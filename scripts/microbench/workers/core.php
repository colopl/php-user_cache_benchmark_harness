<?php
/** Isolated workload worker for ../run.py; each invocation starts a fresh cache. */
use UserCache\Cache;

final class MicrobenchValue {
    public function __construct(public array $items, public string $name = 'example') {}
}
final class MicrobenchMagicValue {
    public function __construct(public array $items) {}
    public function __serialize(): array { return ['items' => $this->items]; }
    public function __unserialize(array $state): void { $this->items = $state['items']; }
}
final class MicrobenchDestructorValue {
    public function __construct(public array $items) {}
    public function __destruct() {}
}

$case = $argv[1] ?? 'fetch_scalar_1';
$iterations = max(1, (int) ($argv[2] ?? 100000));
$cache = Cache::getPool('microbench');
if (Cache::getStatus()->getAvailability() !== UserCache\CacheAvailability::Available) {
    throw new RuntimeException('CLI user_cache must be enabled.');
}
$cache->clear();
$size = 1;
$value = 42;
if (preg_match('/^fetch_scalar_(\d+)$/', $case, $match)) {
    $size = (int) $match[1];
} elseif (preg_match('/^fetch_string_300_(\d+)$/', $case, $match)) {
    $size = (int) $match[1];
    $value = str_repeat('x', 300);
} else {
    $value = match ($case) {
        'fetch_string_300', 'store_string_300', 'store_delete' => str_repeat('x', 300),
        'fetch_string_8192' => str_repeat('x', 8192),
        'fetch_packed_array' => range(0, 127),
        'fetch_hash_array' => array_combine(array_map(fn($i) => "field_$i", range(0, 31)), range(0, 31)),
        'fetch_object' => new MicrobenchValue(range(0, 31)),
        'fetch_dynamic_object' => (object) ['items' => range(0, 127)],
        'fetch_destructor_object' => new MicrobenchDestructorValue(range(0, 31)),
        'fetch_magic' => new MicrobenchMagicValue(range(0, 31)),
        'fetch_spl' => new ArrayObject(range(0, 31)),
        default => 42,
    };
    if (str_contains($case, 'multiple_')) {
        $size = (int) substr($case, strrpos($case, '_') + 1);
    }
}
$keys = [];
$values = [];
for ($i = 0; $i < $size; $i++) {
    $key = $case === 'fetch_long_key' ? str_repeat('key', 512) : "k$i";
    $keys[] = $key;
    $values[$key] = $value;
}
if (!$cache->storeMultiple($values)) throw new RuntimeException('Setup failed.');
$checksum = null;

function runWorkload(string $case, Cache $cache, array $keys, array $values, mixed $value, int $n): mixed {
    $mask = count($keys) - 1;
    $key = $keys[0];
    $result = null;
    if ($case === 'store_scalar' || $case === 'store_string_300') {
        for ($i = 0; $i < $n; $i++) $result = $cache->store($key, $value);
    } elseif ($case === 'store_delete') {
        for ($i = 0; $i < $n; $i++) { $cache->store($key, $value); $result = $cache->delete($key); }
    } elseif ($case === 'has_scalar') {
        for ($i = 0; $i < $n; $i++) $result = $cache->has($key);
    } elseif ($case === 'fetch_miss') {
        for ($i = 0; $i < $n; $i++) $result = $cache->fetch('missing');
    } elseif (str_starts_with($case, 'fetch_multiple_')) {
        for ($i = 0; $i < $n; $i++) $result = $cache->fetchMultiple($keys);
    } elseif (str_starts_with($case, 'store_multiple_')) {
        for ($i = 0; $i < $n; $i++) $result = $cache->storeMultiple($values);
    } elseif (str_starts_with($case, 'status_')) {
        for ($i = 0; $i < $n; $i++) $result = Cache::getStatus()->getFreeMemory();
    } elseif ($case === 'pool_status') {
        for ($i = 0; $i < $n; $i++) $result = $cache->getPoolStatus()->getEntryCount();
    } elseif ($case === 'lock_unlock') {
        for ($i = 0; $i < $n; $i++) { $cache->lock($key); $result = $cache->unlock($key); }
    } elseif ($case === 'remember_hit' || $case === 'remember_miss') {
        $callback = static fn() => 42;
        for ($i = 0; $i < $n; $i++) {
            if ($case === 'remember_miss') $cache->delete($key);
            $result = $cache->remember($key, $callback);
        }
    } else {
        for ($i = 0; $i < $n; $i++) $result = $cache->fetch($keys[$i & $mask]);
    }
    return $result;
}

if ($case === 'status_fragmented' || $case === 'pool_status') {
    $cache->clear();
    for ($i = 0; $i < 8192; $i++) {
        if (!$cache->store("fragment-$i", str_repeat('x', 64 + ($i % 12) * 64))) {
            throw new RuntimeException('Fragment setup failed.');
        }
    }
    for ($i = 0; $i < 8192; $i += 2) $cache->delete("fragment-$i");
} elseif ($case === 'status_empty') {
    $cache->clear();
}

$memoryBefore = memory_get_usage();
if (str_starts_with($case, 'memory_')) {
    $start = hrtime(true);
    if ($case === 'memory_pools') {
        for ($i = 0; $i < 2000; $i++) Cache::getPool("pool-$i")->fetch('missing');
        $iterations = 2000;
    } elseif ($case === 'memory_strings') {
        for ($i = 0; $i < 40000; $i++) {
            if (!$cache->store("s$i", str_pad((string) $i, 300, 'x'))) throw new RuntimeException('Store failed.');
        }
        $iterations = 40000;
    } elseif ($case === 'memory_objects') {
        for ($i = 0; $i < 6000; $i++) {
            $object = new MicrobenchValue(array_fill(0, 32, $i), str_pad((string) $i, 768, 'x'));
            if (!$cache->store("o$i", $object)) throw new RuntimeException('Store failed.');
            $cache->fetch("o$i");
            $cache->fetch("o$i");
        }
        unset($object);
        $iterations = 6000;
    } elseif ($case === 'memory_large_objects') {
        for ($i = 0; $i < 24; $i++) {
            $object = new MicrobenchValue([$i], str_repeat(chr(65 + $i), 1024 * 1024));
            if (!$cache->store("large-$i", $object)) throw new RuntimeException('Store failed.');
            $cache->fetch("large-$i");
            $cache->fetch("large-$i");
        }
        unset($object);
        $iterations = 24;
    } elseif ($case === 'memory_fixed_arrays') {
        for ($i = 0; $i < 1500; $i++) {
            $object = SplFixedArray::fromArray(array_fill(0, 512, $i));
            if (!$cache->store("fixed-$i", $object)) throw new RuntimeException('Store failed.');
            $cache->fetch("fixed-$i");
            $cache->fetch("fixed-$i");
        }
        unset($object);
        $iterations = 1500;
    } elseif ($case === 'memory_object_arrays') {
        $object = (object) ['children' => array_fill(0, 16384, new stdClass())];
        for ($i = 0; $i < 32; $i++) {
            if (!$cache->store("children-$i", $object)) throw new RuntimeException('Store failed.');
            $cache->fetch("children-$i");
            $cache->fetch("children-$i");
        }
        unset($object);
        $iterations = 32;
    } else {
        throw new InvalidArgumentException('Unknown memory case.');
    }
    $elapsed = hrtime(true) - $start;
    gc_collect_cycles();
} else {
    runWorkload($case, $cache, $keys, $values, $value, min($iterations, 3000));
    $memoryBefore = memory_get_usage();
    $start = hrtime(true);
    $checksum = runWorkload($case, $cache, $keys, $values, $value, $iterations);
    $elapsed = hrtime(true) - $start;
}
$memoryAfter = memory_get_usage();
$status = Cache::getStatus();
$result = [
    'case' => $case,
    'iterations' => $iterations,
    'elapsed_ns' => $elapsed,
    'ns_per_operation' => $elapsed / $iterations,
    'retained_php_bytes' => $memoryAfter - $memoryBefore,
    'peak_php_bytes' => memory_get_peak_usage(),
    'shared_used_bytes' => $status->getUsedMemory(),
    'entries' => $status->getEntryCount(),
    'checksum_type' => get_debug_type($checksum),
];

// Keep validation outside both the timed loop and the memory snapshots above.
$validationChecks = 0;
function verifyMicrobench(bool $condition, string $message): void {
    global $validationChecks;
    $validationChecks++;
    if (!$condition) throw new RuntimeException($message);
}
function verifyMicrobenchValue(mixed $actual, mixed $expected): void {
    verifyMicrobench(get_debug_type($actual) === get_debug_type($expected) &&
        (is_object($expected) ? $actual == $expected : $actual === $expected), 'Fetched value mismatch.');
}

verifyMicrobench($status->getStoreFailureCount() === 0, 'A store failed during the workload.');
$expectedEntries = match ($case) {
    'status_empty', 'store_delete' => 0,
    'status_fragmented', 'pool_status' => 4096,
    'memory_strings' => 40001,
    'memory_objects' => 6001,
    'memory_large_objects' => 25,
    'memory_fixed_arrays' => 1501,
    'memory_object_arrays' => 33,
    default => $size,
};
verifyMicrobench($status->getEntryCount() === $expectedEntries, 'Final entry count mismatch.');
if ($case === 'memory_pools') {
    verifyMicrobench(count(Cache::getPools()) === 2001, 'Pool registry count mismatch.');
    for ($i = 0; $i < 2000; $i++) {
        verifyMicrobench(Cache::getPool("pool-$i")->fetch('missing') === null, 'Expected an empty pool.');
    }
    verifyMicrobenchValue($cache->fetch('k0'), 42);
} elseif ($case === 'memory_strings') {
    for ($i = 0; $i < 40000; $i++) {
        verifyMicrobenchValue($cache->fetch("s$i"), str_pad((string) $i, 300, 'x'));
    }
} elseif ($case === 'memory_objects') {
    for ($i = 0; $i < 6000; $i++) {
        verifyMicrobenchValue($cache->fetch("o$i"),
            new MicrobenchValue(array_fill(0, 32, $i), str_pad((string) $i, 768, 'x')));
    }
} elseif ($case === 'memory_large_objects') {
    for ($i = 0; $i < 24; $i++) {
        verifyMicrobenchValue($cache->fetch("large-$i"),
            new MicrobenchValue([$i], str_repeat(chr(65 + $i), 1024 * 1024)));
    }
} elseif ($case === 'memory_fixed_arrays') {
    for ($i = 0; $i < 1500; $i++) {
        verifyMicrobenchValue($cache->fetch("fixed-$i"), SplFixedArray::fromArray(array_fill(0, 512, $i)));
    }
} elseif ($case === 'memory_object_arrays') {
    for ($i = 0; $i < 32; $i++) {
        $actual = $cache->fetch("children-$i");
        verifyMicrobench($actual instanceof stdClass && isset($actual->children) &&
            is_array($actual->children) && count($actual->children) === 16384, 'Object-array shape mismatch.');
        $child = $actual->children[0];
        verifyMicrobench($child instanceof stdClass && get_object_vars($child) === [], 'Child value mismatch.');
        foreach ($actual->children as $item) {
            verifyMicrobench($item === $child, 'Repeated object identity was not preserved.');
        }
    }
} elseif ($case === 'status_fragmented' || $case === 'pool_status') {
    verifyMicrobench($checksum === ($case === 'pool_status' ? 4096 : $status->getFreeMemory()),
        'Status result mismatch.');
    for ($i = 0; $i < 8192; $i++) {
        verifyMicrobenchValue($cache->fetch("fragment-$i"),
            ($i & 1) ? str_repeat('x', 64 + ($i % 12) * 64) : null);
    }
} elseif ($case === 'status_empty') {
    verifyMicrobench($checksum === $status->getFreeMemory(), 'Empty status result mismatch.');
} elseif ($case === 'store_delete') {
    verifyMicrobench($checksum === true && !$cache->has($keys[0]), 'Final delete failed.');
} else {
    if (str_starts_with($case, 'fetch_multiple_')) {
        verifyMicrobenchValue($checksum, $values);
    } elseif ($case === 'fetch_miss') {
        verifyMicrobench($checksum === null, 'Missing-key fetch returned a value.');
    } elseif (str_starts_with($case, 'store_') || $case === 'has_scalar' || $case === 'lock_unlock') {
        verifyMicrobench($checksum === true, 'Final operation failed.');
    } else {
        verifyMicrobenchValue($checksum, $value);
    }
    foreach ($keys as $key) verifyMicrobenchValue($cache->fetch($key), $value);
    if ($case === 'lock_unlock') {
        verifyMicrobench($cache->unlock($keys[0]) === false, 'Workload left an entry locked.');
    }
}
$result['validation_checks'] = $validationChecks;
$result['errors'] = 0;
echo json_encode($result, JSON_THROW_ON_ERROR), "\n";
