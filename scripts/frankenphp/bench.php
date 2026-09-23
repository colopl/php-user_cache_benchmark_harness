<?php
/* All values are built at run time; OPcache/JIT is disabled by the driver. */
function bench_payload(string $kind): mixed {
    return match ($kind) {
        'null' => null, 'bool' => true, 'int' => 12345, 'double' => 1.2345,
        'string32' => str_repeat('x', 32), 'string300' => str_repeat('x', 300),
        'string4k' => str_repeat('x', 4096), 'string64k' => str_repeat('x', 65536),
        'packed8' => range(0, 7), 'packed512' => range(0, 511),
        'hash32' => array_combine(array_map(fn($i) => 'dynamic-key-' . $i, range(0, 31)), range(0, 31)),
        'nested' => array_map(fn($i) => ['number' => $i, 'text' => str_repeat(chr(65 + $i), 32)], range(0, 7)),
        default => throw new RuntimeException('Unknown payload'),
    };
}

function bench_observe(mixed &$value, string $kind, bool $mutate): int {
    if ($kind === 'nested') {
        if ($mutate) $value[0]['text'][0] = 'z';
        return strlen($value[0]['text']) + $value[7]['number'];
    }
    if (is_array($value)) {
        if ($mutate) $value[array_key_first($value)] = 42;
        return count($value) + (int) $value[array_key_first($value)];
    }
    if (is_string($value)) {
        if ($mutate) $value[0] = 'z';
        return strlen($value) + ord($value[0]);
    }
    return (int) $value;
}

function benchmark(): void {
    $kind = $_GET['payload'] ?? 'packed8';
    $backend = $_GET['backend'] ?? 'user_cache';
    $mode = $_GET['temperature'] ?? 'warm';
    $count = max(1, min(32768, (int) ($_GET['keys'] ?? 1)));
    $loops = max(1, (int) ($_GET['loops'] ?? 10000));
    $mutate = ($_GET['mutate'] ?? '0') === '1';
    $ttl = (int) ($_GET['ttl'] ?? 0);
    // Recreate the pool so prior storage-key tables do not pollute the next backend.
    UserCache\Cache::deletePool('bench');
    $cache = UserCache\Cache::getPool('bench');
    uc_bench_reference_clear();
    memory_reset_peak_usage();
    $heapAfterReset = memory_get_usage();
    $persistentAfterReset = uc_bench_reference_persistent_bytes();
    $payload = bench_payload($kind);
    $flags = uc_bench_value_flags($payload);
    $keys = array_map(fn($i) => 'bench-key-' . $i, range(0, $count - 1));
    if ($backend === 'user_cache') {
        foreach ($keys as $key) if (!$cache->store($key, $payload, $ttl)) throw new RuntimeException('cache seed failed');
        $fetch = static fn($key) => $cache->fetch($key);
    } elseif ($backend === 'reference') {
        foreach ($keys as $key) if (!uc_bench_reference_store($key, $payload, $ttl)) throw new RuntimeException('reference seed failed');
        $fetch = static fn($key) => uc_bench_reference_fetch($key);
    } elseif ($backend === 'raw') {
        uc_bench_reference_prepare_raw($payload);
        $fetch = static fn($key) => uc_bench_reference_read_raw();
    } else {
        throw new RuntimeException('Unknown backend');
    }
    $heapAfterSeed = memory_get_usage();
    $persistentAfterSeed = uc_bench_reference_persistent_bytes();
    if ($mode === 'warm') {
        foreach ($keys as $key) {
            $value = $fetch($key);
            if ($value !== $payload) throw new RuntimeException('warmup mismatch');
            unset($value);
        }
    } elseif ($mode === 'cold') {
        // A first pass over previously unread keys; no clearing inside timing.
        $loops = $count;
    } else {
        throw new RuntimeException('Unknown temperature');
    }
    $heapBefore = memory_get_usage();
    $cpuBefore = uc_bench_thread_cpu_ns();
    $checksum = 0;
    $start = hrtime(true);
    for ($i = 0; $i < $loops; ++$i) {
        $value = $fetch($keys[$i % $count]);
        $checksum += bench_observe($value, $kind, $mutate);
        unset($value);
    }
    $elapsed = hrtime(true) - $start;
    $cpuAfter = uc_bench_thread_cpu_ns();
    $observation = $payload;
    $expected = bench_observe($observation, $kind, $mutate) * $loops;
    unset($observation);
    if ($checksum !== $expected) throw new RuntimeException('checksum mismatch');
    if ($fetch($keys[0]) !== $payload) throw new RuntimeException('mutation escaped into cache');
    $status = UserCache\Cache::getStatus();
    $result = [
        'backend' => $backend, 'payload' => $kind, 'keys' => $count,
        'temperature' => $mode, 'mutate' => $mutate, 'ttl' => $ttl,
        'operations' => $loops, 'elapsed_ns' => $elapsed, 'ns_per_operation' => $elapsed / $loops,
        'thread_cpu_ns' => $cpuAfter - $cpuBefore,
        'checksum' => $checksum, 'expected_checksum' => $expected,
        'mutation_isolated' => true, 'flags' => $flags,
        'php_heap_after_reset' => $heapAfterReset, 'php_heap_after_seed' => $heapAfterSeed,
        'reference_persistent_after_reset' => $persistentAfterReset,
        'reference_persistent_after_seed' => $persistentAfterSeed,
        'php_heap_after_warm' => $heapBefore, 'php_heap_before' => $heapBefore, 'php_heap_after' => memory_get_usage(),
        'php_peak_heap' => memory_get_peak_usage(),
        'graph_pins' => $status->getGraphPinnedReferences(),
        'shared_used' => $status->getUsedMemory(),
    ];
    header('Content-Type: application/json');
    echo json_encode($result, JSON_THROW_ON_ERROR);
}
