<?php
/** Pool-status costs. Run each scenario in a fresh CLI process. */
use UserCache\Cache;

$scenario = $argv[1] ?? 'warm';
$reportedScenario = $scenario;
$churn = $scenario === 'same-churn' || $scenario === 'other-churn';
$replace = $churn || $scenario === 'same-replace' || $scenario === 'other-replace';
if ($replace) {
    $scenario = str_replace(['-replace', '-churn'], '-write', $scenario);
}
$iterations = (int) ($argv[2] ?? ($scenario === 'first' ? 1 : 1000));
$poolKeys = (int) ($argv[3] ?? 128);
$otherKeys = (int) ($argv[4] ?? 16384);
if (!in_array($scenario, ['first', 'warm', 'same-write', 'other-write'], true) ||
    $iterations < 1 || $poolKeys < 0 || $otherKeys < 0 ||
    ($scenario === 'first' && $iterations !== 1)) {
    throw new InvalidArgumentException('scenario iterations poolKeys otherKeys');
}
$cache = Cache::getPool('status-target');
$other = Cache::getPool('status-other');
$cache->clear();
$other->clear();
for ($i = 0; $i < $poolKeys; $i++) {
    if (!$cache->store("target:$i", $i)) {
        throw new RuntimeException('target setup failed');
    }
}
for ($i = 0; $i < $otherKeys; $i++) {
    if (!$other->store("other:$i", $i)) {
        throw new RuntimeException('other setup failed');
    }
}
// Keep entry counts constant; replacement scenarios vary allocated payload size.
if ($scenario === 'same-write' && $poolKeys === 0 ||
    $scenario === 'other-write' && $otherKeys === 0) {
    throw new InvalidArgumentException('write scenario requires an existing key');
}
$replacements = [str_repeat('a', 64), str_repeat('b', 96)];
if ($replace) {
    ($scenario === 'same-write' ? $cache : $other)->store(
        $scenario === 'same-write' ? 'target:0' : 'other:0', $replacements[0]);
}
if ($scenario !== 'first') {
    if ($cache->getPoolStatus()->getEntryCount() !== $poolKeys) {
        throw new RuntimeException('unexpected target count');
    }
}
$memoryBefore = memory_get_usage();
$sum = 0;
$start = hrtime(true);
for ($i = 0; $i < $iterations; $i++) {
    if ($scenario === 'same-write') {
        if ($churn) {
            $cache->delete('target:0');
        }
        $cache->store('target:0', $replace ? $replacements[$i & 1] : $i);
    } elseif ($scenario === 'other-write') {
        if ($churn) {
            $other->delete('other:0');
        }
        $other->store('other:0', $replace ? $replacements[$i & 1] : $i);
    }
    $status = $cache->getPoolStatus();
    $sum += $status->getEntryCount();
}
$elapsed = hrtime(true) - $start;
if ($sum !== $iterations * $poolKeys || count($status->getEntryKeys()) !== $poolKeys) {
    throw new RuntimeException('status checksum mismatch');
}
$result = [
    'scenario' => $reportedScenario,
    'iterations' => $iterations,
    'pool_keys' => $poolKeys,
    'other_keys' => $otherKeys,
    'elapsed_ns' => $elapsed,
    'ns_per_iteration' => $elapsed / $iterations,
    'retained_bytes' => memory_get_usage() - $memoryBefore,
    'checksum' => $sum,
];
// Check final values and both pools after capturing the original memory metric.
$validationChecks = 2;
if ($cache->getPoolStatus()->getEntryCount() !== $poolKeys ||
    $other->getPoolStatus()->getEntryCount() !== $otherKeys ||
    Cache::getStatus()->getStoreFailureCount() !== 0) {
    throw new RuntimeException('final pool state mismatch');
}
$validationChecks += 3;
foreach ([[$cache, 'target', $poolKeys, $scenario === 'same-write'],
          [$other, 'other', $otherKeys, $scenario === 'other-write']] as [$pool, $prefix, $count, $changed]) {
    for ($i = 0; $i < $count; $i++) {
        $expected = $i === 0 && $changed ?
            ($replace ? $replacements[($iterations - 1) & 1] : $iterations - 1) : $i;
        if ($pool->fetch("$prefix:$i") !== $expected) throw new RuntimeException('final pool value mismatch');
        $validationChecks++;
    }
}
$result['validation_checks'] = $validationChecks;
$result['errors'] = 0;
echo json_encode($result, JSON_THROW_ON_ERROR), "\n";
