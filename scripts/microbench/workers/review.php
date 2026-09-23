<?php
/**
 * Run with the freshly built CLI, for example:
 * php -n -d user_cache.enable_cli=1 -d user_cache.shm_size=64M review.php
 * Re-run the same script against the baseline build for comparison.
 */
use UserCache\Cache;

$quick = ($argv[1] ?? '') === '--quick';
$storeIterations = $quick ? 2000 : 200000;
$lockIterations = $quick ? 200 : 20000;
$deleteIterations = $quick ? 1000 : 10000;
$bulkCount = 2000;
$cache = Cache::getPool('review-benchmark');
if (Cache::getStatus()->getAvailability() !== UserCache\CacheAvailability::Available) {
    throw new RuntimeException('Enable user_cache for the CLI before running this benchmark.');
}

function measureStores(Cache $cache, int $iterations): array
{
    $samples = [];
    for ($sample = 0; $sample < 3; $sample++) {
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            if (!$cache->store('hot', $i)) {
                throw new RuntimeException('Unexpected store failure.');
            }
        }
        $samples[] = hrtime(true) - $start;
    }
    return $samples;
}

$cache->store('hot', 0);
$before = measureStores($cache, $storeIterations);
$lockStart = hrtime(true);
for ($i = 0; $i < $lockIterations; $i++) {
    $key = md5((string) $i);
    if (!$cache->lock($key) || !$cache->unlock($key)) {
        throw new RuntimeException('Unexpected lock failure.');
    }
}
$lockNs = hrtime(true) - $lockStart;
$after = measureStores($cache, $storeIterations);
$cache->clear();

$memoryBefore = memory_get_usage();
$deleteStart = hrtime(true);
for ($i = 0; $i < $deleteIterations; $i++) {
    if (!$cache->store("key-$i", str_pad((string) $i, 300, 'x')) || !$cache->delete("key-$i")) {
        throw new RuntimeException('Unexpected store/delete failure.');
    }
}
$deleteNs = hrtime(true) - $deleteStart;
gc_collect_cycles();
$memoryAfter = memory_get_usage();

$values = [];
for ($i = 0; $i < $bulkCount; $i++) {
    $values["bulk-$i"] = $i;
}
$start = hrtime(true);
$bulkResult = $cache->storeMultiple($values);
$bulkNs = hrtime(true) - $start;
if (!$bulkResult || $cache->fetchMultiple(array_keys($values)) !== $values) {
    throw new RuntimeException('Bulk result mismatch');
}
$median = static function (array $samples): int {
    sort($samples);
    return $samples[1];
};

echo json_encode([
    'case' => 'review_pipeline',
    'php' => PHP_VERSION,
    'quick' => $quick,
    'store_iterations_per_sample' => $storeIterations,
    'lock_churn_iterations' => $lockIterations,
    'store_delete_iterations' => $deleteIterations,
    'bulk_entries' => $bulkCount,
    'store_ns_before_lock_churn' => $before,
    'store_ns_after_lock_churn' => $after,
    'store_before_ns_per_operation' => $median($before) / $storeIterations,
    'store_after_ns_per_operation' => $median($after) / $storeIterations,
    'lock_churn_ns_per_operation' => $lockNs / $lockIterations,
    'store_delete_ns_per_operation' => $deleteNs / $deleteIterations,
    'retained_php_bytes' => $memoryAfter - $memoryBefore,
    'bulk_result' => $bulkResult,
    'bulk_elapsed_ns' => $bulkNs,
    'bulk_ns_per_entry' => $bulkNs / $bulkCount,
    'errors' => 0,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
