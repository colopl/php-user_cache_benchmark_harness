<?php

declare(strict_types=1);

final class UcPerformanceReport
{
	private ?string $cliReadPath = null;
	private ?string $cliWritePath = null;
	private ?string $residentPath = null;
	private ?string $fpmOncePath = null;
	private ?string $fpmHotPath = null;
	private array $bulkPaths = [];
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
		$fpmOnce = $this->fpmOncePath !== null ? $this->readJson($this->fpmOncePath) : null;
		$fpmHot = $this->fpmHotPath !== null ? $this->readJson($this->fpmHotPath) : null;
		$bulkRuns = array_map(fn (string $path): array => ['path' => $path, 'data' => $this->readJson($path)], $this->bulkPaths);

		if ($cliWrite === null && $cliRead !== null && ($cliRead['write'] ?? []) !== []) {
			$cliWrite = $cliRead;
		}

		if ($cliRead === null && $cliWrite === null && $resident === null && $fpmOnce === null && $fpmHot === null && $bulkRuns === []) {
			throw new RuntimeException('No benchmark result files were provided');
		}

		$html = $this->render($cliRead, $cliWrite, $resident, $fpmOnce, $fpmHot, $bulkRuns);
		$dir = dirname($this->output);
		if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
			throw new RuntimeException('Unable to create output directory: ' . $dir);
		}
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
					$this->fpmOncePath = $this->absolutePath($this->value($argv, ++$i, $arg));
					break;
				case '--fpm-hot':
					$this->fpmHotPath = $this->absolutePath($this->value($argv, ++$i, $arg));
					break;
				case '--bulk':
					$this->bulkPaths[] = $this->absolutePath($this->value($argv, ++$i, $arg));
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
		fwrite(STDOUT, "Usage: php scripts/render_user_cache_performance_report.php [--cli-read FILE] [--cli-write FILE] [--resident FILE] [--fpm-once FILE] [--fpm-hot FILE] [--bulk FILE]... [--output FILE]\n");
	}

	private function render(?array $cliRead, ?array $cliWrite, ?array $resident, ?array $fpmOnce, ?array $fpmHot, array $bulkRuns): string
	{
		$cards = [];
		if ($cliRead !== null) {
			$cards[] = $this->winnerCard('CLI repeated read', $cliRead['read'] ?? [], 'mean_operation_us');
		}
		if ($cliWrite !== null && ($cliWrite['write'] ?? []) !== []) {
			$cards[] = $this->winnerCard('CLI store', $cliWrite['write'] ?? [], 'mean_store_us');
		}
		if ($fpmOnce !== null) {
			$cards[] = $this->winnerCard('FPM one fetch/request', $fpmOnce['results'] ?? [], 'mean_server_us_per_op');
		}
		if ($fpmHot !== null) {
			$cards[] = $this->winnerCard('FPM hot read', $fpmHot['results'] ?? [], 'mean_server_us_per_op');
		}
		return '<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>OPcache User Cache Performance Report</title>
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
<h1>OPcache User Cache Performance Report</h1>
<p>Generated at <code>' . self::h(gmdate(DATE_ATOM)) . '</code>. Times are in microseconds; lower is faster.</p>
<div class="cards">' . implode('', $cards) . '</div>
' . $this->environmentSection($cliRead, $fpmOnce, $fpmHot) . '
' . ($cliRead !== null ? $this->cacheReadTable('CLI Repeated Read', $cliRead['read'] ?? [], 'mean_operation_us', 'median_us') : '') . '
' . ($cliWrite !== null ? $this->cacheReadTable('CLI Store', $cliWrite['write'] ?? [], 'mean_store_us', 'median_us', false, 'store-tradeoff-note') : '') . '
' . ($fpmOnce !== null ? $this->cacheReadTable('FPM One Fetch Per Request', $fpmOnce['results'] ?? [], 'mean_server_us_per_op', 'median_server_us_per_op', true) : '') . '
' . ($fpmHot !== null ? $this->cacheReadTable('FPM Hot Read', $fpmHot['results'] ?? [], 'mean_server_us_per_op', 'median_server_us_per_op', true) : '') . '
' . $this->residentTable($resident, $cliRead) . '
' . $this->bulkTables($bulkRuns) . '
' . $this->artifactTable() . '
' . $this->workloadsSection($cliRead, $cliWrite, $resident, $fpmOnce, $fpmHot, $bulkRuns) . '
' . $this->notesSection() . '
</main>
</body>
</html>
';
	}

	private function environmentSection(?array $cliRead, ?array $fpmOnce, ?array $fpmHot): string
	{
		$environment = $cliRead['environment'] ?? $fpmOnce['environment'] ?? $fpmHot['environment'] ?? null;
		if (!is_array($environment)) {
			return '';
		}

		$ini = is_array($environment['ini'] ?? null) ? $environment['ini'] : [];
		$extensions = is_array($environment['loaded_extensions'] ?? null) ? $environment['loaded_extensions'] : [];
		$threadSafety = array_key_exists('php_zts', $environment)
			? (!empty($environment['php_zts']) ? 'ZTS' : 'NTS')
			: (PHP_ZTS ? 'ZTS' : 'NTS');
		$loaded = [];
		foreach ($extensions as $name => $enabled) {
			if ($enabled) {
				$loaded[] = (string) $name;
			}
		}

		$rows = [
			'PHP' => (string) ($environment['php_version'] ?? '') . ' (' . (string) ($environment['php_sapi'] ?? '') . ')',
			'Thread Safety' => $threadSafety,
			'Binary' => (string) ($environment['php_binary'] ?? ''),
			'System' => (string) ($environment['uname'] ?? ''),
			'opcache.user_cache_shm_size' => (string) ($ini['opcache.user_cache_shm_size'] ?? ''),
			'Loaded benchmark extensions' => $loaded !== [] ? implode(', ', $loaded) : 'none',
		];

		$html = '<h2>Environment</h2><table><tbody>';
		foreach ($rows as $key => $value) {
			$html .= '<tr><th>' . self::h($key) . '</th><td><code>' . self::h($value) . '</code></td></tr>';
		}

		return $html . '</tbody></table>';
	}

	private function winnerCard(string $title, array $rows, string $metric): string
	{
		$groups = $this->groupRows($rows);
		$total = count($groups);
		$userWins = 0;
		$speedups = [];
		foreach ($groups as $caseRows) {
			$best = $this->bestBackend($caseRows, $metric);
			if ($best === 'user_cache') {
				$userWins++;
			}
			if (isset($caseRows['user_cache'], $caseRows['apcu'])) {
				$user = (float) $caseRows['user_cache'][$metric];
				$apcu = (float) $caseRows['apcu'][$metric];
				if ($user > 0.0) {
					$speedups[] = $apcu / $user;
				}
			}
		}
		$medianSpeedup = $speedups !== [] ? $this->median($speedups) : null;

		return '<div class="card">' . self::h($title)
			. '<strong>' . self::h((string) $userWins) . '/' . self::h((string) $total) . ' UserCache wins</strong>'
			. '<span class="small">Median APCu/UserCache: ' . ($medianSpeedup !== null ? self::h($this->number($medianSpeedup, 2) . 'x') : 'n/a') . '</span></div>';
	}

	private function cacheReadTable(string $title, array $rows, string $metric, string $medianMetric, bool $showWorkers = false, ?string $apcuLossAnchor = null): string
	{
		$groups = $this->groupRows($rows);
		if ($groups === []) {
			return '<h2>' . self::h($title) . '</h2><p class="note">No rows measured.</p>';
		}

		$html = '<h2>' . self::h($title) . '</h2><table><thead><tr>'
			. '<th>Workload</th><th class="num">UserCache</th><th class="num">APCu</th><th class="num">DeepClone</th><th class="num">APCu/UserCache</th>'
			. ($showWorkers ? '<th class="num">Workers</th>' : '')
			. '</tr></thead><tbody>';

		foreach ($groups as $case => $caseRows) {
			$bestBackend = $this->bestBackend($caseRows, $metric);
			$user = isset($caseRows['user_cache']) ? (float) $caseRows['user_cache'][$metric] : null;
			$apcu = isset($caseRows['apcu']) ? (float) $caseRows['apcu'][$metric] : null;
			$speedup = $user !== null && $user > 0.0 && $apcu !== null ? $apcu / $user : null;
			$workers = isset($caseRows['user_cache']['worker_count']) ? (string) $caseRows['user_cache']['worker_count'] : '';
			$noteLink = $apcuLossAnchor !== null && $speedup !== null && $speedup < 1.0
				? '<a class="note-link" href="#' . self::h($apcuLossAnchor) . '">store trade-off note</a>'
				: '';
			$html .= '<tr><td>' . $this->workloadLink($case) . $noteLink . '</td>'
				. $this->metricCell($caseRows['user_cache'] ?? null, $metric, $medianMetric, $bestBackend === 'user_cache')
				. $this->metricCell($caseRows['apcu'] ?? null, $metric, $medianMetric, $bestBackend === 'apcu')
				. $this->metricCell($caseRows['deepclone'] ?? null, $metric, $medianMetric, $bestBackend === 'deepclone')
				. '<td class="num">' . ($speedup !== null ? self::h($this->number($speedup, 2) . 'x') : '<span class="muted">n/a</span>') . '</td>'
				. ($showWorkers ? '<td class="num">' . self::h($workers) . '</td>' : '')
				. '</tr>';
		}

		return $html . '</tbody></table>';
	}

	private function metricCell(?array $row, string $metric, string $medianMetric, bool $winner): string
	{
		if ($row === null || !isset($row[$metric])) {
			return '<td class="num"><span class="muted">n/a</span></td>';
		}

		$class = $winner ? ' class="winner"' : '';
		$median = isset($row[$medianMetric]) ? (float) $row[$medianMetric] : null;
		return '<td class="num"><span' . $class . '>' . self::h($this->number((float) $row[$metric], 3)) . ' us</span>'
			. ($median !== null ? '<span class="small">median ' . self::h($this->number($median, 3)) . '</span>' : '')
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
			$residentUs = (float) $row['mean_operation_us'];
			$userUs = isset($cliRows[$case]['user_cache']) ? (float) $cliRows[$case]['user_cache']['mean_operation_us'] : null;
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

	private function bulkTables(array $bulkRuns): string
	{
		$html = '';
		foreach ($bulkRuns as $bulkRun) {
			$data = $bulkRun['data'];
			$keyCount = (string) ($data['options']['key_count'] ?? '?');
			$html .= '<h2><a class="workload-link" href="#' . self::h($this->bulkWorkloadId($keyCount)) . '">Bulk Read: ' . self::h($keyCount) . ' Keys</a></h2><table class="bulk-table"><thead><tr><th>Backend</th><th class="num">Mean/batch</th><th class="num">Median/batch</th><th class="num">Mean/key</th></tr></thead><tbody>';
			$rows = $data['rows'] ?? [];
			$bestBackend = $this->bestBackendByRows($rows, 'backend', 'mean_us_per_key');
			foreach ($rows as $row) {
				$winner = $bestBackend === ($row['backend'] ?? null);
				$html .= '<tr><td><code>' . $this->ident((string) $row['backend']) . '</code></td>'
					. '<td class="num' . ($winner ? ' winner' : '') . '">' . self::h($this->number((float) $row['mean_us_per_batch'], 3)) . ' us</td>'
					. '<td class="num">' . self::h($this->number((float) $row['median_us_per_batch'], 3)) . ' us</td>'
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
			'FPM one fetch/request JSON' => $this->fpmOncePath,
			'FPM hot read JSON' => $this->fpmHotPath,
		];
		foreach ($this->bulkPaths as $index => $path) {
			$paths['Bulk read JSON #' . ($index + 1)] = $path;
		}

		$html = '<h2>Artifacts</h2><table><thead><tr><th>Artifact</th><th>Path</th></tr></thead><tbody>';
		foreach ($paths as $label => $path) {
			if ($path === null) {
				continue;
			}
			$html .= '<tr><td>' . self::h($label) . '</td><td><code>' . self::h($path) . '</code></td></tr>';
		}

		return $html . '</tbody></table>';
	}

	private function workloadsSection(?array $cliRead, ?array $cliWrite, ?array $resident, ?array $fpmOnce, ?array $fpmHot, array $bulkRuns): string
	{
		$workloads = [];

		$this->mergeCaseMetadata($workloads, $cliRead['cases'] ?? []);
		$this->mergeCaseMetadata($workloads, $cliWrite['cases'] ?? []);
		$this->mergeRows($workloads, $cliRead['read'] ?? [], 'CLI repeated read');
		$this->mergeRows($workloads, $cliWrite['write'] ?? [], 'CLI store');
		$this->mergeRows($workloads, $resident['rows'] ?? [], 'Resident direct access');
		$this->mergeRows($workloads, $fpmOnce['results'] ?? [], 'FPM one fetch/request');
		$this->mergeRows($workloads, $fpmHot['results'] ?? [], 'FPM hot read');

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
			$description = 'Uses the multi-key config payload, primes ' . $keyCount . ' keys before timing, then repeatedly fetches all keys as a batch. UserCache is measured with fetchMultiple() and with a per-key fetch() loop; APCu is measured with apcu_fetch(array) and with a per-key loop.';
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
			. '<p class="note warn">The resident table does not include UserCache store time. It compares direct access to an already-resident payload with fetching a previously stored UserCache entry and running the same access probe. The difference estimates fetch and materialization overhead after the value has already been stored. The primary comparison for UserCache is against APCu or ext-deepclone when reconstructing object-heavy payloads, not against literals already resident in the request.</p>'
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
		$best = null;
		$bestValue = null;
		foreach ($rowsByBackend as $backend => $row) {
			if (!isset($row[$metric])) {
				continue;
			}
			$value = (float) $row[$metric];
			if ($bestValue === null || $value < $bestValue) {
				$best = (string) $backend;
				$bestValue = $value;
			}
		}

		return $best;
	}

	private function bestBackendByRows(array $rows, string $backendField, string $metric): ?string
	{
		$best = null;
		$bestValue = null;
		foreach ($rows as $row) {
			if (!isset($row[$backendField], $row[$metric])) {
				continue;
			}
			$value = (float) $row[$metric];
			if ($bestValue === null || $value < $bestValue) {
				$best = (string) $row[$backendField];
				$bestValue = $value;
			}
		}

		return $best;
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
