<?php
// Keep setup and key-cache allocations outside the retained snapshot measurement.
use UserCache\Cache;

$count = (int) ($argv[1] ?? 4096);
if ($count < 0) throw new InvalidArgumentException('Key count must be non-negative');
$pool = Cache::getPool('snapshot-memory');
if (!$pool->clear()) throw new RuntimeException('Initial clear failed');
for ($i = 0; $i < $count; $i++) {
    if (!$pool->store('key-' . $i, $i)) throw new RuntimeException('Store failed');
}
$before = memory_get_usage();
$status = $pool->getPoolStatus();
if ($status->getEntryCount() !== $count) throw new RuntimeException('Status mismatch');
$withStatus = memory_get_usage();
unset($status);
$afterStatus = memory_get_usage();
if (!$pool->clear()) throw new RuntimeException('Final clear failed');
$afterClear = memory_get_usage();
unset($pool);
if (!Cache::deletePool('snapshot-memory')) throw new RuntimeException('Pool release failed');
$afterDelete = memory_get_usage();
echo json_encode([
    'keys' => $count,
    'before_status_bytes' => $before,
    'with_status_bytes' => $withStatus,
    'after_status_release_bytes' => $afterStatus,
    'after_clear_bytes' => $afterClear,
    'after_pool_release_bytes' => $afterDelete,
    'snapshot_retained_bytes' => $afterStatus - $before,
], JSON_THROW_ON_ERROR), "\n";
