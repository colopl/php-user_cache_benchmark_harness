<?php

declare(strict_types=1);

final class UserCacheFpmReadRunner
{
	private string $baseUrl = 'http://127.0.0.1:8080/user_cache_fpm_read_bench.php';
	private array $cases = [
		'constant_array',
		'route_table_read',
		'large_array',
		'large_string',
		'large_object_graph',
		'metadata_object_read',
		'metadata_object_fetch_mutate',
		'safe_direct_object',
		'spl_collection_object',
		'spl_linked_collection_object',
		'spl_heap_object',
		'carbon_datetime_object',
		'carbon_model_object',
	];
	private array $backends = ['user_cache', 'apcu', 'deepclone'];
	private int $operations = 1;
	private int $requests = 60;
	private int $warmup = 10;
	private int $concurrency = 5;
	private int $holdUs = 10000;
	private string $output;

	public function __construct()
	{
		$this->output = dirname(__DIR__) . '/results/user-cache-fpm-read-' . gmdate('Ymd\THis\Z') . '.json';
	}

	public function run(array $argv): int
	{
		$this->parse($argv);
		$description = $this->requestJson(['action' => 'describe']);
		$results = [];
		$failures = [];

		foreach ($this->cases as $caseName) {
			foreach ($this->backends as $backendName) {
				try {
					$prime = $this->requestJson([
						'action' => 'prime',
						'case' => $caseName,
						'backend' => $backendName,
					]);
					$digest = (string) ($prime['digest'] ?? '');
					if ($digest === '') {
						throw new RuntimeException('Prime response did not include a digest');
					}

					if ($this->warmup > 0) {
						$this->runMeasureRequests($caseName, $backendName, $digest, $this->warmup);
					}

					$samples = $this->runMeasureRequests($caseName, $backendName, $digest, $this->requests);
					$results[] = $this->summarize($caseName, $backendName, $samples);
				} catch (Throwable $throwable) {
					$failures[] = [
						'case' => $caseName,
						'backend' => $backendName,
						'error_class' => $throwable::class,
						'error' => $throwable->getMessage(),
					];
				}
			}
		}

		$payload = [
			'generated_at' => gmdate(DATE_ATOM),
			'base_url' => $this->baseUrl,
			'options' => [
				'cases' => $this->cases,
				'backends' => $this->backends,
				'operations' => $this->operations,
				'requests' => $this->requests,
				'warmup' => $this->warmup,
				'concurrency' => $this->concurrency,
				'hold_us' => $this->holdUs,
			],
			'environment' => $description,
			'results' => $results,
			'failures' => $failures,
		];

		$this->writeJson($this->output, $payload);
		$this->printSummary($payload);

		return $failures === [] ? 0 : 1;
	}

	private function parse(array $argv): void
	{
		for ($i = 1; $i < count($argv); $i++) {
			$arg = $argv[$i];
			switch ($arg) {
				case '--base-url':
					$this->baseUrl = $this->value($argv, ++$i, $arg);
					break;
				case '--cases':
					$this->cases = $this->csv($this->value($argv, ++$i, $arg));
					break;
				case '--backends':
					$this->backends = $this->csv($this->value($argv, ++$i, $arg));
					break;
				case '--operations':
					$this->operations = $this->positiveInt($this->value($argv, ++$i, $arg), $arg);
					break;
				case '--requests':
					$this->requests = $this->positiveInt($this->value($argv, ++$i, $arg), $arg);
					break;
				case '--warmup':
					$this->warmup = $this->nonNegativeInt($this->value($argv, ++$i, $arg), $arg);
					break;
				case '--concurrency':
					$this->concurrency = $this->positiveInt($this->value($argv, ++$i, $arg), $arg);
					break;
				case '--hold-us':
					$this->holdUs = $this->nonNegativeInt($this->value($argv, ++$i, $arg), $arg);
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
		fwrite(STDOUT, "Usage: php scripts/benchmark_user_cache_fpm_read.php [--base-url URL] [--cases a,b] [--backends user_cache,apcu,deepclone] [--operations N] [--requests N] [--warmup N] [--concurrency N] [--hold-us N] [--output FILE]\n");
	}

	private function runMeasureRequests(string $caseName, string $backendName, string $digest, int $requestCount): array
	{
		$samples = [];
		$remaining = $requestCount;

		while ($remaining > 0) {
			$batchSize = min($this->concurrency, $remaining);
			$batch = [];
			for ($i = 0; $i < $batchSize; $i++) {
				$batch[] = $this->startCurl($this->url([
					'action' => 'measure',
					'case' => $caseName,
					'backend' => $backendName,
					'operations' => (string) $this->operations,
					'hold_us' => (string) $this->holdUs,
					'expected_digest' => $digest,
				]));
			}
			foreach ($batch as $process) {
				$samples[] = $this->finishCurl($process);
			}
			$remaining -= $batchSize;
		}

		return $samples;
	}

	private function startCurl(string $url): array
	{
		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];
		$process = proc_open(['curl', '-fsS', '--max-time', '300', $url], $descriptors, $pipes);
		if (!is_resource($process)) {
			throw new RuntimeException('Failed to start curl');
		}
		fclose($pipes[0]);

		return [
			'process' => $process,
			'pipes' => $pipes,
			'started_ns' => hrtime(true),
			'url' => $url,
		];
	}

	private function finishCurl(array $process): array
	{
		$stdout = stream_get_contents($process['pipes'][1]);
		$stderr = stream_get_contents($process['pipes'][2]);
		fclose($process['pipes'][1]);
		fclose($process['pipes'][2]);
		$exitCode = proc_close($process['process']);
		$clientUs = (int) ((hrtime(true) - $process['started_ns']) / 1000);
		if ($exitCode !== 0) {
			throw new RuntimeException('curl failed for ' . $process['url'] . ': ' . trim((string) $stderr));
		}

		$data = json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($data) || ($data['ok'] ?? false) !== true) {
			throw new RuntimeException('Benchmark endpoint returned an error: ' . (string) ($data['error'] ?? $stdout));
		}
		$data['client_us'] = $clientUs;

		return $data;
	}

	private function summarize(string $caseName, string $backendName, array $samples): array
	{
		$serverUs = array_map(static fn (array $sample): float => (float) $sample['server_us_per_op'], $samples);
		$clientUs = array_map(static fn (array $sample): float => (float) $sample['client_us'], $samples);
		$pids = [];
		foreach ($samples as $sample) {
			$pids[(string) $sample['pid']] = ($pids[(string) $sample['pid']] ?? 0) + 1;
		}

		return [
			'case' => $caseName,
			'backend' => $backendName,
			'operations_per_request' => $this->operations,
			'requests' => count($samples),
			'mean_server_us_per_op' => $this->mean($serverUs),
			'median_server_us_per_op' => $this->median($serverUs),
			'min_server_us_per_op' => min($serverUs),
			'max_server_us_per_op' => max($serverUs),
			'mean_client_us_per_request' => $this->mean($clientUs),
			'worker_pids' => $pids,
			'worker_count' => count($pids),
		];
	}

	private function requestJson(array $query): array
	{
		$url = $this->url($query);
		$context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 300]]);
		$body = @file_get_contents($url, false, $context);
		$status = $this->httpStatus($http_response_header ?? []);
		if ($body === false || $status < 200 || $status >= 300) {
			throw new RuntimeException('HTTP request failed (' . $status . '): ' . $url . "\n" . (is_string($body) ? $body : ''));
		}

		$data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($data) || ($data['ok'] ?? false) !== true) {
			throw new RuntimeException('Benchmark endpoint returned an error: ' . (string) ($data['error'] ?? $body));
		}

		return $data;
	}

	private function url(array $query): string
	{
		$separator = str_contains($this->baseUrl, '?') ? '&' : '?';
		return $this->baseUrl . $separator . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
	}

	private function httpStatus(array $headers): int
	{
		foreach ($headers as $header) {
			if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches)) {
				return (int) $matches[1];
			}
		}

		return 0;
	}

	private function printSummary(array $payload): void
	{
		echo "Output: " . $this->output . "\n";
		echo "case\tbackend\tmean_us/op\tmedian_us/op\tworkers\n";
		foreach ($payload['results'] as $row) {
			printf(
				"%s\t%s\t%.3f\t%.3f\t%d\n",
				$row['case'],
				$row['backend'],
				$row['mean_server_us_per_op'],
				$row['median_server_us_per_op'],
				$row['worker_count'],
			);
		}
		foreach ($payload['failures'] as $failure) {
			fprintf(STDERR, "FAIL %s/%s: %s\n", $failure['case'], $failure['backend'], $failure['error']);
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
	exit((new UserCacheFpmReadRunner())->run($argv));
} catch (Throwable $throwable) {
	fwrite(STDERR, 'Benchmark failed: ' . $throwable->getMessage() . "\n");
	exit(1);
}
