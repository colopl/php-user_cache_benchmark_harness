<?php

declare(strict_types=1);

require_once __DIR__ . '/UserCacheBenchmark.php';

final class UcResidentProbeRunner
{
	private array $cases = ['constant_array', 'route_table_read', 'large_array', 'large_string'];
	private int $operations = 3000;
	private int $iterations = 20;
	private int $warmup = 3;
	private string $output;

	public function __construct()
	{
		$this->output = dirname(__DIR__) . '/results/resident-payload-probe-' . gmdate('Ymd\THis\Z') . '.json';
	}

	public function run(array $argv): int
	{
		$this->parse($argv);
		$rows = [];
		$failures = [];

		foreach (UcBenchPayloadFactory::select($this->cases) as $caseName => $case) {
			try {
				$probe = $case['probe'];
				if ($probe === null) {
					throw new RuntimeException('Selected case does not define a probe');
				}

				$payload = $case['build']();
				$expectedDigest = $case['digest']($payload);
				$samples = [];
				for ($i = 0; $i < $this->warmup; $i++) {
					$this->sample($payload, $probe, $case, $expectedDigest);
				}
				for ($i = 0; $i < $this->iterations; $i++) {
					$samples[] = $this->sample($payload, $probe, $case, $expectedDigest);
				}

				$rows[] = [
					'case' => $caseName,
					'backend' => 'resident_payload_probe',
					'operations' => $this->operations,
					'iterations' => $this->iterations,
					'mean_operation_us' => $this->mean($samples),
					'median_us' => $this->median($samples),
					'min_us' => min($samples),
					'max_us' => max($samples),
					'samples_us' => $samples,
				];
			} catch (Throwable $throwable) {
				$failures[] = [
					'case' => $caseName,
					'error_class' => $throwable::class,
					'error' => $throwable->getMessage(),
				];
			}
		}

		$result = [
			'generated_at' => gmdate(DATE_ATOM),
			'options' => [
				'cases' => $this->cases,
				'operations' => $this->operations,
				'iterations' => $this->iterations,
				'warmup' => $this->warmup,
			],
			'environment' => [
				'php_version' => PHP_VERSION,
				'php_sapi' => PHP_SAPI,
				'opcache_enable_cli' => ini_get('opcache.enable_cli'),
				'opcache_jit' => ini_get('opcache.jit'),
			],
			'rows' => $rows,
			'failures' => $failures,
		];

		$this->writeJson($this->output, $result);
		$this->printSummary($result);

		return $failures === [] ? 0 : 1;
	}

	private function parse(array $argv): void
	{
		for ($i = 1; $i < count($argv); $i++) {
			$arg = $argv[$i];
			switch ($arg) {
				case '--cases':
					$this->cases = $this->csv($this->value($argv, ++$i, $arg));
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
		fwrite(STDOUT, "Usage: php scripts/benchmark_user_cache_resident_probe.php [--cases a,b] [--operations N] [--iterations N] [--warmup N] [--output FILE]\n");
	}

	private function sample(mixed $payload, callable $probe, array $case, string $expectedDigest): float
	{
		$score = 0;
		$start = hrtime(true);
		for ($i = 0; $i < $this->operations; $i++) {
			$score ^= $probe($payload, $i);
		}
		$elapsed = hrtime(true) - $start;
		if ($case['digest']($payload) !== $expectedDigest) {
			throw new RuntimeException('Resident payload was mutated while probing');
		}
		if ($score === PHP_INT_MIN) {
			throw new RuntimeException('Unreachable score guard was hit');
		}

		return ($elapsed / 1000) / $this->operations;
	}

	private function printSummary(array $result): void
	{
		echo 'Output: ' . $this->output . "\n";
		echo "case\tmean_us/op\tmedian_us/op\n";
		foreach ($result['rows'] as $row) {
			printf("%s\t%.3f\t%.3f\n", $row['case'], $row['mean_operation_us'], $row['median_us']);
		}
		foreach ($result['failures'] as $failure) {
			fprintf(STDERR, "FAIL %s: %s\n", $failure['case'], $failure['error']);
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

	private function csv(string $value): array
	{
		$items = array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $item): bool => $item !== ''));
		if ($items === []) {
			throw new InvalidArgumentException('CSV option must not be empty');
		}

		return $items;
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
	exit((new UcResidentProbeRunner())->run($argv));
} catch (Throwable $throwable) {
	fwrite(STDERR, 'Benchmark failed: ' . $throwable->getMessage() . "\n");
	exit(1);
}
