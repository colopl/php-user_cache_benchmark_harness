<?php
/* Usage: writers.php mode workers iterations [disjoint|same] [keys_per_worker]
 * Modes: scalar, increment, insert, string, array, mixed.
 * Fork, key generation, initialization and final verification are not timed. */
use UserCache\Cache;

$mode = $argv[1] ?? 'increment';
$workers = (int) ($argv[2] ?? 1);
$iterations = (int) ($argv[3] ?? ($mode === 'insert' ? 2000 : 200000));
$layout = $argv[4] ?? 'disjoint';
$keyCount = (int) ($argv[5] ?? 1);
if (!function_exists('pcntl_fork') || !function_exists('stream_socket_pair')) {
    fwrite(STDERR, "pcntl and stream_socket_pair are required\n");
    exit(77);
}
if (!in_array($mode, ['scalar', 'increment', 'insert', 'string', 'array', 'mixed'], true) ||
    !in_array($workers, [1, 2, 4, 8], true) || $iterations < 1 || $keyCount < 1 ||
    !in_array($layout, ['disjoint', 'same'], true) ||
    ($mode === 'insert' && $layout !== 'disjoint')) {
    throw new InvalidArgumentException('Invalid benchmark arguments; insert requires disjoint keys');
}

const WRITER_POOL = 'contention-writers';

/* The candidate uses 8 stripes: (Zend hash ^ (Zend hash >> 16)) & 7.
 * Only the low 19 DJB2 bits are needed, avoiding PHP integer overflow. */
function writerStripe(string $key): int {
    $bytes = "user_cache\x1f" . WRITER_POOL . "\x1f" . $key;
    $hash = 5381;
    for ($i = 0, $length = strlen($bytes); $i < $length; $i++) {
        $hash = ($hash * 33 + ord($bytes[$i])) & 0x7ffff;
    }
    return ($hash ^ ($hash >> 16)) & 7;
}

function writerKey(int $worker, int $index, int $stride): string {
    $target = ($worker + $index * $stride) & 7;
    for ($nonce = 0; ; $nonce++) {
        $key = md5("worker-$worker-key-$index-nonce-$nonce");
        if (writerStripe($key) === $target) return $key;
    }
}

function writeMessage($stream, string $message): void {
    $offset = 0;
    while ($offset < strlen($message)) {
        $written = fwrite($stream, substr($message, $offset));
        if ($written === false || $written === 0) throw new RuntimeException('Barrier write failed');
        $offset += $written;
    }
}

function writerWork(Cache $cache, array $keys, string $mode, int $iterations, array $payloads): array {
    $errors = 0;
    $checksum = 0;
    $keyCount = count($keys);
    $batchLatencies = [];
    $started = hrtime(true);
    for ($base = 0; $base < $iterations; $base += 256) {
        $end = min($base + 256, $iterations);
        $batchStarted = hrtime(true);
        for ($i = $base; $i < $end; $i++) {
            $key = $keys[$i % $keyCount];
            switch ($mode) {
                case 'increment':
                    $value = $cache->increment($key);
                    if ($value === null) $errors++;
                    else $checksum ^= $value;
                    break;
                case 'mixed':
                    if ($i % 100 === 0) {
                        $value = $cache->increment($keys[intdiv($i, 100) % $keyCount]);
                        if ($value === null) $errors++;
                    } else {
                        $value = $cache->fetch($key);
                        if (!is_int($value) || $value < 0) $errors++;
                    }
                    if (is_int($value)) $checksum ^= $value;
                    break;
                case 'string':
                case 'array':
                    if (!$cache->store($key, $payloads[$i & 1])) $errors++;
                    break;
                default:
                    if (!$cache->store($key, $i + 1)) $errors++;
                    break;
            }
        }
        $batchLatencies[] = (hrtime(true) - $batchStarted) / ($end - $base);
    }
    $finished = hrtime(true);
    return ['started_ns' => $started, 'finished_ns' => $finished,
        'elapsed_ns' => $finished - $started, 'errors' => $errors, 'checksum' => $checksum,
        'batch_latencies_ns_per_operation' => $batchLatencies];
}

$cache = Cache::getPool(WRITER_POOL);
$cache->clear();
$status = Cache::getStatus();
if ($status->getAvailability() !== UserCache\CacheAvailability::Available) {
    throw new RuntimeException('UserCache must be available before fork');
}
$keysPerWorker = $mode === 'insert' ? $iterations : $keyCount;
$distinctKeys = $keysPerWorker * ($layout === 'same' ? 1 : $workers);
if ($distinctKeys > intdiv($status->getEntryCapacity() * 3, 4)) {
    throw new RuntimeException('Too many keys for this entry capacity; reduce iterations/keys or increase shm_size and entries_hint');
}
$payloads = match ($mode) {
    'string' => [str_repeat('a', 300), str_repeat('b', 300)],
    'array' => [range(1, 8), range(11, 18)],
    default => [],
};
$allKeys = [];
$stripes = [];
for ($worker = 0; $worker < $workers; $worker++) {
    if ($layout === 'same' && $worker !== 0) {
        $allKeys[$worker] = $allKeys[0];
        $stripes[$worker] = $stripes[0];
        continue;
    }
    $keys = [];
    $distribution = array_fill(0, 8, 0);
    for ($index = 0; $index < $keysPerWorker; $index++) {
        $key = writerKey($worker, $index, $layout === 'same' ? 1 : $workers);
        $keys[] = $key;
        $distribution[writerStripe($key)]++;
        if ($mode !== 'insert' && !$cache->store($key, $payloads[0] ?? 0)) {
            throw new RuntimeException('Parent seed failed');
        }
    }
    $allKeys[$worker] = $keys;
    $stripes[$worker] = $distribution;
}

$pairs = [];
for ($worker = 0; $worker < $workers; $worker++) {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    if ($pair === false) throw new RuntimeException('Socket pair failed');
    stream_set_timeout($pair[0], 120);
    stream_set_timeout($pair[1], 120);
    $pairs[] = $pair;
}
$children = [];
for ($worker = 0; $worker < $workers; $worker++) {
    $pid = pcntl_fork();
    if ($pid < 0) throw new RuntimeException('Fork failed');
    if ($pid === 0) {
        foreach ($pairs as $index => $pair) {
            fclose($pair[0]);
            if ($index !== $worker) fclose($pair[1]);
        }
        $peer = $pairs[$worker][1];
        try {
            /* Resolve the forked runtime and prefix cache before the barrier. */
            $cache->has('__writer_runtime_warmup__');
            if ($mode !== 'insert') {
                foreach ($allKeys[$worker] as $key) {
                    if (!$cache->has($key)) throw new RuntimeException('Child warmup miss');
                }
            }
            writeMessage($peer, "ready\n");
            if (fgets($peer) !== "go\n") throw new RuntimeException('Start barrier failed');
            $row = writerWork($cache, $allKeys[$worker], $mode, $iterations, $payloads);
            writeMessage($peer, json_encode($row, JSON_THROW_ON_ERROR) . "\n");
            fclose($peer);
            exit($row['errors'] === 0 ? 0 : 1);
        } catch (Throwable $exception) {
            writeMessage($peer, json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR) . "\n");
            fclose($peer);
            exit(1);
        }
    }
    $children[] = $pid;
}
foreach ($pairs as $pair) fclose($pair[1]);
foreach ($pairs as $pair) {
    if (fgets($pair[0]) !== "ready\n") throw new RuntimeException('Ready barrier failed');
}
$started = hrtime(true);
foreach ($pairs as $pair) writeMessage($pair[0], "go\n");
$rows = [];
foreach ($pairs as $pair) {
    $line = fgets($pair[0]);
    if ($line === false) throw new RuntimeException('Worker result missing');
    $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
    if (isset($row['error'])) throw new RuntimeException($row['error']);
    $rows[] = $row;
    fclose($pair[0]);
}
$finished = max(array_column($rows, 'finished_ns'));
$errors = array_sum(array_column($rows, 'errors'));
$failures = [];
foreach ($children as $pid) {
    if (pcntl_waitpid($pid, $childStatus) !== $pid ||
        !pcntl_wifexited($childStatus) || pcntl_wexitstatus($childStatus) !== 0) {
        $errors++;
        $failures[] = 'child exit';
    }
}

/* Validate after every worker has finished; no verification enters the timer. */
foreach ($allKeys as $worker => $keys) {
    if ($layout === 'same' && $worker !== 0) continue;
    foreach ($keys as $index => $key) {
        $calls = $iterations > $index ? intdiv($iterations - 1 - $index, count($keys)) + 1 : 0;
        $last = $calls === 0 ? 0 : $index + ($calls - 1) * count($keys) + 1;
        $multiplier = $layout === 'same' ? $workers : 1;
        $expected = match ($mode) {
            'increment' => $calls * $multiplier,
            'mixed' => max(0, intdiv(intdiv($iterations - 1, 100) + count($keys) - $index, count($keys))) * $multiplier,
            'string', 'array' => $last === 0 ? $payloads[0] : $payloads[($last - 1) & 1],
            default => $last,
        };
        if ($cache->fetch($key) !== $expected) {
            $errors++;
            if (count($failures) < 8) $failures[] = "final value: worker=$worker key=$index";
        }
    }
}
$final = Cache::getStatus();
if ($final->getEntryCount() !== $distinctKeys ||
    $final->getEvictionCount() !== $status->getEvictionCount() ||
    $final->getExpungeCount() !== $status->getExpungeCount()) {
    $errors++;
    $failures[] = 'entry count or eviction changed';
}
$latencies = [];
$checksum = 0;
foreach ($rows as &$row) {
    $checksum ^= $row['checksum'];
    foreach ($row['batch_latencies_ns_per_operation'] as $latency) $latencies[] = $latency;
    unset($row['batch_latencies_ns_per_operation']);
}
unset($row);
sort($latencies, SORT_NUMERIC);
$percentiles = [];
foreach (['p50' => 0.50, 'p95' => 0.95, 'p99' => 0.99] as $name => $fraction) {
    $percentiles[$name] = $latencies[max(0, (int) ceil(count($latencies) * $fraction) - 1)];
}
$elapsed = $finished - $started;
$operations = $workers * $iterations;
echo json_encode([
    'case' => "writers_{$mode}_{$layout}_{$workers}", 'mode' => $mode,
    'workers' => $workers, 'iterations_per_worker' => $iterations,
    'key_layout' => $layout, 'keys_per_worker' => $keysPerWorker,
    'stripe_counts' => $stripes, 'operations' => $operations,
    'elapsed_ns' => $elapsed, 'ns_per_operation' => $elapsed / $operations,
    'throughput_ops_per_second' => $operations * 1e9 / $elapsed,
    'latency_sample_unit' => 'operation mean within a batch of at most 256 operations',
    'latency_ns_per_operation' => $percentiles, 'latency_sample_count' => count($latencies),
    'worker_samples' => $rows, 'checksum' => $checksum,
    'errors' => $errors, 'failure_details' => $failures,
], JSON_THROW_ON_ERROR), "\n";
exit($errors === 0 ? 0 : 1);
