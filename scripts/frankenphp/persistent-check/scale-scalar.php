<?php
/* Persistent-worker correctness probe driven by frankenphp-scale -kind scalar.
 * Every check throws, turning into an HTTP 500 that aborts the driver.
 * Usage (after ./benchmark.sh --build-persistence):
 *   runtime/frankenphp/bin/frankenphp-scale -root scripts/frankenphp/persistent-check \
 *       -kind scalar -workers 4 -operations 100 -duration 3s
 * Exit status 0 means every check passed and no graph pin leaked. */
$cache = UserCache\Cache::getPool('pcheck');
$payloads = [
    'int' => 12345,
    'dbl' => 1.5,
    'short' => 'abc',
    'str' => str_repeat('s', 300),
    'long' => str_repeat('L', 5000),
    'packed' => range(0, 7),
    'nested' => [['a' => 1, 'b' => str_repeat('x', 40)], ['c' => [1, 2, 3]]],
    'hash' => ['k1' => 'v1', 'k2' => 2, 'k3' => [true, null, 1.25]],
    'obj' => (object) ['p' => 1, 'q' => [1, 2]],
    'refs' => (function () { $a = [1, 2]; return ['x' => &$a[0], 'y' => &$a[0]]; })(),
];
$lastCounter = -1;
$requests = 0;

function check(bool $ok, string $what): void
{
    if (!$ok) {
        throw new RuntimeException('check failed: ' . $what);
    }
}

function same_payload(mixed $a, mixed $b): bool
{
    return is_object($b) ? ($a == $b && $a !== $b) : $a === $b;
}

function run_checks(int $serial, int $thread): void
{
    global $cache, $payloads, $lastCounter;
    switch ($serial % 12) {
        case 0:
            $key = "own-$thread";
            $value = ['serial' => $serial, 's' => str_repeat('x', $serial % 50), 'n' => [$serial]];
            check($cache->store($key, $value), 'own store');
            check($cache->fetch($key) === $value, 'own array read-after-write');
            check($cache->fetch($key) === $value, 'own array repeated read');
            break;
        case 1:
            $v = $cache->fetch('packed');
            $v[0] = 99;
            $w = $cache->fetch('nested');
            $w[0]['b'][0] = 'z';
            $w[1]['c'][] = 4;
            check($cache->fetch('packed') === $payloads['packed'], 'packed COW isolation');
            check($cache->fetch('nested') === $payloads['nested'], 'nested COW isolation');
            break;
        case 2:
            $s = $cache->fetch('str');
            $s[0] = 'Z';
            $l = $cache->fetch('long');
            $l[0] = 'Z';
            check($cache->fetch('str') === $payloads['str'], 'string COW isolation');
            check($cache->fetch('long') === $payloads['long'], 'long string COW isolation');
            break;
        case 3:
            foreach ($payloads as $k => $p) {
                check(same_payload($cache->fetch($k), $p), "shared payload $k");
            }
            $r = $cache->fetch('refs');
            $r['x'] = 7;
            check($r['y'] === 7, 'reference identity kept');
            check($cache->fetch('refs') === $payloads['refs'], 'reference graph isolation');
            break;
        case 4:
            $key = "del-$thread";
            $cache->delete($key);
            check($cache->fetch($key, 'none') === 'none', 'deleted key');
            check(!$cache->has($key), 'deleted key has');
            check($cache->store($key, "v$serial"), 'del re-store');
            check($cache->fetch($key) === "v$serial", 'del re-add read');
            check($cache->has($key), 're-added has');
            break;
        case 5:
            $key = "ttl-$thread";
            $value = ['ttl' => $serial];
            check($cache->store($key, $value, 100), 'ttl store');
            check($cache->fetch($key) === $value, 'ttl read');
            check($cache->fetch($key) === $value, 'ttl repeated read');
            break;
        case 6:
            $key = "sc-$thread";
            check($cache->store($key, $serial), 'scalar store');
            check($cache->fetch($key) === $serial, 'scalar read-after-write');
            check($cache->increment($key, 2) === $serial + 2, 'increment result');
            check($cache->fetch($key) === $serial + 2, 'read after increment');
            check($cache->store($key, "str$serial"), 'scalar to string');
            check($cache->fetch($key) === "str$serial", 'read after type change');
            break;
        case 7:
            $o = $cache->fetch('obj');
            $o->p = 2;
            check($cache->fetch('obj') == $payloads['obj'], 'object isolation');
            check($cache->fetch('obj') !== $cache->fetch('obj'), 'object fresh instance');
            break;
        case 8:
            $keys = ['int', 'str', 'packed', 'nested', 'missing', 'hash', 'long'];
            $all = $cache->fetchMultiple($keys, 'dflt');
            foreach ($keys as $k) {
                check($all[$k] === ($payloads[$k] ?? 'dflt'), "fetchMultiple $k");
            }
            $all['packed'][0] = -5;
            check($cache->fetch('packed') === $payloads['packed'], 'fetchMultiple COW');
            break;
        case 9:
            $counter = $cache->increment('counter');
            check(is_int($counter) && $counter > $lastCounter, 'counter monotonic increment');
            $seen = $cache->fetch('counter');
            check(is_int($seen) && $seen >= $counter, 'counter read not stale');
            $lastCounter = $seen;
            $shared = $cache->fetch('shared-array');
            check(is_array($shared) && $shared['v'] >= 0, 'shared array read');
            check($cache->store('shared-array', ['v' => $serial, 'pad' => str_repeat('p', 64)]), 'shared array store');
            break;
        case 10:
            $pool = UserCache\Cache::getPool("clr-$thread");
            check($pool->store('a', [1, 2]) && $pool->store('b', 'bee') && $pool->store('c', 3), 'clear pool store');
            check($pool->fetch('a') === [1, 2] && $pool->fetch('b') === 'bee' && $pool->fetch('c') === 3, 'clear pool read');
            check($pool->clear(), 'clear');
            check($pool->fetch('a', 'gone') === 'gone' && $pool->fetch('b', 'gone') === 'gone' && $pool->fetch('c', 'gone') === 'gone', 'cleared read');
            check($pool->store('a', [3]), 'store after clear');
            check($pool->fetch('a') === [3], 'read after clear store');
            break;
        case 11:
            $key = "multi-$thread";
            check($cache->storeMultiple([$key . 'a' => [$serial], $key . 'b' => "s$serial"]), 'storeMultiple');
            check($cache->fetch($key . 'a') === [$serial] && $cache->fetch($key . 'b') === "s$serial", 'storeMultiple read');
            check($cache->deleteMultiple([$key . 'a']), 'deleteMultiple');
            check($cache->fetch($key . 'a', 'none') === 'none', 'deleteMultiple read');
            $got = $cache->remember($key . 'r', fn() => ['r' => $serial]);
            check(is_array($got) && isset($got['r']), 'remember');
            break;
    }
}

function scalar_request(): void
{
    global $cache, $payloads, $requests;
    $action = $_GET['action'] ?? 'run';
    $result = ['thread' => uc_bench_thread_id()];
    if ($action === 'setup') {
        check($cache->clear(), 'setup clear');
        foreach ($payloads as $k => $p) {
            check($cache->store($k, $p), "seed $k");
        }
        check($cache->store('counter', 0) && $cache->store('shared-array', ['v' => 0]), 'seed shared');
        $result['ready'] = true;
    } elseif ($action === 'warm') {
        foreach ($payloads as $k => $p) {
            check(same_payload($cache->fetch($k), $p), "warm $k");
        }
        usleep(1000);
        $result['ready'] = true;
    } elseif ($action === 'status') {
        $status = UserCache\Cache::getStatus();
        $result += ['pins' => $status->getGraphPinnedReferences(), 'shared_used' => $status->getUsedMemory(),
            'php_heap' => memory_get_usage(), 'reference_persistent_bytes' => 0];
    } else {
        $count = max(1, min(100, (int) ($_GET['operations'] ?? 100)));
        $sequence = (int) ($_GET['sequence'] ?? 0);
        $thread = uc_bench_thread_id();
        $cpu = uc_bench_thread_cpu_ns();
        $start = hrtime(true);
        for ($i = 0; $i < $count; $i++) {
            run_checks($sequence * $count + $i + $requests, $thread);
        }
        $requests++;
        $result += ['batch_ns' => hrtime(true) - $start, 'thread_cpu_ns' => uc_bench_thread_cpu_ns() - $cpu,
            'reads' => $count, 'writes' => 0, 'failures' => 0, 'checksum' => $count * 307];
    }
    header('Content-Type: application/json');
    echo json_encode($result, JSON_THROW_ON_ERROR);
}

while (frankenphp_handle_request(scalar_request(...))) {}
