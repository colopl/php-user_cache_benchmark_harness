<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/UserCacheBenchmark.php';

$backend = new UcBenchYacBackend();
if (!$backend->available()) {
    echo 'SKIP: ' . $backend->unavailableReason() . "\n";
    exit(0);
}

$backend->clear();
foreach ([false, null, 123, str_repeat('large-', 20000), ['nested' => ['value' => 42]]] as $value) {
    $backend->store('test', $value);
    if ($backend->fetch('test') !== $value) {
        throw new RuntimeException('Yac did not round-trip the value');
    }
}
$backend->clear();
try {
    $backend->fetch('test');
    throw new LogicException('A cache miss was not detected');
} catch (RuntimeException $exception) {
    if (!str_contains($exception->getMessage(), 'missed key')) {
        throw $exception;
    }
}

$options = uc_bench_parse_options(['benchmark', '--no-isolate', '--backends', 'yac',
    '--cases', 'constant_array,large_string,cycle_assignment_object', '--iterations', '1', '--warmup', '0',
    '--read-operations', '2', '--write-operations', '2', '--key-space', '2']);
$result = (new UcBenchRunner($options))->run();
if ($result['failures'] !== [] || count($result['read']) !== 3 || count($result['write']) !== 3) {
    throw new RuntimeException('Yac workload or bounded key failed: ' . json_encode($result['failures']));
}
foreach ($result['read'] as $row) {
    if ($row['value_shm_bytes'] !== null || $row['backend_shm_baseline_bytes'] !== null
        || $row['backend_shm_reserved_bytes'] <= 0 || !is_int($row['fetch_retained_bytes'])) {
        throw new RuntimeException('Incorrect Yac memory accounting');
    }
}
$backend->clear();
echo "PASS\n";
