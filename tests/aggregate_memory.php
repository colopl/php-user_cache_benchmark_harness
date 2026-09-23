<?php

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/uc-memory-median-' . getmypid();
mkdir($tmp);
try {
    $fields = ['value_shm_bytes', 'backend_shm_baseline_bytes', 'backend_shm_reserved_bytes',
        'store_retained_bytes', 'first_fetch_retained_bytes', 'fetch_retained_bytes', 'request_residual_bytes'];
    foreach ([1 => 10, 2 => 30] as $run => $value) {
        mkdir($tmp . '/run' . $run . '/cli-read', 0777, true);
        $rows = [];
        foreach (['user_cache', 'apcu', 'yac'] as $backend) {
            $row = ['mode' => 'read', 'case' => 'example', 'backend' => $backend,
                'median_us' => $value, 'samples_us' => [$value]];
            foreach ($fields as $field) {
                $row[$field] = $backend === 'yac' && in_array($field, ['value_shm_bytes', 'backend_shm_baseline_bytes'], true)
                    ? null : $value;
            }
            $rows[] = $row;
        }
        file_put_contents($tmp . '/run' . $run . '/cli-read/user-cache-benchmark-test.json', json_encode(['read' => $rows], JSON_THROW_ON_ERROR));
    }
    $cmd = [PHP_BINARY, dirname(__DIR__) . '/scripts/aggregate_results_median.php',
        '--output', $tmp . '/median', $tmp . '/run1', $tmp . '/run2'];
    $process = proc_open($cmd, [1 => ['file', $tmp . '/log', 'w'], 2 => ['file', $tmp . '/log', 'a']], $pipes);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('Aggregation failed: ' . file_get_contents($tmp . '/log'));
    }
    $data = json_decode(file_get_contents($tmp . '/median/cli-read/user-cache-benchmark-median.json'), true, 512, JSON_THROW_ON_ERROR);
    foreach ($data['read'] as $row) {
        foreach ($fields as $field) {
            $expected = $row['backend'] === 'yac' && in_array($field, ['value_shm_bytes', 'backend_shm_baseline_bytes'], true) ? null : 20;
            if ($row[$field] !== $expected) {
                throw new RuntimeException('Wrong median for ' . $row['backend'] . '/' . $field);
            }
        }
    }
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($tmp);
}
echo "PASS\n";
