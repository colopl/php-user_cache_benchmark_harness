<?php

declare(strict_types=1);

/**
 * Aggregate multiple full-run results directories into a single directory
 * whose every metric is the median across runs. Point the combined report
 * renderer at the aggregated directory to publish run-to-run-robust numbers:
 * single FPM one-fetch runs swing by tens of percent, so per-run medians
 * alone are not stable enough to compare backends.
 *
 * Usage:
 *   php scripts/aggregate_results_median.php --output DIR RUN_DIR [RUN_DIR ...]
 *
 * Aggregation rules per (case, backend) row group:
 *   - median-like and mean-like metrics: median of the per-run values
 *   - min_*: minimum across runs, max_*: maximum across runs
 *   - samples arrays: replaced by the list of per-run medians
 *   - descriptive fields: copied from the first run
 */

function usage(): void
{
	fwrite(STDOUT, "Usage: php scripts/aggregate_results_median.php --output DIR RUN_DIR [RUN_DIR ...]\n");
}

function median_of(array $values): float
{
	sort($values);
	$count = count($values);
	if ($count === 0) {
		return 0.0;
	}
	$middle = intdiv($count, 2);

	return $count % 2 === 1 ? (float) $values[$middle] : ((float) $values[$middle - 1] + (float) $values[$middle]) / 2.0;
}

function load_json(string $path): array
{
	$data = json_decode((string) file_get_contents($path), true);
	if (!is_array($data)) {
		throw new RuntimeException('Unable to parse JSON: ' . $path);
	}

	return $data;
}

function first_glob(string $pattern): ?string
{
	$matches = glob($pattern);

	return $matches === false || $matches === [] ? null : $matches[0];
}

/**
 * Merge grouped rows: for every numeric field listed in $medianFields take the
 * median across runs, $minFields the minimum, $maxFields the maximum. The
 * samples field, when given, becomes the list of per-run $sampleSource values.
 */
function aggregate_rows(
	array $rowsPerRun,
	callable $groupKey,
	array $medianFields,
	array $minFields,
	array $maxFields,
	?string $samplesField,
	?string $sampleSource
): array {
	$groups = [];
	foreach ($rowsPerRun as $runIndex => $rows) {
		foreach ($rows as $row) {
			$groups[$groupKey($row)][$runIndex] = $row;
		}
	}

	$result = [];
	foreach ($groups as $rowsByRun) {
		$aggregated = $rowsByRun[array_key_first($rowsByRun)];
		foreach ($medianFields as $field) {
			$values = [];
			foreach ($rowsByRun as $row) {
				if (isset($row[$field]) && is_numeric($row[$field])) {
					$values[] = (float) $row[$field];
				}
			}
			if ($values !== []) {
				$aggregated[$field] = median_of($values);
			}
		}
		foreach ($minFields as $field) {
			$values = array_column($rowsByRun, $field);
			if ($values !== []) {
				$aggregated[$field] = min(array_map('floatval', $values));
			}
		}
		foreach ($maxFields as $field) {
			$values = array_column($rowsByRun, $field);
			if ($values !== []) {
				$aggregated[$field] = max(array_map('floatval', $values));
			}
		}
		if ($samplesField !== null && $sampleSource !== null) {
			$aggregated[$samplesField] = array_values(array_map(
				static fn (array $row): float => (float) ($row[$sampleSource] ?? 0.0),
				$rowsByRun
			));
			$aggregated['iterations'] = count($rowsByRun);
		}
		$result[] = $aggregated;
	}

	return $result;
}

/** Recompute the per-case ranking fields the CLI writer emits. */
function recompute_cli_ranks(array $rows): array
{
	$byCase = [];
	foreach ($rows as $index => $row) {
		$byCase[$row['mode'] . "\0" . $row['case']][] = $index;
	}

	foreach ($byCase as $indexes) {
		$medians = [];
		foreach ($indexes as $index) {
			$medians[$index] = (float) $rows[$index]['median_us'];
		}
		asort($medians);
		$bestIndex = array_key_first($medians);
		$bestValue = $medians[$bestIndex];
		$userValue = null;
		foreach ($indexes as $index) {
			if ($rows[$index]['backend'] === 'user_cache') {
				$userValue = (float) $rows[$index]['median_us'];
			}
		}
		$rank = 0;
		foreach ($medians as $index => $value) {
			$rank++;
			if (isset($rows[$index]['rank'])) {
				$rows[$index]['rank'] = $rank;
			}
			if (isset($rows[$index]['best_backend'])) {
				$rows[$index]['best_backend'] = $rows[$bestIndex]['backend'];
			}
			if (isset($rows[$index]['best_metric_us'])) {
				$rows[$index]['best_metric_us'] = $bestValue;
			}
			if (isset($rows[$index]['ratio_to_best']) && $bestValue > 0.0) {
				$rows[$index]['ratio_to_best'] = $value / $bestValue;
			}
			if (isset($rows[$index]['delta_vs_user_cache_percent']) && $userValue !== null && $userValue > 0.0) {
				$rows[$index]['delta_vs_user_cache_percent'] = ($value / $userValue - 1.0) * 100.0;
			}
		}
	}

	return $rows;
}

$output = null;
$runDirs = [];
for ($i = 1; $i < $argc; $i++) {
	if ($argv[$i] === '--output') {
		$output = $argv[++$i] ?? null;
	} elseif ($argv[$i] === '-h' || $argv[$i] === '--help') {
		usage();
		exit(0);
	} else {
		$runDirs[] = rtrim($argv[$i], '/');
	}
}

if ($output === null || count($runDirs) < 2) {
	usage();
	fwrite(STDERR, "error: --output and at least two run directories are required\n");
	exit(2);
}

foreach ($runDirs as $dir) {
	if (!is_dir($dir)) {
		fwrite(STDERR, "error: not a directory: $dir\n");
		exit(2);
	}
}

if (!is_dir($output) && !mkdir($output, 0777, true) && !is_dir($output)) {
	fwrite(STDERR, "error: unable to create $output\n");
	exit(1);
}

$stamp = ['aggregated_from' => $runDirs, 'aggregation' => 'median across ' . count($runDirs) . ' runs'];

/* CLI read / write */
foreach ([['cli-read', 'read'], ['cli-write', 'write']] as [$sub, $mode]) {
	$runs = [];
	$base = null;
	foreach ($runDirs as $dir) {
		$path = first_glob("$dir/$sub/user-cache-benchmark-*.json");
		if ($path === null) {
			continue;
		}
		$data = load_json($path);
		$base ??= $data;
		$runs[] = $data[$mode] ?? [];
	}
	if ($base === null || count($runs) < 2) {
		continue;
	}
	$base[$mode] = recompute_cli_ranks(aggregate_rows(
		$runs,
		static fn (array $row): string => $row['mode'] . "\0" . $row['case'] . "\0" . $row['backend'],
		['median_us', 'mean_operation_us', 'mean_store_us', 'stddev_us', 'operations_per_second',
			'value_shm_bytes', 'backend_shm_baseline_bytes', 'backend_shm_reserved_bytes',
			'store_retained_bytes', 'first_fetch_retained_bytes', 'fetch_retained_bytes', 'request_residual_bytes'],
		['min_us'],
		['max_us'],
		'samples_us',
		'median_us'
	));
	$base += $stamp;
	if (!is_dir("$output/$sub")) {
		mkdir("$output/$sub", 0777, true);
	}
	file_put_contents("$output/$sub/user-cache-benchmark-median.json", json_encode($base, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

/* FPM hot / once serializer variants */
foreach ([
	'fpm-read-hot-php.json',
	'fpm-read-hot-igbinary.json',
	'fpm-read-once-php.json',
	'fpm-read-once-igbinary.json',
] as $name) {
	$runs = [];
	$base = null;
	foreach ($runDirs as $dir) {
		if (!is_file("$dir/$name")) {
			continue;
		}
		$data = load_json("$dir/$name");
		$base ??= $data;
		$runs[] = $data['results'] ?? [];
	}
	if ($base === null || count($runs) < 2) {
		continue;
	}
	$base['results'] = aggregate_rows(
		$runs,
		static fn (array $row): string => $row['case'] . "\0" . $row['backend'],
		[
			'median_server_us_per_op',
			'mean_server_us_per_op',
			'p25_server_us_per_op',
			'p75_server_us_per_op',
			'p95_server_us_per_op',
			'mean_client_us_per_request',
			'median_worker_median_server_us_per_op',
		],
		['min_server_us_per_op'],
		['max_server_us_per_op'],
		null,
		null
	);
	$base += $stamp;
	file_put_contents("$output/$name", json_encode($base, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

/* Bulk reads */
foreach (['bulk-read-32.json', 'bulk-read-128.json'] as $name) {
	$runs = [];
	$base = null;
	foreach ($runDirs as $dir) {
		if (!is_file("$dir/$name")) {
			continue;
		}
		$data = load_json("$dir/$name");
		$base ??= $data;
		$runs[] = $data['rows'] ?? [];
	}
	if ($base === null || count($runs) < 2) {
		continue;
	}
	$base['rows'] = aggregate_rows(
		$runs,
		static fn (array $row): string => (string) $row['backend'],
		['median_us_per_batch', 'mean_us_per_batch', 'mean_us_per_key'],
		[],
		[],
		'samples_us_per_batch',
		'median_us_per_batch'
	);
	$base += $stamp;
	file_put_contents("$output/$name", json_encode($base, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

/* Resident probe */
$runs = [];
$base = null;
foreach ($runDirs as $dir) {
	if (!is_file("$dir/resident-payload-probe.json")) {
		continue;
	}
	$data = load_json("$dir/resident-payload-probe.json");
	$base ??= $data;
	$runs[] = $data['rows'] ?? [];
}
if ($base !== null && count($runs) >= 2) {
	$base['rows'] = aggregate_rows(
		$runs,
		static fn (array $row): string => $row['case'] . "\0" . $row['backend'],
		['median_us', 'mean_operation_us'],
		['min_us'],
		['max_us'],
		'samples_us',
		'median_us'
	);
	$base += $stamp;
	file_put_contents("$output/resident-payload-probe.json", json_encode($base, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

fwrite(STDOUT, "Aggregated " . count($runDirs) . " runs into $output\n");
