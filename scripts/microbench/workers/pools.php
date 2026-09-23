<?php
/**
 * Controls for the fixed-size memory_pools workload. Run each sample in a fresh
 * process, alternating before/after binaries on the same CPU, with the same INIs:
 * -n -d user_cache.enable_cli=1 -d user_cache.shm_size=128M -d memory_limit=512M
 *
 * cold: original clear + seed, then time new pools and missing reads.
 * warm: create/read the same pools, delete them all, then time their recreation.
 * warm-create: create/delete the same pools without their missing reads.
 * end-to-end: cold, reporting clear + seed + measured loop as the primary time.
 * create-only: time new pools without missing reads.
 * existing-miss: create pools first, then time getPool + the first missing read.
 * warm-existing: create/read pools first, then time existing pools and warm reads.
 *
 * Warmup also warms PHP allocations and retains the pool registry's capacity.
 * deletePool itself clears a shared prefix; its cost differs between builds.
 * A warm/cold difference alone does not identify SHM cache warmth as the cause.
 */
use UserCache\Cache;

$scenario = $argv[1] ?? 'cold';
$poolCount = (int) ($argv[2] ?? 2000);
if (!in_array($scenario, [
    'cold', 'warm', 'warm-create', 'end-to-end', 'create-only',
    'existing-miss', 'warm-existing',
], true) || $poolCount < 1) {
    throw new InvalidArgumentException(
        'Usage: pools.php cold|warm|warm-create|end-to-end|create-only|existing-miss|warm-existing [pools=2000]'
    );
}

$cache = Cache::getPool('microbench');
if (Cache::getStatus()->getAvailability() !== UserCache\CacheAvailability::Available) {
    throw new RuntimeException('CLI user_cache must be enabled');
}

$setupMemory = memory_get_usage();
$setupStart = hrtime(true);
if (!$cache->clear()) throw new RuntimeException('Initial clear failed');
$afterClear = hrtime(true);
if (!$cache->storeMultiple(['k0' => 42])) throw new RuntimeException('Initial seed failed');
$afterSeed = hrtime(true);

$warmStart = hrtime(true);
if ($scenario === 'warm' || $scenario === 'warm-create' ||
    $scenario === 'existing-miss' || $scenario === 'warm-existing') {
    if ($scenario === 'warm' || $scenario === 'warm-existing') {
        for ($i = 0; $i < $poolCount; $i++) Cache::getPool("pool-$i")->fetch('missing');
    } else {
        for ($i = 0; $i < $poolCount; $i++) Cache::getPool("pool-$i");
    }
    if ($scenario === 'warm' || $scenario === 'warm-create') {
        for ($i = 0; $i < $poolCount; $i++) {
            if (!Cache::deletePool("pool-$i")) throw new RuntimeException('Warmup deletion failed');
        }
    }
}
$warmupNs = hrtime(true) - $warmStart;

$memoryBefore = memory_get_usage();
$peakBefore = memory_get_peak_usage();
$loopStart = hrtime(true);
if ($scenario === 'create-only') {
    for ($i = 0; $i < $poolCount; $i++) Cache::getPool("pool-$i");
} else {
    // Keep the original memory_pools expression and pool names unchanged.
    for ($i = 0; $i < $poolCount; $i++) Cache::getPool("pool-$i")->fetch('missing');
}
$loopEnd = hrtime(true);
$loopNs = $loopEnd - $loopStart;
$memoryAfter = memory_get_usage();
$peakAfter = memory_get_peak_usage();

// Validation and status allocation are excluded from timing and PHP retention.
$status = Cache::getStatus();
if ($status->getEntryCount() !== 1 || $cache->fetch('k0') !== 42 ||
    count(Cache::getPools()) !== $poolCount + 1 ||
    Cache::getPool('pool-0')->fetch('missing') !== null ||
    Cache::getPool('pool-' . ($poolCount - 1))->fetch('missing') !== null) {
    throw new RuntimeException('Pool workload changed cache contents or pool count');
}

$elapsed = $scenario === 'end-to-end' ? $loopEnd - $setupStart : $loopNs;
echo json_encode([
    'case' => 'memory_pools_' . $scenario,
    'scenario' => $scenario,
    'iterations' => $poolCount,
    'elapsed_ns' => $elapsed,
    'ns_per_operation' => $elapsed / $poolCount,
    'pool_loop_ns' => $loopNs,
    'pool_loop_ns_per_operation' => $loopNs / $poolCount,
    'clear_ns' => $afterClear - $setupStart,
    'seed_ns' => $afterSeed - $afterClear,
    'warmup_ns' => $warmupNs,
    'setup_and_pool_ns' => $loopEnd - $setupStart,
    'retained_php_bytes' => $memoryAfter - $memoryBefore,
    'retained_from_setup_php_bytes' => $memoryAfter - $setupMemory,
    'peak_php_bytes_before_loop' => $peakBefore,
    'peak_php_bytes' => $peakAfter,
    'shared_used_bytes' => $status->getUsedMemory(),
    'entries' => $status->getEntryCount(),
    'entry_capacity' => $status->getEntryCapacity(),
    'errors' => 0,
], JSON_THROW_ON_ERROR), "\n";
