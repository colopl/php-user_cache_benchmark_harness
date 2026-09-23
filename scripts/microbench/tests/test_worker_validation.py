"""Exercise worker assertions with an in-memory fake cache, never benchmark SHM."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest


DIRECTORY = Path(__file__).resolve().parents[1]
PHP = Path(os.environ.get('PHP_CLI_BIN', DIRECTORY.parents[2] / 'sapi/cli/php'))
FIXTURE = r'''<?php
namespace MicrobenchFixture {
enum CacheAvailability { case Available; }
final class Status {
    public function __construct(private ?Cache $pool = null) {}
    public function getAvailability(): CacheAvailability { return CacheAvailability::Available; }
    public function getEntryCount(): int {
        return $this->pool ? count($this->pool->data) : array_sum(array_map(fn($p) => count($p->data), Cache::$pools));
    }
    public function getEntryKeys(): array { return array_keys($this->pool->data); }
    public function getFreeMemory(): int { return 1024; }
    public function getUsedMemory(): int { return 1024; }
    public function getStoreFailureCount(): int { return 0; }
}
final class Cache {
    public static array $pools = [];
    public array $data = [];
    private array $locks = [];
    public static function getPool(string $name): self { return self::$pools[$name] ??= new self; }
    public static function getPools(): array { return self::$pools; }
    public static function getStatus(): Status { return new Status; }
    public function getPoolStatus(): Status { return new Status($this); }
    public function clear(): bool { $this->data = []; return true; }
    public function storeMultiple(array $values): bool { $this->data = array_replace($this->data, $values); return true; }
    public function store(string $key, mixed $value): bool {
        if (getenv('MICROBENCH_FIXTURE_FAULT') === 'store') return false;
        if (getenv('MICROBENCH_FIXTURE_FAULT') !== 'silent-write' || !array_key_exists($key, $this->data)) $this->data[$key] = $value;
        return true;
    }
    public function fetch(string $key): mixed {
        $value = $this->data[$key] ?? null;
        if (getenv('MICROBENCH_FIXTURE_FAULT') === 'scalar' && is_int($value)) return $value + 1;
        if (getenv('MICROBENCH_FIXTURE_FAULT') === 'miss' && $value === null) return 99;
        return $value;
    }
    public function fetchMultiple(array $keys): array {
        $values = []; foreach ($keys as $key) $values[$key] = $this->fetch($key); return $values;
    }
    public function has(string $key): bool { return array_key_exists($key, $this->data); }
    public function delete(string $key): bool { unset($this->data[$key]); return true; }
    public function lock(string $key): bool { $this->locks[$key] = true; return true; }
    public function unlock(string $key): bool {
        $locked = isset($this->locks[$key]); unset($this->locks[$key]); return $locked;
    }
    public function remember(string $key, callable $callback): mixed { return $this->data[$key] ??= $callback(); }
}
}
namespace {
'''


@unittest.skipUnless(PHP.is_file(), 'Set PHP_CLI_BIN to run the fake-cache assertion checks')
class WorkerValidationTest(unittest.TestCase):
    def worker(self, name, args, fault=''):
        source = (DIRECTORY / 'workers' / name).read_text()
        source = source.removeprefix('<?php').replace('UserCache\\', 'MicrobenchFixture\\')
        with tempfile.TemporaryDirectory() as temporary:
            path = Path(temporary) / name
            path.write_text(FIXTURE + source + '\n}\n')
            return subprocess.run([str(PHP), '-n', str(path), *map(str, args)],
                                  env=dict(os.environ, MICROBENCH_FIXTURE_FAULT=fault),
                                  capture_output=True, text=True, timeout=10)

    def test_core_final_values_and_failed_operations_are_rejected(self):
        for case in ['fetch_scalar_1', 'fetch_string_300', 'fetch_object', 'fetch_multiple_32',
                     'store_scalar', 'store_delete', 'lock_unlock', 'remember_miss', 'fetch_miss']:
            with self.subTest(case=case):
                result = self.worker('core.php', [case, 3])
                self.assertEqual(result.returncode, 0, result.stderr + result.stdout)
                self.assertGreater(json.loads(result.stdout)['validation_checks'], 0)
        for case, fault in [('fetch_scalar_1', 'scalar'), ('fetch_multiple_32', 'scalar'),
                            ('store_scalar', 'store'), ('fetch_miss', 'miss')]:
            with self.subTest(case=case, fault=fault):
                result = self.worker('core.php', [case, 3], fault)
                self.assertNotEqual(result.returncode, 0)

    def test_lru_checksums_cover_partial_cycles(self):
        for kind in ['scalar', 'string', 'array']:
            result = self.worker('lru.php', [kind, 64, 129])
            self.assertEqual(result.returncode, 0, result.stderr + result.stdout)
            self.assertEqual(json.loads(result.stdout)['validation_checks'], 65)
        self.assertNotEqual(self.worker('lru.php', ['scalar', 64, 129], 'scalar').returncode, 0)

    def test_status_detects_silent_writes_without_count_changes(self):
        for mode in ['first', 'warm', 'same-write', 'other-write', 'same-replace',
                     'other-replace', 'same-churn', 'other-churn']:
            with self.subTest(mode=mode):
                result = self.worker('status.php', [mode, 1 if mode == 'first' else 4, 2, 3])
                self.assertEqual(result.returncode, 0, result.stderr + result.stdout)
        result = self.worker('status.php', ['same-write', 4, 2, 3], 'silent-write')
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('final pool value mismatch', result.stderr + result.stdout)


if __name__ == '__main__':
    unittest.main()
