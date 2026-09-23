<?php
/* Run before/after builds sequentially on the same CPU. Setup is not timed. */
use UserCache\Cache;

$kind = $argv[1] ?? 'scalar';
$keyCount = (int) ($argv[2] ?? 1);
$iterations = (int) ($argv[3] ?? 1000000);
if (!in_array($kind, ['scalar', 'string', 'array'], true) ||
    $keyCount < 1 || ($keyCount & ($keyCount - 1)) !== 0 || $iterations < 1) {
    throw new InvalidArgumentException('Usage: lru.php scalar|string|array power_of_two_keys iterations');
}

$cache = Cache::getPool('lru-bench');
$cache->clear();
$keys = [];
for ($i = 0; $i < $keyCount; $i++) {
    $key = md5("lru-key-$i");
    $value = match ($kind) {
        'scalar' => $i + 1,
        'string' => str_repeat('v', 128),
        'array' => range(1, 8),
    };
    if (!$cache->store($key, $value)) throw new RuntimeException('Seed failed');
    $keys[] = $key;
}
$mask = $keyCount - 1;
for ($i = 0, $warm = max(4096, $keyCount * 128); $i < $warm; $i++) {
    $cache->fetch($keys[$i & $mask]);
}
$beforeMemory = memory_get_usage();

/* GDB's clock probe enables its counter only between these two markers. */
usleep(1);
$start = hrtime(true);
$checksum = 0;
for ($i = 0; $i < $iterations; $i++) {
    $value = $cache->fetch($keys[$i & $mask]);
    $checksum += match ($kind) {
        'scalar' => $value,
        'string' => strlen($value),
        'array' => count($value),
    };
}
$elapsed = hrtime(true) - $start;
usleep(2);
$result = [
    'case' => "lru_{$kind}_{$keyCount}",
    'keys' => $keyCount,
    'iterations' => $iterations,
    'elapsed_ns' => $elapsed,
    'ns_per_operation' => $elapsed / $iterations,
    'checksum' => $checksum,
    'retained_php_bytes' => memory_get_usage() - $beforeMemory,
];

// Validate after capturing memory, preserving the original timed read loop.
$remainder = $iterations % $keyCount;
$expectedChecksum = match ($kind) {
    'scalar' => intdiv($iterations, $keyCount) * intdiv($keyCount * ($keyCount + 1), 2) +
        intdiv($remainder * ($remainder + 1), 2),
    'string' => $iterations * 128,
    'array' => $iterations * 8,
};
if ($checksum !== $expectedChecksum) throw new RuntimeException('LRU checksum mismatch');
$validationChecks = 1;
for ($i = 0; $i < $keyCount; $i++) {
    $expected = match ($kind) {
        'scalar' => $i + 1,
        'string' => str_repeat('v', 128),
        'array' => range(1, 8),
    };
    if ($cache->fetch($keys[$i]) !== $expected) throw new RuntimeException('LRU value mismatch');
    $validationChecks++;
}

$result['validation_checks'] = $validationChecks;
$result['errors'] = 0;
echo json_encode($result, JSON_THROW_ON_ERROR), "\n";
