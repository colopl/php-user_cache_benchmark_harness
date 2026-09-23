<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/UserCacheBenchmark.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$label = static fn (string $backend): string => $backend;
$rows = [
    ['backend' => 'user_cache', 'median_us' => 8.0],
    ['backend' => 'apcu', 'median_us' => 4.0],
    ['backend' => 'yac', 'median_us' => 2.0],
    ['backend' => 'apcu_igbinary', 'median_us' => 4.0],
    ['backend' => 'missing', 'median_us' => null],
    ['backend' => 'zero', 'median_us' => 0],
    ['backend' => 'invalid', 'median_us' => -1],
];
$ranking = UcBenchComparison::fasterCell($rows, 'median_us', $label);
check($ranking === '<span class="ranking">yac 1.00x</span>'
    . '<span class="ranking">apcu 2.00x</span>'
    . '<span class="ranking">apcu_igbinary 2.00x</span>'
    . '<span class="ranking">user_cache 4.00x</span>', 'Incorrect sorted ratios or invalid measurements included');
check(str_contains(UcBenchComparison::fasterCell(array_slice($rows, 1), 'median_us', $label), 'yac 1.00x'), 'UserCache must not be required');
check(str_contains(UcBenchComparison::fasterCell([], 'median_us', $label), 'n/a'), 'Missing measurements must be n/a');
check(str_contains(UcBenchComparison::fasterCell($rows, 'median_us', static fn (): string => '<yac>'), '&lt;yac&gt;'), 'Labels must be escaped');

$tmp = sys_get_temp_dir() . '/uc-comparison-' . getmypid();
mkdir($tmp);
try {
    $fixtureRows = [];
    foreach (['user_cache' => 8, 'apcu' => 2, 'apcu_igbinary' => 4, 'yac' => 2] as $backend => $time) {
        $fixtureRows[] = [
            'case' => 'example', 'case_label' => 'Example', 'mode' => 'read',
            'backend' => $backend, 'backend_label' => $backend,
            'median_us' => $time, 'mean_operation_us' => $time,
            'value_shm_bytes' => $backend === 'yac' ? null : ($backend === 'apcu' ? 64 : 128),
            'fetch_retained_bytes' => $backend === 'user_cache' ? 0 : 256,
            'backend_shm_baseline_bytes' => $backend === 'yac' ? null : 512,
            'backend_shm_reserved_bytes' => 1048576,
            'first_fetch_retained_bytes' => 32,
            'store_retained_bytes' => 16,
            'request_residual_bytes' => 8,
            'operation_count' => 1, 'samples_us' => [$time], 'iterations' => 1,
            'min_us' => $time, 'max_us' => $time, 'stddev_us' => 0,
            'operations_per_second' => 1000000 / $time,
            'mutates_after_fetch' => false, 'delta_vs_user_cache_percent' => null,
        ];
    }
    $result = [
        'read' => $fixtureRows, 'write' => [], 'failures' => [], 'backends' => [], 'cases' => [],
        'environment' => [
            'php_version' => PHP_VERSION, 'php_sapi' => PHP_SAPI,
            'php_binary' => PHP_BINARY, 'uname' => php_uname(),
            'loaded_extensions' => [], 'ini' => ['opcache.enable_cli' => '1', 'user_cache.shm_size' => '128M'], 'opcache_jit' => null,
        ],
        'options' => uc_bench_parse_options(['benchmark', '--read-only']),
        'generated_at' => gmdate(DATE_ATOM), 'version' => UC_BENCH_VERSION,
    ];
    file_put_contents($tmp . '/read.json', json_encode($result, JSON_THROW_ON_ERROR));
    $cmd = [PHP_BINARY, dirname(__DIR__) . '/scripts/render_user_cache_performance_report.php',
        '--cli-read', $tmp . '/read.json', '--output', $tmp . '/report.html'];
    $process = proc_open($cmd, [1 => ['file', $tmp . '/log', 'w'], 2 => ['file', $tmp . '/log', 'a']], $pipes);
    check(proc_close($process) === 0, 'Renderer failed: ' . file_get_contents($tmp . '/log'));
    $reports = [file_get_contents($tmp . '/report.html'), UcBenchHtmlReport::render($result, $tmp . '/read.json')];
    foreach ($reports as $html) {
        check(!str_contains($html, 'Faster/UserCache'), 'Old heading remains');
        check(str_contains($html, '>Faster</th>'), 'Missing Faster heading');
        check(str_contains($html, 'APCu/php 1.00x</span><span class="ranking">Yac/php 1.00x'), 'Tied fastest backends must both appear first');
        check(substr_count($html, '<td class="num winner-cell">') === 2, 'Both tied fastest cells must be colored');
        check(str_contains($html, 'Memory Usage'), 'Missing memory section');
        check(str_contains($html, 'memory-best">per fetch 0 B'), 'Zero heap use must win');
        check(str_contains($html, 'memory-best">cache 64 B'), 'Cache minimum must win independently');
        check(str_contains($html, 'cache n/a'), 'Yac usage must not be fabricated');
        check(str_contains($html, 'request hold 24 B'), 'Request hold must include store-retained state');
        check(str_contains($html, 'Reserved</th>'), 'Reserved capacity must be separate');
    }
    check(UcBenchComparison::memoryTable([['backend' => 'yac', 'case' => 'legacy']], $label) === '', 'Legacy JSON without memory must remain supported');
} finally {
    foreach (glob($tmp . '/*') ?: [] as $path) {
        unlink($path);
    }
    rmdir($tmp);
}
echo "PASS\n";
