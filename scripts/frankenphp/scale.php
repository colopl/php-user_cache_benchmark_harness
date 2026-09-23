<?php
/* A fixed working set; setup and per-worker warmup precede measured HTTP. */
$scaleCache = UserCache\Cache::getPool('scale');
$scaleKeys = array_map(fn($i) => 'scale-key-' . $i, range(0, 31));
$scaleText = str_repeat('w', 300);
$scalePacked = range(0, 7);

function scale_request(): void {
    global $scaleCache, $scaleKeys, $scaleText, $scalePacked;
    $backend = $_GET['backend'] ?? 'user_cache';
    $action = $_GET['action'] ?? 'run';
    $fetch = $backend === 'reference'
        ? static fn($key) => uc_bench_reference_fetch($key)
        : static fn($key) => $scaleCache->fetch($key);
    $store = $backend === 'reference'
        ? static fn($key, $value) => uc_bench_reference_store($key, $value, 3600)
        : static fn($key, $value) => $scaleCache->store($key, $value, 3600);
    $result = ['thread' => uc_bench_thread_id()];
    if ($action === 'setup') {
        if (!$scaleCache->clear()) throw new RuntimeException('scale clear failed');
        uc_bench_reference_clear();
        foreach ($scaleKeys as $key) {
            if (!$store($key, ['version' => 0, 'text' => $scaleText, 'packed' => $scalePacked])) {
                throw new RuntimeException('scale setup failed');
            }
        }
        $result['ready'] = true;
    } elseif ($action === 'warm') {
        foreach ($scaleKeys as $key) {
            $value = $fetch($key);
            if (!is_array($value) || $value['text'] !== $scaleText) throw new RuntimeException('scale warmup failed');
        }
        // Outside measurement: allow the driver to reach every PHP worker.
        usleep(1000);
        $result['ready'] = true;
    } elseif ($action === 'status') {
        $status = UserCache\Cache::getStatus();
        $result['pins'] = $status->getGraphPinnedReferences();
        $result['shared_used'] = $status->getUsedMemory();
        $result['reference_persistent_bytes'] = uc_bench_reference_persistent_bytes();
        $result['php_heap'] = memory_get_usage();
    } else {
        $count = max(1, min(100, (int) ($_GET['operations'] ?? 100)));
        $sequence = (int) ($_GET['sequence'] ?? 0);
        $mixed = ($_GET['mixed'] ?? '0') === '1';
        $reads = $writes = $failures = $checksum = 0;
        $cpuBefore = uc_bench_thread_cpu_ns();
        $start = hrtime(true);
        for ($i = 0; $i < $count; ++$i) {
            $serial = $sequence * $count + $i;
            if ($mixed && $serial % 100 === 0) {
                $key = $scaleKeys[intdiv($serial, 100) % 32];
                if (!$store($key, ['version' => $serial + 1, 'text' => $scaleText, 'packed' => $scalePacked])) ++$failures;
                ++$writes;
            } else {
                $value = $fetch($scaleKeys[$serial % 32]);
                if (!is_array($value) || !is_int($value['version']) || $value['text'] !== $scaleText || $value['packed'] !== $scalePacked) {
                    ++$failures;
                } else {
                    $checksum += strlen($value['text']) + $value['packed'][7];
                }
                unset($value);
                ++$reads;
            }
        }
        $result += ['batch_ns' => hrtime(true) - $start, 'thread_cpu_ns' => uc_bench_thread_cpu_ns() - $cpuBefore,
            'reads' => $reads, 'writes' => $writes, 'failures' => $failures, 'checksum' => $checksum];
    }
    header('Content-Type: application/json');
    echo json_encode($result, JSON_THROW_ON_ERROR);
}

while (frankenphp_handle_request(scale_request(...))) {}
