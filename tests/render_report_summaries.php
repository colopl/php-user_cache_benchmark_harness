<?php

declare(strict_types=1);

function fail(string $message): never
{
	fwrite(STDERR, $message . "\n");
	exit(1);
}

function write_file(string $path, string $contents): void
{
	if (file_put_contents($path, $contents) === false) {
		fail('Unable to write fixture: ' . $path);
	}
}

function write_json(string $path, array $data): void
{
	write_file($path, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
}

function cli_row(string $case, string $backend, float $median): array
{
	return ['case' => $case, 'backend' => $backend, 'median_us' => $median, 'mean_operation_us' => $median];
}

function fpm_fixture(array $backends, array $medians): array
{
	$results = [];
	foreach ($medians as $backend => $median) {
		$results[] = [
			'case' => 'case_a',
			'backend' => $backend,
			'median_server_us_per_op' => $median,
			'mean_server_us_per_op' => $median,
			'worker_count' => 1,
		];
	}

	return ['options' => ['backends' => $backends], 'results' => $results, 'failures' => []];
}

function ratio(float $median, array $interval): array
{
	return ['paired_ratio' => ['median' => $median], 'median_ratio_ci95' => $interval];
}

function render(string $root, array $args, string $output): string
{
	$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/render_user_cache_performance_report.php');
	foreach ($args as $arg) {
		$cmd .= ' ' . escapeshellarg($arg);
	}
	exec($cmd . ' --output ' . escapeshellarg($output) . ' 2>&1', $lines, $status);
	if ($status !== 0) {
		fail("Renderer failed:\n" . implode("\n", $lines));
	}
	$html = file_get_contents($output);
	if ($html === false) {
		fail('Unable to read report: ' . $output);
	}

	return $html;
}

function expect(string $html, array $needles, bool $present = true): void
{
	foreach ($needles as $needle) {
		if (str_contains($html, $needle) !== $present) {
			fail(($present ? 'Missing expected' : 'Unexpected') . ' report text: ' . $needle);
		}
	}
}

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/uc-report-summaries-' . getmypid();
if (!mkdir($tmp . '/site', 0777, true)) {
	fail('Unable to create temp directory: ' . $tmp);
}

try {
	write_json($tmp . '/cli-read.json', ['environment' => ['php_version' => PHP_VERSION], 'aggregation' => 'median across 3 runs', 'read' => [
		cli_row('case_a', 'user_cache', 1.0),
		cli_row('case_a', 'apcu_igbinary', 4.0),
		cli_row('case_b', 'user_cache', 2.0),
		cli_row('case_b', 'apcu_igbinary', 1.0),
	]]);
	write_json($tmp . '/fpm-once-php.json', fpm_fixture(['user_cache', 'apcu'], ['user_cache' => 3.0, 'apcu' => 4.0]));
	write_json($tmp . '/fpm-once-igbinary.json', fpm_fixture(['user_cache', 'apcu_igbinary'], ['user_cache' => 5.0, 'apcu_igbinary' => 10.0]));
	$bulkRows = [];
	foreach (['user_cache_fetch_multiple' => 1.0, 'user_cache_fetch_loop' => 2.0, 'apcu_igbinary_fetch_multiple' => 8.0, 'apcu_igbinary_fetch_loop' => 9.0] as $backend => $median) {
		$bulkRows[] = ['backend' => $backend, 'median_us_per_batch' => $median, 'mean_us_per_batch' => $median, 'mean_us_per_key' => $median / 32];
	}
	write_json($tmp . '/bulk-read-32.json', ['options' => ['key_count' => 32, 'operations' => 10], 'rows' => $bulkRows]);

	$shape = ['keys' => 1, 'temperature' => 'warm', 'mutate' => false, 'ttl' => 0];
	$noisyShape = ['keys' => 32768, 'temperature' => 'cold', 'mutate' => true, 'ttl' => 0];
	$persistence = [
		'schema_version' => 1,
		'status' => 'complete',
		'smoke_only' => false,
		'created_at' => '2026-09-23T05:53:15.273284+00:00',
		'configuration' => ['mode' => 'worker', 'pairs' => 15, 'sessions' => 2, 'rounds' => 5, 'seconds' => 5, 'cpus' => '0-3', 'payloads' => ['int', 'x</script>y']],
		'build_manifest' => ['inputs' => ['frankenphp_revision' => '51e6246e71f335b96ac22d7782f5b20392555109']],
		'micro' => [
			['payload' => 'x</script>y'] + $noisyShape + ratio(1.05, [1.01, 1.1]),
			['payload' => 'int'] + $shape + ratio(0.9, [0.85, 0.95]),
		],
		'aa' => [
			['payload' => 'x</script>y', 'pairs' => 15] + $noisyShape + ratio(1.05, [1.0, 1.1]),
			['payload' => 'int', 'pairs' => 15] + $shape + ratio(1.0, [0.99, 1.01]),
		],
		'http' => [
			['kind' => 'scalar', 'workers' => 2, 'operations' => 100, 'mixed' => false, 'paired_throughput_ratio' => ['median' => 1.2], 'median_ratio_ci95' => [1.1, 1.3]],
		],
	];
	/* Same escaping as render_persistence_report.py, so '</script>' in a label cannot end the element. */
	$embedded = json_encode($persistence, JSON_HEX_TAG | JSON_THROW_ON_ERROR);
	write_file($tmp . '/BENCH_RESULT_PERSISTENCE.html', '<!doctype html><html><body><script id="embedded-result" type="application/json">' . $embedded . '</script></body></html>');

	$html = render($root, [
		'--cli-read', $tmp . '/cli-read.json',
		'--fpm-once', $tmp . '/fpm-once-php.json',
		'--fpm-once', $tmp . '/fpm-once-igbinary.json',
		'--bulk', $tmp . '/bulk-read-32.json',
		'--persistence', $tmp . '/BENCH_RESULT_PERSISTENCE.html',
		'--cpus', '0-3',
	], $tmp . '/site/BENCH_RESULT.html');

	expect($html, [
		'UserCache vs APCu/igbinary',
		'<td class="num">1/2</td><td class="num winner-cell">1.41x</td>',
		'<td class="num winner-cell">2.00x</td>',
		'<td class="num winner-cell">8.00x</td>',
		'href="../BENCH_RESULT_PERSISTENCE.html">FrankenPHP persistence comparison</a>',
		'UserCache vs FrankenPHP zval.h reference',
		'measured 2026-09-23T05:53:15+00:00',
		'<code>x&lt;/script&gt;y</code>',
		'<td class="num winner-cell">0.900x',
		'<td class="num slower-cell">1.050x †',
		'<td class="num winner-cell">1.200x',
		'2 workers, 100 ops/request',
		'CPU affinity</th><td><code>0-3</code>',
		'median across 3 runs',
	]);
	/* UserCache from the php-serializer run must not be paired with APCu/igbinary. */
	expect($html, ['3.33x', 'is a smoke run'], false);

	$persistence['smoke_only'] = true;
	write_json($tmp . '/result.json', $persistence);
	$html = render($root, ['--cli-read', $tmp . '/cli-read.json', '--persistence', $tmp . '/result.json'], $tmp . '/site/json.html');
	expect($html, ['UserCache vs FrankenPHP zval.h reference', 'is a smoke run']);
	expect($html, ['FrankenPHP persistence comparison</a>'], false);
} finally {
	foreach (array_merge(glob($tmp . '/site/*') ?: [], glob($tmp . '/*') ?: []) as $path) {
		if (is_file($path)) {
			unlink($path);
		}
	}
	rmdir($tmp . '/site');
	rmdir($tmp);
}

echo "PASS\n";
