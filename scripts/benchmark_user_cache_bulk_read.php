<?php

declare(strict_types=1);

require_once __DIR__ . '/UserCacheBenchmark.php';

interface UcBulkReadBackend
{
	public function name(): string;
	public function available(): bool;
	public function unavailableReason(): ?string;
	public function clear(): void;
	public function prime(array $values): void;
	public function fetch(array $keys): array;
}

abstract class UcBulkReadAbstractBackend implements UcBulkReadBackend
{
	protected bool $available = true;
	protected ?string $unavailableReason = null;

	public function available(): bool
	{
		return $this->available;
	}

	public function unavailableReason(): ?string
	{
		return $this->unavailableReason;
	}

	protected function unavailable(string $reason): void
	{
		$this->available = false;
		$this->unavailableReason = $reason;
	}
}

final class UcBulkReadUserCacheBackend extends UcBulkReadAbstractBackend
{
	private ?Opcache\UserCache $cache = null;

	public function __construct()
	{
		if (!class_exists('Opcache\\UserCache')) {
			$this->unavailable('Opcache\\UserCache class is not available');
			return;
		}

		$this->cache = new Opcache\UserCache('bulk-read-benchmark');
		$info = $this->cache->info();
		if (!$info->available) {
			$this->unavailable($info->unavailableReason ?? 'Opcache\\UserCache is unavailable');
		}
	}

	public function name(): string
	{
		return 'user_cache_fetch_multiple';
	}

	public function clear(): void
	{
		if ($this->cache === null || !$this->cache->clear()) {
			throw new RuntimeException('Opcache\\UserCache::clear() failed');
		}
	}

	public function prime(array $values): void
	{
		if ($this->cache === null || !$this->cache->storeMultiple($values)) {
			throw new RuntimeException('Opcache\\UserCache::storeMultiple() failed');
		}
	}

	public function fetch(array $keys): array
	{
		$result = $this->cache?->fetchMultiple($keys);
		if (!is_array($result)) {
			throw new RuntimeException('Opcache\\UserCache::fetchMultiple() did not return an array');
		}

		return $result;
	}
}

final class UcBulkReadUserCacheLoopBackend extends UcBulkReadAbstractBackend
{
	private ?Opcache\UserCache $cache = null;

	public function __construct()
	{
		if (!class_exists('Opcache\\UserCache')) {
			$this->unavailable('Opcache\\UserCache class is not available');
			return;
		}

		$this->cache = new Opcache\UserCache('bulk-read-loop-benchmark');
		$info = $this->cache->info();
		if (!$info->available) {
			$this->unavailable($info->unavailableReason ?? 'Opcache\\UserCache is unavailable');
		}
	}

	public function name(): string
	{
		return 'user_cache_fetch_loop';
	}

	public function clear(): void
	{
		if ($this->cache === null || !$this->cache->clear()) {
			throw new RuntimeException('Opcache\\UserCache::clear() failed');
		}
	}

	public function prime(array $values): void
	{
		if ($this->cache === null || !$this->cache->storeMultiple($values)) {
			throw new RuntimeException('Opcache\\UserCache::storeMultiple() failed');
		}
	}

	public function fetch(array $keys): array
	{
		$result = [];
		$default = new stdClass();
		foreach ($keys as $key) {
			$value = $this->cache?->fetch($key, $default);
			if ($value === $default) {
				throw new RuntimeException('Opcache\\UserCache::fetch() missed key ' . $key);
			}
			$result[$key] = $value;
		}

		return $result;
	}
}

final class UcBulkReadApcuBackend extends UcBulkReadAbstractBackend
{
	public function __construct()
	{
		if (!function_exists('apcu_fetch') || !function_exists('apcu_store') || !function_exists('apcu_clear_cache')) {
			$this->unavailable('APCu extension is not loaded');
			return;
		}
		if (function_exists('apcu_enabled') && !apcu_enabled()) {
			$this->unavailable('APCu is disabled');
		}
	}

	public function name(): string
	{
		return 'apcu_fetch_multiple';
	}

	public function clear(): void
	{
		if (!apcu_clear_cache()) {
			throw new RuntimeException('apcu_clear_cache() failed');
		}
	}

	public function prime(array $values): void
	{
		$result = apcu_store($values);
		if ($result !== true && $result !== []) {
			throw new RuntimeException('apcu_store(array) failed');
		}
	}

	public function fetch(array $keys): array
	{
		$success = false;
		$result = apcu_fetch($keys, $success);
		if (!is_array($result)) {
			throw new RuntimeException('apcu_fetch(array) did not return an array');
		}

		return $result;
	}
}

final class UcBulkReadApcuLoopBackend extends UcBulkReadAbstractBackend
{
	public function __construct()
	{
		if (!function_exists('apcu_fetch') || !function_exists('apcu_store') || !function_exists('apcu_clear_cache')) {
			$this->unavailable('APCu extension is not loaded');
			return;
		}
		if (function_exists('apcu_enabled') && !apcu_enabled()) {
			$this->unavailable('APCu is disabled');
		}
	}

	public function name(): string
	{
		return 'apcu_fetch_loop';
	}

	public function clear(): void
	{
		if (!apcu_clear_cache()) {
			throw new RuntimeException('apcu_clear_cache() failed');
		}
	}

	public function prime(array $values): void
	{
		$result = apcu_store($values);
		if ($result !== true && $result !== []) {
			throw new RuntimeException('apcu_store(array) failed');
		}
	}

	public function fetch(array $keys): array
	{
		$result = [];
		foreach ($keys as $key) {
			$success = false;
			$value = apcu_fetch($key, $success);
			if (!$success) {
				throw new RuntimeException('apcu_fetch() missed key ' . $key);
			}
			$result[$key] = $value;
		}

		return $result;
	}
}

final class UcBulkReadRunner
{
	private int $keyCount = 32;
	private int $operations = 1000;
	private int $iterations = 30;
	private int $warmup = 5;
	private string $output;

	public function __construct()
	{
		$this->output = dirname(__DIR__) . '/results/user-cache-bulk-read-' . gmdate('Ymd\THis\Z') . '.json';
	}

	public function run(array $argv): int
	{
		$this->parse($argv);
		$values = $this->values();
		$keys = array_keys($values);
		$expected = $this->checksum($values);
		$rows = [];
		$failures = [];

		foreach ($this->backends() as $backend) {
			if (!$backend->available()) {
				$failures[] = [
					'backend' => $backend->name(),
					'error' => $backend->unavailableReason(),
				];
				continue;
			}

			try {
				$backend->clear();
				$backend->prime($values);
				$this->assertFetched($backend->fetch($keys), $expected, $backend->name());
				for ($i = 0; $i < $this->warmup; $i++) {
					$this->sample($backend, $keys, $expected);
				}

				$samples = [];
				for ($i = 0; $i < $this->iterations; $i++) {
					$samples[] = $this->sample($backend, $keys, $expected);
				}
				$meanBatch = $this->mean($samples);
				$rows[] = [
					'backend' => $backend->name(),
					'key_count' => $this->keyCount,
					'operations' => $this->operations,
					'iterations' => $this->iterations,
					'mean_us_per_batch' => $meanBatch,
					'median_us_per_batch' => $this->median($samples),
					'mean_us_per_key' => $meanBatch / $this->keyCount,
					'samples_us_per_batch' => $samples,
				];
			} catch (Throwable $throwable) {
				$failures[] = [
					'backend' => $backend->name(),
					'error_class' => $throwable::class,
					'error' => $throwable->getMessage(),
				];
			}
		}

		$result = [
			'generated_at' => gmdate(DATE_ATOM),
			'options' => [
				'key_count' => $this->keyCount,
				'operations' => $this->operations,
				'iterations' => $this->iterations,
				'warmup' => $this->warmup,
			],
			'environment' => [
				'php_version' => PHP_VERSION,
				'php_sapi' => PHP_SAPI,
				'opcache_user_cache_shm_size' => ini_get('opcache.user_cache_shm_size'),
				'apcu' => extension_loaded('apcu'),
			],
			'rows' => $rows,
			'failures' => $failures,
		];
		$this->writeJson($this->output, $result);
		$this->printSummary($result);

		return $rows === [] ? 1 : 0;
	}

	private function parse(array $argv): void
	{
		for ($i = 1; $i < count($argv); $i++) {
			$arg = $argv[$i];
			switch ($arg) {
				case '--key-count':
					$this->keyCount = $this->positiveInt($this->value($argv, ++$i, $arg), $arg);
					break;
				case '--operations':
					$this->operations = $this->positiveInt($this->value($argv, ++$i, $arg), $arg);
					break;
				case '--iterations':
					$this->iterations = $this->positiveInt($this->value($argv, ++$i, $arg), $arg);
					break;
				case '--warmup':
					$this->warmup = $this->nonNegativeInt($this->value($argv, ++$i, $arg), $arg);
					break;
				case '--output':
					$this->output = $this->absolutePath($this->value($argv, ++$i, $arg));
					break;
				case '-h':
				case '--help':
					$this->usage();
					exit(0);
				default:
					throw new InvalidArgumentException('Unknown argument: ' . $arg);
			}
		}
	}

	private function usage(): void
	{
		fwrite(STDOUT, "Usage: php scripts/benchmark_user_cache_bulk_read.php [--key-count N] [--operations N] [--iterations N] [--warmup N] [--output FILE]\n");
	}

	private function values(): array
	{
		$case = UcBenchPayloadFactory::select(['multi_key_config_read'])['multi_key_config_read'];
		$payload = $case['build']();
		$entries = array_values($payload['entries']);
		$values = [];
		for ($i = 0; $i < $this->keyCount; $i++) {
			$entry = $entries[$i % count($entries)];
			$entry['name'] = 'bulk_config_' . $i;
			$entry['limits']['items'] += $i;
			$values['bulk:' . $i] = $entry;
		}

		return $values;
	}

	private function backends(): array
	{
		return [
			new UcBulkReadUserCacheBackend(),
			new UcBulkReadUserCacheLoopBackend(),
			new UcBulkReadApcuBackend(),
			new UcBulkReadApcuLoopBackend(),
		];
	}

	private function sample(UcBulkReadBackend $backend, array $keys, string $expected): float
	{
		$last = [];
		$started = hrtime(true);
		for ($i = 0; $i < $this->operations; $i++) {
			$last = $backend->fetch($keys);
		}
		$elapsed = hrtime(true) - $started;
		$this->assertFetched($last, $expected, $backend->name());

		return ($elapsed / 1000) / $this->operations;
	}

	private function assertFetched(array $values, string $expected, string $backendName): void
	{
		if (count($values) !== $this->keyCount) {
			throw new RuntimeException($backendName . ' returned ' . count($values) . ' values; expected ' . $this->keyCount);
		}
		$actual = $this->checksum($values);
		if ($actual !== $expected) {
			throw new RuntimeException($backendName . ' checksum mismatch: expected ' . $expected . ', got ' . $actual);
		}
	}

	private function checksum(array $values): string
	{
		ksort($values);
		$first = reset($values);
		$last = end($values);
		if (!is_array($first) || !is_array($last)) {
			throw new RuntimeException('Bulk values are malformed');
		}

		return count($values) . ':' . $first['name'] . ':' . $first['limits']['items'] . ':' . $last['name'] . ':' . $last['limits']['items'];
	}

	private function printSummary(array $result): void
	{
		echo 'Output: ' . $this->output . "\n";
		echo "backend\tmean_us/batch\tmedian_us/batch\tmean_us/key\n";
		foreach ($result['rows'] as $row) {
			printf(
				"%s\t%.3f\t%.3f\t%.3f\n",
				$row['backend'],
				$row['mean_us_per_batch'],
				$row['median_us_per_batch'],
				$row['mean_us_per_key'],
			);
		}
		foreach ($result['failures'] as $failure) {
			fprintf(STDERR, "FAIL %s: %s\n", $failure['backend'], $failure['error']);
		}
	}

	private function writeJson(string $path, array $payload): void
	{
		$dir = dirname($path);
		if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
			throw new RuntimeException('Unable to create output directory: ' . $dir);
		}
		file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
	}

	private function value(array $argv, int $offset, string $option): string
	{
		if (!isset($argv[$offset]) || str_starts_with($argv[$offset], '--')) {
			throw new InvalidArgumentException($option . ' requires a value');
		}

		return $argv[$offset];
	}

	private function positiveInt(string $value, string $option): int
	{
		if (!preg_match('/^[1-9][0-9]*$/', $value)) {
			throw new InvalidArgumentException($option . ' must be a positive integer');
		}

		return (int) $value;
	}

	private function nonNegativeInt(string $value, string $option): int
	{
		if (!preg_match('/^(0|[1-9][0-9]*)$/', $value)) {
			throw new InvalidArgumentException($option . ' must be a non-negative integer');
		}

		return (int) $value;
	}

	private function absolutePath(string $path): string
	{
		if ($path === '') {
			throw new InvalidArgumentException('Path must not be empty');
		}
		if ($path[0] === '/') {
			return $path;
		}

		return getcwd() . '/' . $path;
	}

	private function mean(array $values): float
	{
		return array_sum($values) / max(1, count($values));
	}

	private function median(array $values): float
	{
		sort($values, SORT_NUMERIC);
		$count = count($values);
		$middle = intdiv($count, 2);
		if ($count % 2 === 1) {
			return (float) $values[$middle];
		}

		return ((float) $values[$middle - 1] + (float) $values[$middle]) / 2.0;
	}
}

try {
	exit((new UcBulkReadRunner())->run($argv));
} catch (Throwable $throwable) {
	fwrite(STDERR, 'Benchmark failed: ' . $throwable->getMessage() . "\n");
	exit(1);
}
