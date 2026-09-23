<?php

declare(strict_types=1);

require_once __DIR__ . '/BenchmarkComparison.php';

final class UcPerformanceReport
{
	private ?string $cliReadPath = null;
	private ?string $cliWritePath = null;
	private ?string $residentPath = null;
	private array $fpmOncePaths = [];
	private array $fpmHotPaths = [];
	private array $bulkPaths = [];
	private ?string $persistencePath = null;
	private ?string $cpus = null;
	private string $output;

	public function __construct()
	{
		$this->output = dirname(__DIR__) . '/BENCH_RESULT.html';
	}

	public function run(array $argv): int
	{
		$this->parse($argv);

		$cliRead = $this->cliReadPath !== null ? $this->readJson($this->cliReadPath) : null;
		$cliWrite = $this->cliWritePath !== null ? $this->readJson($this->cliWritePath) : null;
		$resident = $this->residentPath !== null ? $this->readJson($this->residentPath) : null;
		$fpmOnceRuns = $this->readFpmRuns($this->fpmOncePaths);
		$fpmHotRuns = $this->readFpmRuns($this->fpmHotPaths);
		$bulkRuns = array_map(fn (string $path): array => ['path' => $path, 'data' => $this->readJson($path)], $this->bulkPaths);
		$persistence = $this->persistencePath !== null ? $this->readPersistence($this->persistencePath) : null;

		if ($cliWrite === null && $cliRead !== null && ($cliRead['write'] ?? []) !== []) {
			$cliWrite = $cliRead;
		}

		if ($cliRead === null && $cliWrite === null && $resident === null && $fpmOnceRuns === [] && $fpmHotRuns === [] && $bulkRuns === []) {
			throw new RuntimeException('No benchmark result files were provided');
		}

		/* Links to related reports are relative to the resolved output directory. */
		$dir = dirname($this->output);
		if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
			throw new RuntimeException('Unable to create output directory: ' . $dir);
		}
		$html = $this->render($cliRead, $cliWrite, $resident, $fpmOnceRuns, $fpmHotRuns, $bulkRuns, $persistence);
		file_put_contents($this->output, $html);
		echo 'Wrote HTML report: ' . $this->output . "\n";

		return 0;
	}

	private function parse(array $argv): void
	{
		for ($i = 1; $i < count($argv); $i++) {
			$arg = $argv[$i];
			switch ($arg) {
				case '--cli-read':
					$this->cliReadPath = $this->absolutePath($this->value($argv, ++$i, $arg));
					break;
				case '--cli-write':
					$this->cliWritePath = $this->absolutePath($this->value($argv, ++$i, $arg));
					break;
				case '--resident':
					$this->residentPath = $this->absolutePath($this->value($argv, ++$i, $arg));
					break;
				case '--fpm-once':
					$this->fpmOncePaths[] = $this->absolutePath($this->value($argv, ++$i, $arg));
					break;
				case '--fpm-hot':
					$this->fpmHotPaths[] = $this->absolutePath($this->value($argv, ++$i, $arg));
					break;
				case '--bulk':
					$this->bulkPaths[] = $this->absolutePath($this->value($argv, ++$i, $arg));
					break;
				case '--persistence':
					$this->persistencePath = $this->absolutePath($this->value($argv, ++$i, $arg));
					break;
				case '--cpus':
					$this->cpus = $this->value($argv, ++$i, $arg);
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
		fwrite(STDOUT, "Usage: php scripts/render_user_cache_performance_report.php [--cli-read FILE] [--cli-write FILE] [--resident FILE] [--fpm-once FILE] [--fpm-hot FILE] [--bulk FILE]... [--persistence FILE] [--cpus LIST] [--output FILE]\n"
			. "  --persistence FILE   BENCH_RESULT_PERSISTENCE.html (embedded aggregate JSON) or its result.json; adds a FrankenPHP summary\n"
			. "  --cpus LIST          CPU set the measurements were pinned to\n");
	}

	private function readFpmRuns(array $paths): array
	{
		$runs = [];
		foreach ($paths as $index => $path) {
			$data = $this->readJson($path);
			$runs[] = [
				'path' => $path,
				'label' => $this->fpmRunLabel($data, $index),
				'data' => $data,
			];
		}

		return $runs;
	}

	private function render(?array $cliRead, ?array $cliWrite, ?array $resident, array $fpmOnceRuns, array $fpmHotRuns, array $bulkRuns, ?array $persistence): string
	{
		$cards = [];
		if ($cliRead !== null) {
			$cards[] = $this->winnerCard('CLI repeated read', $cliRead['read'] ?? [], 'median_us');
		}
		if ($cliWrite !== null && ($cliWrite['write'] ?? []) !== []) {
			$cards[] = $this->winnerCard('CLI store', $cliWrite['write'] ?? [], 'median_us');
		}
		foreach ($fpmOnceRuns as $run) {
			$cards[] = $this->winnerCard('FPM one fetch/request (' . $run['label'] . ')', $run['data']['results'] ?? [], 'median_server_us_per_op');
		}
		foreach ($fpmHotRuns as $run) {
			$cards[] = $this->winnerCard('FPM hot read (' . $run['label'] . ')', $run['data']['results'] ?? [], 'median_server_us_per_op');
		}
		return '<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>UserCache\Cache Performance Report</title>
<style>
:root {
  color-scheme: light;
  --ink: #17202a;
  --muted: #5f6b7a;
  --line: #d8e0e7;
  --panel: #f7f9fb;
  --accent: #13715f;
  --accent-soft: #dcefe9;
  --warn: #8c5a00;
  --warn-soft: #fff2d8;
}
body {
  margin: 0;
  color: var(--ink);
  background: #fff;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
}
main {
  max-width: 1220px;
  margin: 0 auto;
  padding: 28px 20px 52px;
}
h1 {
  margin: 0 0 8px;
  font-size: 30px;
  line-height: 1.2;
  letter-spacing: 0;
}
h2 {
  margin: 30px 0 10px;
  font-size: 19px;
  letter-spacing: 0;
}
h3 {
  margin: 16px 0 6px;
  font-size: 15px;
  letter-spacing: 0;
}
p {
  color: var(--muted);
  line-height: 1.55;
}
.cards {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
  gap: 12px;
  margin: 18px 0;
}
.card {
  border: 1px solid var(--line);
  border-radius: 8px;
  background: var(--panel);
  padding: 12px 14px;
}
.card strong {
  display: block;
  margin-top: 4px;
  font-size: 22px;
  line-height: 1.25;
  overflow-wrap: anywhere;
  word-break: break-word;
}
.ratio-list {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 4px 12px;
  margin: 10px 0 0;
  font-variant-numeric: tabular-nums;
}
.ratio-list dt,
.ratio-list dd {
  margin: 0;
}
.ratio-list dt {
  color: var(--muted);
}
.ratio-list dd {
  text-align: right;
  font-weight: 650;
}
.note {
  border: 1px solid var(--line);
  border-radius: 8px;
  background: var(--panel);
  padding: 10px 12px;
}
.warn {
  border-color: #edc36a;
  background: var(--warn-soft);
  color: var(--warn);
}
table {
  width: 100%;
  border-collapse: collapse;
  margin: 8px 0 20px;
  font-size: 14px;
}
.bulk-table {
  table-layout: fixed;
}
.bulk-table th:first-child {
  width: 42%;
}
th, td {
  border-bottom: 1px solid var(--line);
  padding: 8px 10px;
  text-align: left;
  vertical-align: top;
}
th {
  background: var(--panel);
  font-weight: 650;
}
td.num, th.num {
  text-align: right;
  font-variant-numeric: tabular-nums;
}
td.winner-cell { background: var(--accent-soft); }
td.slower-cell { background: var(--warn-soft); }
.ranking { display: block; white-space: nowrap; }
.memory-best { background: var(--accent-soft); font-weight: 700; }
.winner {
  color: var(--accent);
  font-weight: 700;
}
.muted {
  color: var(--muted);
}
.small {
  display: block;
  margin-top: 2px;
  color: var(--muted);
  font-size: 12px;
}
.note-link {
  display: block;
  margin-top: 4px;
  font-size: 12px;
}
.workload-link {
  color: inherit;
  text-decoration: none;
}
.workload-link:hover,
.workload-link:focus {
  text-decoration: underline;
}
code {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 0.94em;
  overflow-wrap: anywhere;
  word-break: break-word;
}
</style>
</head>
<body>
<main>
<h1>UserCache\Cache Performance Report</h1>
<p>Generated at <code>' . self::h(gmdate(DATE_ATOM)) . '</code>. Times are in microseconds; lower is faster. Faster lists every measured backend in ascending time order: 1.00x is fastest, and each other ratio is its time divided by the fastest time. Green cells mark the fastest measurements, including ties.</p>
' . $this->relatedReports() . '
<div class="cards">' . implode('', $cards) . '</div>
' . $this->headToHeadSection('apcu_igbinary', $cliRead, $cliWrite, $fpmOnceRuns, $fpmHotRuns, $bulkRuns) . '
' . $this->persistenceSection($persistence) . '
' . $this->environmentSection($cliRead, $fpmOnceRuns, $fpmHotRuns) . '
' . ($cliRead !== null ? $this->cacheReadTable('CLI Repeated Read', $cliRead['read'] ?? [], 'median_us', 'mean_operation_us', false, null, 'mean') : '') . '
' . ($cliWrite !== null ? $this->cacheReadTable('CLI Store', $cliWrite['write'] ?? [], 'median_us', 'mean_store_us', false, 'store-tradeoff-note', 'mean') : '') . '
' . $this->fpmTables('FPM One Fetch Per Request', $fpmOnceRuns) . '
' . $this->fpmTables('FPM Hot Read', $fpmHotRuns) . '
' . UcBenchComparison::memoryTable($cliRead['read'] ?? [], $this->backendLabel(...)) . '
' . $this->residentTable($resident, $cliRead) . '
' . $this->bulkTables($bulkRuns) . '
' . $this->artifactTable() . '
' . $this->workloadsSection($cliRead, $cliWrite, $resident, $fpmOnceRuns, $fpmHotRuns, $bulkRuns) . '
' . $this->notesSection() . '
</main>
</body>
</html>
';
	}

	private function environmentSection(?array $cliRead, array $fpmOnceRuns, array $fpmHotRuns): string
	{
		$environment = $cliRead['environment']
			?? $fpmOnceRuns[0]['data']['environment']
			?? $fpmHotRuns[0]['data']['environment']
			?? null;
		if (!is_array($environment)) {
			return '';
		}

		$ini = is_array($environment['ini'] ?? null) ? $environment['ini'] : [];
		$extensions = is_array($environment['loaded_extensions'] ?? null) ? $environment['loaded_extensions'] : [];
		$threadSafety = array_key_exists('php_zts', $environment)
			? (!empty($environment['php_zts']) ? 'ZTS' : 'NTS')
			: (PHP_ZTS ? 'ZTS' : 'NTS');
		$loaded = [];
		foreach ($extensions as $name => $value) {
			if (is_bool($value)) {
				/* Legacy shape: extension name => enabled flag. */
				if ($value) {
					$loaded[] = (string) $name;
				}
			} else {
				/* Current shape: flat list of loaded extension names. */
				$loaded[] = (string) $value;
			}
		}

		$rows = [
			'PHP' => (string) ($environment['php_version'] ?? '') . ' (' . (string) ($environment['php_sapi'] ?? '') . ')',
			'Thread Safety' => $threadSafety,
			'Binary' => (string) ($environment['php_binary'] ?? ''),
			'System' => (string) ($environment['uname'] ?? ''),
			'user_cache.shm_size' => (string) ($ini['user_cache.shm_size'] ?? ''),
			'Loaded extensions' => $loaded !== [] ? implode(', ', $loaded) : 'none',
		];
		if (isset($cliRead['aggregation'])) {
			$rows['Aggregation'] = (string) $cliRead['aggregation'];
		}
		if ($this->cpus !== null) {
			$rows['CPU affinity'] = $this->cpus;
		}

		foreach (['apc.shm_size', 'yac.serializer', 'yac.compress_threshold', 'yac.keys_memory_size', 'yac.values_memory_size'] as $key) {
			if (isset($ini[$key])) {
				$rows[$key] = (string) $ini[$key];
			}
		}

		$html = '<h2>Environment</h2><table><tbody>';
		foreach ($rows as $key => $value) {
			$html .= '<tr><th>' . self::h($key) . '</th><td><code>' . self::h($value) . '</code></td></tr>';
		}

		return $html . '</tbody></table>';
	}

	private function relatedReports(): string
	{
		if ($this->persistencePath === null || !$this->isHtml($this->persistencePath)) {
			return '';
		}

		return '<p class="note">Related report: <a href="' . self::h($this->relativeHref($this->persistencePath)) . '">FrankenPHP persistence comparison</a></p>';
	}

	private function headToHeadSection(string $backend, ?array $cliRead, ?array $cliWrite, array $fpmOnceRuns, array $fpmHotRuns, array $bulkRuns): string
	{
		$sections = [
			'CLI repeated read' => $this->speedups($cliRead['read'] ?? [], 'median_us', $backend),
			'FPM one fetch/request' => $this->fpmSpeedups($fpmOnceRuns, $backend),
			'FPM hot read' => $this->fpmSpeedups($fpmHotRuns, $backend),
			'CLI store' => $this->speedups($cliWrite['write'] ?? [], 'median_us', $backend),
		];
		$sections = array_filter($sections, static fn (array $speedups): bool => $speedups !== []);
		$bulk = $this->bulkHeadToHeadRows($bulkRuns, $backend);
		if ($sections === [] && $bulk === '') {
			return '';
		}

		$label = $this->backendLabel($backend);
		$html = '<h2 id="summary-' . self::h(str_replace('_', '-', $backend)) . '">UserCache vs ' . self::h($label) . '</h2>'
			. '<p>Speedup is the ' . self::h($label) . ' median divided by the UserCache median from the same measurement session; above 1.00x UserCache is faster. FPM rows pair UserCache with the run that used the same APCu serializer. The geometric mean weighs every workload equally.</p>';
		if ($sections !== []) {
			$html .= '<table><thead><tr><th>Section</th><th class="num">Workloads</th><th class="num">UserCache faster</th><th class="num">Geomean speedup</th><th class="num">Lowest</th><th class="num">Highest</th></tr></thead><tbody>';
			foreach ($sections as $title => $speedups) {
				$faster = count(array_filter($speedups, static fn (float $speedup): bool => $speedup > 1.0));
				$lowest = array_keys($speedups, min($speedups), true)[0];
				$highest = array_keys($speedups, max($speedups), true)[0];
				$note = $title === 'CLI store' ? '<a class="note-link" href="#store-tradeoff-note">store trade-off note</a>' : '';
				$html .= '<tr><td>' . self::h($title) . $note . '</td>'
					. '<td class="num">' . count($speedups) . '</td>'
					. '<td class="num">' . $faster . '/' . count($speedups) . '</td>'
					. $this->speedupCell($this->geomean($speedups))
					. '<td class="num">' . self::h($this->speedup($speedups[$lowest])) . '<span class="small">' . $this->ident((string) $lowest) . '</span></td>'
					. '<td class="num">' . self::h($this->speedup($speedups[$highest])) . '<span class="small">' . $this->ident((string) $highest) . '</span></td></tr>';
			}
			$html .= '</tbody></table>';

			$cases = [];
			foreach ($sections as $speedups) {
				$cases += array_fill_keys(array_keys($speedups), true);
			}
			$html .= '<table><thead><tr><th>Workload</th>';
			foreach (array_keys($sections) as $title) {
				$html .= '<th class="num">' . self::h($title) . '</th>';
			}
			$html .= '</tr></thead><tbody>';
			foreach (array_keys($cases) as $case) {
				$html .= '<tr><td>' . $this->workloadLink((string) $case) . '</td>';
				foreach ($sections as $speedups) {
					$html .= isset($speedups[$case]) ? $this->speedupCell($speedups[$case]) : '<td class="num"><span class="muted">n/a</span></td>';
				}
				$html .= '</tr>';
			}
			$html .= '</tbody></table>';
		}

		return $html . $bulk;
	}

	private function speedups(array $rows, string $metric, string $backend): array
	{
		$speedups = [];
		foreach ($this->groupRows($rows) as $case => $caseRows) {
			$userValue = (float) ($caseRows['user_cache'][$metric] ?? 0.0);
			$backendValue = (float) ($caseRows[$backend][$metric] ?? 0.0);
			if ($userValue > 0.0 && $backendValue > 0.0) {
				$speedups[$case] = $backendValue / $userValue;
			}
		}

		return $speedups;
	}

	private function fpmSpeedups(array $runs, string $backend): array
	{
		$speedups = [];
		foreach ($runs as $run) {
			$speedups += $this->speedups($run['data']['results'] ?? [], 'median_server_us_per_op', $backend);
		}

		return $speedups;
	}

	private function bulkHeadToHeadRows(array $bulkRuns, string $backend): string
	{
		$columns = [
			'user_cache_fetch_multiple',
			'user_cache_fetch_loop',
			$backend . '_fetch_multiple',
			$backend . '_fetch_loop',
		];
		$rows = '';
		foreach ($bulkRuns as $bulkRun) {
			$values = [];
			foreach ($bulkRun['data']['rows'] ?? [] as $row) {
				$values[(string) $row['backend']] = (float) $row['median_us_per_batch'];
			}
			if (!isset($values[$columns[0]], $values[$columns[2]]) || $values[$columns[0]] <= 0.0) {
				continue;
			}
			$rows .= '<tr><td>' . self::h((string) ($bulkRun['data']['options']['key_count'] ?? '?')) . '</td>';
			foreach ($columns as $column) {
				$rows .= '<td class="num">' . (isset($values[$column]) ? self::h($this->number($values[$column], 3)) . ' us' : '<span class="muted">n/a</span>') . '</td>';
			}
			$rows .= $this->speedupCell($values[$columns[2]] / $values[$columns[0]]) . '</tr>';
		}
		if ($rows === '') {
			return '';
		}

		$html = '<h3>Bulk read, median per batch</h3><table><thead><tr><th>Keys</th>';
		foreach ($columns as $column) {
			$html .= '<th class="num">' . self::h($this->backendLabel($column)) . '</th>';
		}

		return $html . '<th class="num">Speedup (array vs array)</th></tr></thead><tbody>' . $rows . '</tbody></table>';
	}

	private function persistenceSection(?array $data): string
	{
		if ($data === null) {
			return '';
		}

		$config = is_array($data['configuration'] ?? null) ? $data['configuration'] : [];
		$aaRatios = [];
		foreach ($data['aa'] as $row) {
			$aaRatios[$this->persistenceCaseKey($row)] = (float) $row['paired_ratio']['median'];
		}
		$pairs = (string) ($config['pairs'] ?? '?') . ' pairs × ' . (string) ($config['sessions'] ?? '?') . ' sessions';
		$conditions = [
			'Mode ' . (string) ($config['mode'] ?? 'worker'),
			'micro ' . $pairs,
			'A/A ' . (string) ($data['aa'][0]['pairs'] ?? '?') . ' pairs',
			'HTTP ' . (string) ($config['rounds'] ?? '?') . ' rounds × ' . (string) ($config['seconds'] ?? '?') . ' s',
			'CPUs ' . (string) ($config['cpus'] ?? 'all'),
			'FrankenPHP ' . substr((string) ($data['build_manifest']['inputs']['frankenphp_revision'] ?? '?'), 0, 12),
			'measured ' . preg_replace('/\.\d+/', '', (string) ($data['created_at'] ?? '?')),
		];

		$html = '<h2 id="summary-frankenphp">UserCache vs FrankenPHP zval.h reference</h2>';
		if ($data['smoke_only'] !== false || $data['status'] !== 'complete') {
			$html .= '<p class="note warn">This persistence result is ' . ($data['smoke_only'] !== false ? 'a smoke run' : 'incomplete') . ' and is not performance evidence.</p>';
		}
		$html .= '<p>PHP fetch batches compare UserCache with a reference cache built on the official FrankenPHP zval.h helpers in persistent worker threads. Micro ratios are UserCache time divided by reference time (below 1.00x UserCache is faster); HTTP ratios are UserCache throughput divided by reference throughput (above 1.00x UserCache is faster). Each ratio is the median of matched pairs; a cell is colored only when its bootstrap 95% interval excludes 1.00x.'
			. ($this->persistencePath !== null && $this->isHtml($this->persistencePath) ? ' Per-case timings, memory, A/A noise and provenance are in the <a href="' . self::h($this->relativeHref($this->persistencePath)) . '">FrankenPHP persistence report</a>.' : '')
			. '</p><p class="note"><code>' . self::h(implode(' · ', $conditions)) . '</code></p>';

		$microGroups = [
			'All PHP fetch batches' => $data['micro'],
			'Read' => array_filter($data['micro'], static fn (array $row): bool => !$row['mutate']),
			'Fetch and mutate' => array_filter($data['micro'], static fn (array $row): bool => (bool) $row['mutate']),
		];
		$html .= '<table><thead><tr><th>Cases</th><th class="num">Count</th><th class="num">UserCache faster</th><th class="num">Inconclusive</th><th class="num">Reference faster</th><th class="num">Geomean UserCache / reference</th></tr></thead><tbody>';
		foreach ($microGroups as $title => $rows) {
			$html .= $this->persistenceSummaryRow($title, $rows, 'paired_ratio', true);
		}
		$html .= $this->persistenceSummaryRow('HTTP worker scaling (throughput)', $data['http'], 'paired_throughput_ratio', false);
		$html .= '</tbody></table>';
		if ($aaRatios !== []) {
			$noisy = array_filter($aaRatios, static fn (float $ratio): bool => abs($ratio - 1.0) > 0.02);
			$html .= '<p>A/A (the reference compared with itself) medians range from ' . self::h($this->number(min($aaRatios), 3) . 'x to ' . $this->number(max($aaRatios), 3) . 'x')
				. ($noisy !== [] ? '; cases marked † exceed ±2% and their ratios carry that much noise.' : '.') . '</p>';
		}

		return $html . $this->persistenceMicroMatrix($data['micro'], $aaRatios, is_array($config['payloads'] ?? null) ? $config['payloads'] : []) . $this->persistenceHttpMatrix($data['http']);
	}

	private function persistenceSummaryRow(string $title, array $rows, string $ratioKey, bool $lowerIsBetter): string
	{
		if ($rows === []) {
			return '';
		}

		$counts = ['user_cache' => 0, 'inconclusive' => 0, 'reference' => 0];
		$ratios = [];
		foreach ($rows as $row) {
			$ratios[] = (float) $row[$ratioKey]['median'];
			$counts[$this->persistenceWinner($row['median_ratio_ci95'] ?? null, $lowerIsBetter)]++;
		}
		$geomean = $this->geomean($ratios);
		$better = $lowerIsBetter ? $geomean < 1.0 : $geomean > 1.0;

		return '<tr><td>' . self::h($title) . '</td>'
			. '<td class="num">' . count($rows) . '</td>'
			. '<td class="num">' . $counts['user_cache'] . '</td>'
			. '<td class="num">' . $counts['inconclusive'] . '</td>'
			. '<td class="num">' . $counts['reference'] . '</td>'
			. '<td class="num' . ($better ? ' winner-cell' : '') . '">' . self::h($this->number($geomean, 3)) . 'x</td></tr>';
	}

	private function persistenceWinner(?array $interval, bool $lowerIsBetter): string
	{
		if ($interval === null || count($interval) !== 2) {
			return 'inconclusive';
		}
		if ((float) $interval[1] < 1.0) {
			return $lowerIsBetter ? 'user_cache' : 'reference';
		}
		if ((float) $interval[0] > 1.0) {
			return $lowerIsBetter ? 'reference' : 'user_cache';
		}

		return 'inconclusive';
	}

	private function persistenceMicroMatrix(array $rows, array $aaRatios, array $payloadOrder): string
	{
		$shapes = [];
		$payloads = array_fill_keys(array_map('strval', $payloadOrder), []);
		foreach ($rows as $row) {
			$shape = [(int) $row['keys'], $row['temperature'] === 'warm' ? 0 : 1, (int) (bool) $row['mutate'], (int) $row['ttl']];
			$shapes[implode('|', $shape)] = $shape;
			$payloads[(string) $row['payload']][implode('|', $shape)] = $row;
		}
		$payloads = array_filter($payloads, static fn (array $cells): bool => $cells !== []);
		uasort($shapes, static fn (array $a, array $b): int => $a <=> $b);

		$html = '<h3>PHP fetch batches, UserCache / reference time</h3><table><thead><tr><th>Payload</th>';
		foreach ($shapes as $shape) {
			$html .= '<th class="num">' . self::h($this->number($shape[0], 0) . ($shape[0] === 1 ? ' key' : ' keys') . ', ' . ($shape[1] === 0 ? 'warm' : 'cold') . ', ' . ($shape[2] === 1 ? 'mutate' : 'read') . ($shape[3] > 0 ? ', TTL ' . $shape[3] : '')) . '</th>';
		}
		$html .= '</tr></thead><tbody>';
		foreach ($payloads as $payload => $cells) {
			$html .= '<tr><td><code>' . self::h($payload) . '</code></td>';
			foreach (array_keys($shapes) as $shapeKey) {
				if (!isset($cells[$shapeKey])) {
					$html .= '<td class="num"><span class="muted">-</span></td>';
					continue;
				}
				$row = $cells[$shapeKey];
				$noise = $aaRatios[$this->persistenceCaseKey($row)] ?? null;
				$html .= $this->persistenceRatioCell($row['paired_ratio']['median'], $row['median_ratio_ci95'] ?? null, true, $noise !== null && abs($noise - 1.0) > 0.02);
			}
			$html .= '</tr>';
		}

		return $html . '</tbody></table>';
	}

	private function persistenceHttpMatrix(array $rows): string
	{
		if ($rows === []) {
			return '';
		}

		$shapes = [];
		$kinds = [];
		foreach ($rows as $row) {
			$shape = [(int) $row['workers'], (int) $row['operations']];
			$shapes[implode('|', $shape)] = $shape;
			$kinds[(string) $row['kind'] . ' / ' . ($row['mixed'] ? '99% read + 1% store' : 'read')][implode('|', $shape)] = $row;
		}
		uasort($shapes, static fn (array $a, array $b): int => $a <=> $b);

		$html = '<h3>HTTP worker scaling, UserCache / reference throughput</h3>'
			. '<p>The Go HTTP client and FrankenPHP workers share the pinned CPU set, so intervals widen as workers grow.</p>'
			. '<table><thead><tr><th>Fixture / mix</th>';
		foreach ($shapes as $shape) {
			$html .= '<th class="num">' . self::h($shape[0] . ($shape[0] === 1 ? ' worker' : ' workers') . ', ' . $shape[1] . ($shape[1] === 1 ? ' op/request' : ' ops/request')) . '</th>';
		}
		$html .= '</tr></thead><tbody>';
		foreach ($kinds as $kind => $cells) {
			$html .= '<tr><td>' . self::h($kind) . '</td>';
			foreach (array_keys($shapes) as $shapeKey) {
				$html .= isset($cells[$shapeKey])
					? $this->persistenceRatioCell($cells[$shapeKey]['paired_throughput_ratio']['median'], $cells[$shapeKey]['median_ratio_ci95'] ?? null, false, false)
					: '<td class="num"><span class="muted">-</span></td>';
			}
			$html .= '</tr>';
		}

		return $html . '</tbody></table>';
	}

	private function persistenceRatioCell(float|int $ratio, ?array $interval, bool $lowerIsBetter, bool $noisy): string
	{
		$class = match ($this->persistenceWinner($interval, $lowerIsBetter)) {
			'user_cache' => ' winner-cell',
			'reference' => ' slower-cell',
			default => '',
		};
		$range = $interval !== null && count($interval) === 2
			? '<span class="small">' . self::h($this->number((float) $interval[0], 3) . '–' . $this->number((float) $interval[1], 3)) . '</span>'
			: '';

		return '<td class="num' . $class . '">' . self::h($this->number((float) $ratio, 3) . 'x' . ($noisy ? ' †' : '')) . $range . '</td>';
	}

	private function persistenceCaseKey(array $row): string
	{
		return implode('|', [(string) $row['payload'], (string) $row['keys'], (string) $row['temperature'], $row['mutate'] ? '1' : '0', (string) $row['ttl']]);
	}

	private function speedupCell(float $speedup): string
	{
		$class = $speedup > 1.0 ? ' winner-cell' : ($speedup < 1.0 ? ' slower-cell' : '');

		return '<td class="num' . $class . '">' . self::h($this->speedup($speedup)) . '</td>';
	}

	private function winnerCard(string $title, array $rows, string $metric): string
	{
		$groups = $this->groupRows($rows);
		$total = count($groups);
		$userWins = 0;
		foreach ($groups as $caseRows) {
			$best = $this->bestBackend($caseRows, $metric);
			if ($best === 'user_cache') {
				$userWins++;
			}
		}

		return '<div class="card">' . self::h($title)
			. '<strong>' . self::h((string) $userWins) . '/' . self::h((string) $total) . ' UserCache wins</strong>'
			. $this->backendRatioList($groups, $rows, $metric)
			. '</div>';
	}

	private function backendRatioList(array $groups, array $rows, string $metric): string
	{
		$ratios = [
			'user_cache' => [
				'ratio' => 1.0,
				'count' => count(array_filter($groups, static fn (array $caseRows): bool => isset($caseRows['user_cache'][$metric]))),
			],
		];

		foreach ($this->backendOrderForRows($rows) as $backendName) {
			if ($backendName === 'user_cache') {
				continue;
			}

			$values = [];
			foreach ($groups as $caseRows) {
				if (!isset($caseRows['user_cache'][$metric], $caseRows[$backendName][$metric])) {
					continue;
				}

				$userValue = (float) $caseRows['user_cache'][$metric];
				$backendValue = (float) $caseRows[$backendName][$metric];
				if ($userValue > 0.0 && $backendValue > 0.0) {
					$values[] = $userValue / $backendValue;
				}
			}

			if ($values !== []) {
				$ratios[$backendName] = [
					'ratio' => $this->median($values),
					'count' => count($values),
				];
			}
		}

		$userRatio = ['user_cache' => $ratios['user_cache']];
		unset($ratios['user_cache']);
		uksort($ratios, function (string $a, string $b) use ($ratios): int {
			$ratioCompare = $ratios[$b]['ratio'] <=> $ratios[$a]['ratio'];
			if ($ratioCompare !== 0) {
				return $ratioCompare;
			}

			return $this->backendLabel($a) <=> $this->backendLabel($b);
		});

		$html = '<dl class="ratio-list">';
		foreach ($userRatio + $ratios as $backendName => $ratio) {
			$count = (int) $ratio['count'];
			$countSuffix = $count > 0 && $count !== count($groups) ? ' / ' . $count . ' cases' : '';
			$html .= '<dt>' . self::h($this->backendLabel($backendName)) . '</dt>'
				. '<dd>' . self::h($this->number((float) $ratio['ratio'], 2) . 'x' . $countSuffix) . '</dd>';
		}

		return $html . '</dl>';
	}

	private function cacheReadTable(string $title, array $rows, string $metric, string $secondaryMetric, bool $showWorkers = false, ?string $apcuLossAnchor = null, string $secondaryLabel = 'median'): string
	{
		$groups = $this->groupRows($rows);
		if ($groups === []) {
			return '<h2>' . self::h($title) . '</h2><p class="note">No rows measured.</p>';
		}

		$backendNames = $this->backendOrderForRows($rows);
		$html = '<h2>' . self::h($title) . '</h2><table><thead><tr><th>Workload</th>';
		foreach ($backendNames as $backendName) {
			$html .= '<th class="num">' . self::h($this->backendLabel($backendName)) . '</th>';
		}
		$html .= '<th class="num">Faster</th>'
			. ($showWorkers ? '<th class="num">Workers</th>' : '')
			. '</tr></thead><tbody>';

		foreach ($groups as $case => $caseRows) {
			$bestBackend = $this->bestBackend($caseRows, $metric);
			$workers = isset($caseRows['user_cache']['worker_count']) ? (string) $caseRows['user_cache']['worker_count'] : '';
			$bestValue = $bestBackend !== null && isset($caseRows[$bestBackend][$metric]) ? (float) $caseRows[$bestBackend][$metric] : null;
			$noteLink = $apcuLossAnchor !== null && $bestBackend !== null && $bestBackend !== 'user_cache'
				? '<a class="note-link" href="#' . self::h($apcuLossAnchor) . '">store trade-off note</a>'
				: '';
			$html .= '<tr><td>' . $this->workloadLink($case) . $noteLink . '</td>';
			foreach ($backendNames as $backendName) {
				$html .= $this->metricCell($caseRows[$backendName] ?? null, $metric, $secondaryMetric, $secondaryLabel, isset($caseRows[$backendName][$metric]) && $bestValue !== null && (float) $caseRows[$backendName][$metric] === $bestValue);
			}
			$html .= '<td class="num">' . UcBenchComparison::fasterCell($caseRows, $metric, $this->backendLabel(...)) . '</td>'
				. ($showWorkers ? '<td class="num">' . self::h($workers) . '</td>' : '')
				. '</tr>';
		}

		return $html . '</tbody></table>';
	}

	private function metricCell(?array $row, string $metric, string $secondaryMetric, string $secondaryLabel, bool $winner): string
	{
		if ($row === null || !isset($row[$metric])) {
			return '<td class="num"><span class="muted">n/a</span></td>';
		}

		$class = $winner ? ' class="winner"' : '';
		$secondary = isset($row[$secondaryMetric]) ? (float) $row[$secondaryMetric] : null;
		$interquartile = isset($row['p25_server_us_per_op'], $row['p75_server_us_per_op'])
			? 'p25-p75 ' . $this->number((float) $row['p25_server_us_per_op'], 3) . '-' . $this->number((float) $row['p75_server_us_per_op'], 3)
			: null;

		return '<td class="num' . ($winner ? ' winner-cell' : '') . '"><span' . $class . '>' . self::h($this->number((float) $row[$metric], 3)) . ' us</span>'
			. ($secondary !== null ? '<span class="small">' . self::h($secondaryLabel . ' ' . $this->number($secondary, 3)) . '</span>' : '')
			. ($interquartile !== null ? '<span class="small">' . self::h($interquartile) . '</span>' : '')
			. '</td>';
	}

	private function residentTable(?array $resident, ?array $cliRead): string
	{
		if ($resident === null) {
			return '';
		}

		$cliRows = $cliRead !== null ? $this->groupRows($cliRead['read'] ?? []) : [];
		$html = '<h2>Resident Payload Probe</h2><table><thead><tr><th>Workload</th><th class="num">Resident direct access</th><th class="num">UserCache fetch + access</th><th class="num">Estimated fetch overhead</th><th class="num">Resident / UserCache</th></tr></thead><tbody>';
		foreach (($resident['rows'] ?? []) as $row) {
			$case = (string) $row['case'];
			$residentUs = (float) $row['median_us'];
			$userUs = isset($cliRows[$case]['user_cache']) ? (float) $cliRows[$case]['user_cache']['median_us'] : null;
			$ratio = $userUs !== null && $userUs > 0.0 ? $residentUs / $userUs : null;
			$overhead = $userUs !== null ? $userUs - $residentUs : null;
			$noteLink = $ratio !== null && $ratio < 1.0
				? '<a class="note-link" href="#resident-baseline-note">resident baseline note</a>'
				: '';
			$html .= '<tr><td>' . $this->workloadLink($case) . $noteLink . '</td>'
				. '<td class="num">' . self::h($this->number($residentUs, 3)) . ' us</td>'
				. '<td class="num">' . ($userUs !== null ? self::h($this->number($userUs, 3) . ' us') : '<span class="muted">n/a</span>') . '</td>'
				. '<td class="num">' . ($overhead !== null ? self::h($this->number($overhead, 3) . ' us') : '<span class="muted">n/a</span>') . '</td>'
				. '<td class="num">' . ($ratio !== null ? self::h($this->number($ratio, 2) . 'x') : '<span class="muted">n/a</span>') . '</td></tr>';
		}

		return $html . '</tbody></table>';
	}

	private function fpmTables(string $title, array $runs): string
	{
		$html = '';
		foreach ($runs as $run) {
			$html .= $this->cacheReadTable(
				$title . ' (' . $run['label'] . ')',
				$run['data']['results'] ?? [],
				'median_server_us_per_op',
				'mean_server_us_per_op',
				true,
				null,
				'mean'
			);
		}

		return $html;
	}

	private function bulkTables(array $bulkRuns): string
	{
		$html = '';
		foreach ($bulkRuns as $bulkRun) {
			$data = $bulkRun['data'];
			$keyCount = (string) ($data['options']['key_count'] ?? '?');
			$html .= '<h2><a class="workload-link" href="#' . self::h($this->bulkWorkloadId($keyCount)) . '">Bulk Read: ' . self::h($keyCount) . ' Keys</a></h2><table class="bulk-table"><thead><tr><th>Backend</th><th class="num">Median/batch</th><th class="num">Mean/batch</th><th class="num">Mean/key</th></tr></thead><tbody>';
			$rows = $data['rows'] ?? [];
			$values = UcBenchComparison::metricValues($rows, 'median_us_per_batch');
			foreach ($rows as $row) {
				$winner = $values !== [] && (float) $row['median_us_per_batch'] === reset($values);
				$html .= '<tr><td><code>' . $this->ident((string) $row['backend']) . '</code><span class="small">' . self::h($this->backendLabel((string) $row['backend'])) . '</span></td>'
					. '<td class="num' . ($winner ? ' winner winner-cell' : '') . '">' . self::h($this->number((float) $row['median_us_per_batch'], 3)) . ' us</td>'
					. '<td class="num">' . self::h($this->number((float) $row['mean_us_per_batch'], 3)) . ' us</td>'
					. '<td class="num">' . self::h($this->number((float) $row['mean_us_per_key'], 3)) . ' us</td></tr>';
			}
			$html .= '</tbody></table>';
		}

		return $html;
	}

	private function artifactTable(): string
	{
		$paths = [
			'CLI repeated read JSON' => $this->cliReadPath,
			'CLI write JSON' => $this->cliWritePath,
			'Resident probe JSON' => $this->residentPath,
		];
		foreach ($this->fpmOncePaths as $index => $path) {
			$paths['FPM one fetch/request JSON #' . ($index + 1)] = $path;
		}
		foreach ($this->fpmHotPaths as $index => $path) {
			$paths['FPM hot read JSON #' . ($index + 1)] = $path;
		}
		foreach ($this->bulkPaths as $index => $path) {
			$paths['Bulk read JSON #' . ($index + 1)] = $path;
		}
		$paths['FrankenPHP persistence result'] = $this->persistencePath;

		$html = '<h2>Artifacts</h2><table><thead><tr><th>Artifact</th><th>Path</th></tr></thead><tbody>';
		foreach ($paths as $label => $path) {
			if ($path === null) {
				continue;
			}
			$html .= '<tr><td>' . self::h($label) . '</td><td><code>' . self::h($path) . '</code></td></tr>';
		}

		return $html . '</tbody></table>';
	}

	private function workloadsSection(?array $cliRead, ?array $cliWrite, ?array $resident, array $fpmOnceRuns, array $fpmHotRuns, array $bulkRuns): string
	{
		$workloads = [];

		$this->mergeCaseMetadata($workloads, $cliRead['cases'] ?? []);
		$this->mergeCaseMetadata($workloads, $cliWrite['cases'] ?? []);
		foreach ($fpmOnceRuns as $run) {
			$this->mergeCaseMetadata($workloads, $run['data']['cases'] ?? []);
		}
		foreach ($fpmHotRuns as $run) {
			$this->mergeCaseMetadata($workloads, $run['data']['cases'] ?? []);
		}
		$this->mergeRows($workloads, $cliRead['read'] ?? [], 'CLI repeated read');
		$this->mergeRows($workloads, $cliWrite['write'] ?? [], 'CLI store');
		$this->mergeRows($workloads, $resident['rows'] ?? [], 'Resident direct access');
		foreach ($fpmOnceRuns as $run) {
			$this->mergeRows($workloads, $run['data']['results'] ?? [], 'FPM one fetch/request');
		}
		foreach ($fpmHotRuns as $run) {
			$this->mergeRows($workloads, $run['data']['results'] ?? [], 'FPM hot read');
		}

		if ($workloads === [] && $bulkRuns === []) {
			return '';
		}

		$html = '<h2>Workloads</h2>'
			. '<p class="note">Click a workload name in the result tables to jump to its description. CLI repeated read stores the payload before timing, then measures only fetch() plus the access probe. CLI store repeatedly stores the payload over the configured key space. Resident direct access runs only the access probe against an already-resident payload. FPM rows run the same fetch path through nginx/php-fpm workers. Bulk read primes multiple keys, then measures fetching the whole key set.</p>'
			. '<table><thead><tr><th>Workload</th><th>What It Measures</th><th>Measured In</th></tr></thead><tbody>';

		foreach ($workloads as $case => $workload) {
			$label = $workload['label'] !== null ? '<span class="small">' . self::h($workload['label']) . '</span>' : '';
			$description = $workload['description'] ?? 'No workload description was recorded in the benchmark JSON.';
			$mutates = $workload['mutates_after_fetch'];
			$mutation = $mutates === null ? '' : '<span class="small">Mutates fetched copy: ' . ($mutates ? 'yes' : 'no') . '</span>';
			$sections = implode(', ', array_keys($workload['sections']));

			$html .= '<tr id="' . self::h($this->workloadId($case)) . '"><td><code>' . $this->ident($case) . '</code>' . $label . '</td>'
				. '<td>' . self::h($description) . $mutation . '</td>'
				. '<td>' . self::h($sections) . '</td></tr>';
		}

		foreach ($bulkRuns as $bulkRun) {
			$data = $bulkRun['data'];
			$keyCount = (string) ($data['options']['key_count'] ?? '?');
			$operations = (string) ($data['options']['operations'] ?? '?');
			$description = 'Uses the multi-key config payload, primes ' . $keyCount . ' keys before timing, then repeatedly fetches all keys as a batch. UserCache is measured with fetchMultiple() and with a per-key fetch() loop; APCu is measured with apcu_fetch(array) and with a per-key loop, once per serializer (php and, when igbinary is available, igbinary); Yac is measured with get(array) and with a per-key loop.';
			$html .= '<tr id="' . self::h($this->bulkWorkloadId($keyCount)) . '"><td><code>bulk_read_' . self::h($keyCount) . '_keys</code><span class="small">Bulk Read: ' . self::h($keyCount) . ' Keys</span></td>'
				. '<td>' . self::h($description) . '<span class="small">Measured batches per iteration: ' . self::h($operations) . '</span></td>'
				. '<td>Bulk read</td></tr>';
		}

		return $html . '</tbody></table>';
	}

	private function notesSection(): string
	{
		return '<h2>Notes</h2>'
			. '<h3 id="resident-baseline-note">Already-resident data is a baseline</h3>'
			. '<p class="note warn">The resident table does not include UserCache store time. It compares direct access to an already-resident payload with fetching a previously stored UserCache entry and running the same access probe. The difference estimates fetch and materialization overhead after the value has already been stored. The primary comparison for UserCache is against APCu/php and APCu/igbinary when reconstructing object-heavy payloads, not against literals already resident in the request.</p>'
			. '<h3 id="store-tradeoff-note">Slower stores are an expected trade-off for faster reads</h3>'
			. '<p class="note warn">Store workloads are shown to make the write-side cost explicit. APCu-style shared caches are usually used for read-heavy paths, where a stored value is read many times. Store throughput is an intentional trade-off in this design, not the metric it is optimized for.</p>';
	}

	private function groupRows(array $rows): array
	{
		$groups = [];
		foreach ($rows as $row) {
			if (!isset($row['case'], $row['backend'])) {
				continue;
			}
			$groups[(string) $row['case']][(string) $row['backend']] = $row;
		}

		return $groups;
	}

	private function backendOrderForRows(array $rows): array
	{
		$preferred = ['user_cache', 'apcu', 'apcu_igbinary', 'yac'];
		$seen = [];
		foreach ($rows as $row) {
			if (isset($row['backend'])) {
				$seen[(string) $row['backend']] = true;
			}
		}

		$ordered = [];
		foreach ($preferred as $backendName) {
			if (isset($seen[$backendName])) {
				$ordered[] = $backendName;
				unset($seen[$backendName]);
			}
		}
		foreach (array_keys($seen) as $backendName) {
			$ordered[] = $backendName;
		}

		return $ordered;
	}

	private function backendLabel(string $backend): string
	{
		return match ($backend) {
			'user_cache' => 'UserCache',
			'apcu' => 'APCu/php',
			'apcu_igbinary' => 'APCu/igbinary',
			'yac' => 'Yac/php',
			'yac_fetch_multiple' => 'Yac get array',
			'yac_fetch_loop' => 'Yac get loop',
			'user_cache_fetch_multiple' => 'UserCache fetchMultiple',
			'user_cache_fetch_loop' => 'UserCache fetch loop',
			'apcu_fetch_multiple' => 'APCu/php fetch array',
			'apcu_fetch_loop' => 'APCu/php fetch loop',
			'apcu_igbinary_fetch_multiple' => 'APCu/igbinary fetch array',
			'apcu_igbinary_fetch_loop' => 'APCu/igbinary fetch loop',
			default => $backend,
		};
	}

	private function fpmRunLabel(array $data, int $index): string
	{
		$backends = is_array($data['options']['backends'] ?? null) ? $data['options']['backends'] : [];
		$hasPhpSerializer = in_array('apcu', $backends, true);
		$hasIgbinarySerializer = in_array('apcu_igbinary', $backends, true);

		if ($hasPhpSerializer && $hasIgbinarySerializer) {
			return 'combined APCu serializers';
		}
		if ($hasIgbinarySerializer) {
			return 'APCu serializer=igbinary';
		}
		if ($hasPhpSerializer) {
			return 'APCu serializer=php';
		}

		return 'run ' . (string) ($index + 1);
	}

	private function mergeCaseMetadata(array &$workloads, array $cases): void
	{
		foreach ($cases as $case => $metadata) {
			if (!is_string($case) || !is_array($metadata)) {
				continue;
			}
			$this->ensureWorkload($workloads, $case);
			$workloads[$case]['label'] = isset($metadata['label']) ? (string) $metadata['label'] : $workloads[$case]['label'];
			$workloads[$case]['description'] = isset($metadata['description']) ? (string) $metadata['description'] : $workloads[$case]['description'];
			if (isset($metadata['mutates_after_fetch'])) {
				$workloads[$case]['mutates_after_fetch'] = (bool) $metadata['mutates_after_fetch'];
			}
		}
	}

	private function mergeRows(array &$workloads, array $rows, string $section): void
	{
		foreach ($rows as $row) {
			if (!isset($row['case'])) {
				continue;
			}
			$case = (string) $row['case'];
			$this->ensureWorkload($workloads, $case);
			if (isset($row['case_label']) && $workloads[$case]['label'] === null) {
				$workloads[$case]['label'] = (string) $row['case_label'];
			}
			if (isset($row['mutates_after_fetch'])) {
				$workloads[$case]['mutates_after_fetch'] = (bool) $row['mutates_after_fetch'];
			}
			$workloads[$case]['sections'][$section] = true;
		}
	}

	private function ensureWorkload(array &$workloads, string $case): void
	{
		if (isset($workloads[$case])) {
			return;
		}

		$workloads[$case] = [
			'label' => null,
			'description' => null,
			'mutates_after_fetch' => null,
			'sections' => [],
		];
	}

	private function bestBackend(array $rowsByBackend, string $metric): ?string
	{
		$values = UcBenchComparison::metricValues($rowsByBackend, $metric);
		return array_key_first($values);
	}

	private function readJson(string $path): array
	{
		$json = file_get_contents($path);
		if ($json === false) {
			throw new RuntimeException('Unable to read JSON file: ' . $path);
		}
		$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($data)) {
			throw new RuntimeException('JSON file did not decode to an object: ' . $path);
		}

		return $data;
	}

	private function readPersistence(string $path): array
	{
		if (!$this->isHtml($path)) {
			$data = $this->readJson($path);
		} else {
			$contents = file_get_contents($path);
			if ($contents === false) {
				throw new RuntimeException('Unable to read persistence report: ' . $path);
			}
			/* The persistence report escapes '<' inside its embedded JSON, so the first closing tag ends it. */
			if (!preg_match('#<script id="embedded-result" type="application/json">(.*?)</script>#s', $contents, $match)) {
				throw new RuntimeException('Persistence report has no embedded aggregate JSON: ' . $path);
			}
			$data = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
		}
		if (!is_array($data) || ($data['schema_version'] ?? null) !== 1 || !is_bool($data['smoke_only'] ?? null) || !isset($data['status'])) {
			throw new RuntimeException('Unsupported persistence result schema: ' . $path);
		}
		foreach (['micro', 'aa', 'http'] as $section) {
			if (!is_array($data[$section] ?? null)) {
				throw new RuntimeException('Persistence result is missing section ' . $section . ': ' . $path);
			}
		}

		return $data;
	}

	private function isHtml(string $path): bool
	{
		return preg_match('/\.html?$/i', $path) === 1;
	}

	private function relativeHref(string $target): string
	{
		$split = static fn (string $path): array => array_values(array_filter(explode('/', (realpath($path) ?: $path)), static fn (string $part): bool => $part !== ''));
		$from = $split(dirname($this->output));
		$to = $split($target);
		$common = 0;
		while ($common < count($from) && $common < count($to) - 1 && $from[$common] === $to[$common]) {
			$common++;
		}
		$parts = array_merge(array_fill(0, count($from) - $common, '..'), array_slice($to, $common));

		return implode('/', array_map('rawurlencode', $parts));
	}

	private function value(array $argv, int $offset, string $option): string
	{
		if (!isset($argv[$offset]) || str_starts_with($argv[$offset], '--')) {
			throw new InvalidArgumentException($option . ' requires a value');
		}

		return $argv[$offset];
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

	private function number(float $value, int $decimals): string
	{
		return number_format($value, $decimals, '.', ',');
	}

	private function speedup(float $value): string
	{
		return $this->number($value, $value >= 100.0 ? 0 : ($value >= 10.0 ? 1 : 2)) . 'x';
	}

	private function geomean(array $values): float
	{
		return exp(array_sum(array_map(static fn (float $value): float => log($value), $values)) / count($values));
	}

	private static function h(string $value): string
	{
		return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	private function ident(string $value): string
	{
		return str_replace('_', '_<wbr>', self::h($value));
	}

	private function workloadLink(string $case): string
	{
		return '<a class="workload-link" href="#' . self::h($this->workloadId($case)) . '"><code>' . $this->ident($case) . '</code></a>';
	}

	private function workloadId(string $case): string
	{
		$id = preg_replace('/[^a-z0-9_-]+/', '-', strtolower($case));
		if ($id === null || $id === '') {
			$id = 'unknown';
		}

		return 'workload-' . $id;
	}

	private function bulkWorkloadId(string $keyCount): string
	{
		$id = preg_replace('/[^a-z0-9_-]+/', '-', strtolower($keyCount));
		if ($id === null || $id === '') {
			$id = 'unknown';
		}

		return 'workload-bulk-read-' . $id;
	}
}

try {
	exit((new UcPerformanceReport())->run($argv));
} catch (Throwable $throwable) {
	fwrite(STDERR, 'Report failed: ' . $throwable->getMessage() . "\n");
	exit(1);
}
