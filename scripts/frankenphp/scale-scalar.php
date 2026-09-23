<?php
/* Uses the existing FrankenPHP scale.go driver with non-expiring integers. */
$cache = UserCache\Cache::getPool('scalar-http');
$keys = array_map(fn($i) => 'key-' . $i, range(0, 31));

function scalar_request(): void {
    global $cache, $keys;
    $backend = $_GET['backend'] ?? 'user_cache';
    $action = $_GET['action'] ?? 'run';
    $fetch = $backend === 'reference'
        ? static fn($key) => uc_bench_reference_fetch($key)
        : static fn($key) => $cache->fetch($key);
    $store = $backend === 'reference'
        ? static fn($key, $value) => uc_bench_reference_store($key, $value, 0)
        : static fn($key, $value) => $cache->store($key, $value);
    $result = ['thread' => uc_bench_thread_id()];
    if ($action === 'setup') {
        if (!$cache->clear()) throw new RuntimeException('clear failed');
        uc_bench_reference_clear();
        foreach ($keys as $key) {
            if (!$store($key, 0)) throw new RuntimeException('seed failed');
        }
        $result['ready'] = true;
    } elseif ($action === 'warm') {
        foreach ($keys as $key) {
            if (!is_int($fetch($key))) throw new RuntimeException('warmup failed');
        }
        usleep(1000);
        $result['ready'] = true;
    } elseif ($action === 'status') {
        $status = UserCache\Cache::getStatus();
        $result += ['pins' => $status->getGraphPinnedReferences(),
            'shared_used' => $status->getUsedMemory(), 'php_heap' => memory_get_usage(),
            'reference_persistent_bytes' => uc_bench_reference_persistent_bytes()];
    } else {
        $count = max(1, min(100, (int) ($_GET['operations'] ?? 100)));
        $sequence = (int) ($_GET['sequence'] ?? 0);
        $mixed = ($_GET['mixed'] ?? '0') === '1';
        $reads = $writes = $failures = $checksum = 0;
        $cpu = uc_bench_thread_cpu_ns();
        $start = hrtime(true);
        for ($i = 0; $i < $count; $i++) {
            $serial = $sequence * $count + $i;
            if ($mixed && $serial % 100 === 0) {
                if (!$store($keys[intdiv($serial, 100) % 32], $serial + 1)) $failures++;
                $writes++;
            } else {
                $value = $fetch($keys[$serial % 32]);
                if (!is_int($value) || $value < 0) $failures++;
                else $checksum += 307; // The driver's expected per-read checksum.
                $reads++;
            }
        }
        $result += ['batch_ns' => hrtime(true) - $start, 'thread_cpu_ns' => uc_bench_thread_cpu_ns() - $cpu,
            'reads' => $reads, 'writes' => $writes, 'failures' => $failures, 'checksum' => $checksum];
    }
    header('Content-Type: application/json');
    echo json_encode($result, JSON_THROW_ON_ERROR);
}

while (frankenphp_handle_request(scalar_request(...))) {}
