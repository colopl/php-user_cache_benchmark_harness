<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/scripts/UserCacheBenchmark.php';

header('Content-Type: application/json');

function uc_fpm_read_json(array $payload, int $status = 200): never
{
	http_response_code($status);
	echo json_encode($payload, JSON_UNESCAPED_SLASHES), "\n";
	exit;
}

function uc_fpm_read_param_int(string $name, int $default, int $min, int $max): int
{
	$value = $_GET[$name] ?? null;
	if (!is_scalar($value)) {
		return $default;
	}

	$string = (string) $value;
	if ($string === '' || strspn($string, '0123456789') !== strlen($string)) {
		return $default;
	}

	return max($min, min($max, (int) $string));
}

function uc_fpm_read_backends(): array
{
	return [
		'user_cache' => new UcBenchUserCacheBackend('fpm-user-cache-read-bench'),
		'apcu' => new UcBenchApcuBackend(),
		'apcu_igbinary' => new UcBenchApcuBackend(
			'apcu_igbinary',
			'APCu/igbinary store/fetch',
			'igbinary',
			true,
		),
	];
}

function uc_fpm_read_backend(string $backendName): UcBenchBackend
{
	$backends = uc_fpm_read_backends();
	if (!isset($backends[$backendName])) {
		throw new InvalidArgumentException('Unknown backend: ' . $backendName);
	}

	$backend = $backends[$backendName];
	if (!$backend->available()) {
		throw new RuntimeException($backendName . ' backend is unavailable: ' . ($backend->unavailableReason() ?? 'unknown reason'));
	}

	return $backend;
}

function uc_fpm_read_case(string $caseName): array
{
	$cases = UcBenchPayloadFactory::select([$caseName]);

	return $cases[$caseName];
}

function uc_fpm_read_key(string $caseName, string $backendName): string
{
	return 'user_cache_fpm_read_benchmark.' . UC_BENCH_VERSION . '.' . $backendName . '.' . $caseName;
}

try {
	$action = (string) ($_GET['action'] ?? 'describe');
	$pid = getmypid();

	if ($action === 'describe') {
		$backendInfo = [];
		foreach (uc_fpm_read_backends() as $backendName => $backend) {
			$backendInfo[$backendName] = [
				'label' => $backend->label(),
				'available' => $backend->available(),
				'unavailable_reason' => $backend->unavailableReason(),
			];
		}

		$userCacheAvailable = false;
		$userCacheReason = null;
		if (class_exists('UserCache\Cache')) {
			[$userCacheAvailable, $userCacheReason] = uc_bench_user_cache_status();
		}

		uc_fpm_read_json([
			'ok' => true,
			'pid' => $pid,
			'php_version' => PHP_VERSION,
			'php_sapi' => PHP_SAPI,
			'user_cache' => [
				'class_exists' => class_exists('UserCache\Cache'),
				'available' => $userCacheAvailable,
				'unavailable_reason' => $userCacheReason,
				'shm_size' => ini_get('user_cache.shm_size'),
			],
			'extensions' => [
				'apcu' => extension_loaded('apcu'),
				'igbinary' => function_exists('igbinary_serialize') && function_exists('igbinary_unserialize'),
			],
			'cases' => array_keys(UcBenchPayloadFactory::all()),
			'backends' => $backendInfo,
		]);
	}

	$caseName = (string) ($_GET['case'] ?? '');
	$backendName = (string) ($_GET['backend'] ?? '');
	if ($caseName === '' || $backendName === '') {
		throw new InvalidArgumentException('case and backend are required');
	}

	$case = uc_fpm_read_case($caseName);
	$backend = uc_fpm_read_backend($backendName);
	$key = uc_fpm_read_key($caseName, $backendName);

	if ($action === 'clear') {
		$backend->clear();
		uc_fpm_read_json([
			'ok' => true,
			'pid' => $pid,
			'action' => $action,
			'case' => $caseName,
			'backend' => $backendName,
		]);
	}

	if ($action === 'prime') {
		$payload = $case['build']();
		$digest = $case['digest']($payload);
		$backend->store($key, $payload);
		$fetched = $backend->fetch($key);
		$actualDigest = $case['digest']($fetched);
		if ($actualDigest !== $digest) {
			throw new RuntimeException('Digest mismatch after prime: expected ' . $digest . ', got ' . $actualDigest);
		}

		uc_fpm_read_json([
			'ok' => true,
			'pid' => $pid,
			'action' => $action,
			'case' => $caseName,
			'backend' => $backendName,
			'digest' => $digest,
		]);
	}

	if ($action === 'collect_cycles') {
		$holdUs = uc_fpm_read_param_int('hold_us', 0, 0, 1000000);
		$collected = gc_collect_cycles();
		if ($holdUs > 0) {
			usleep($holdUs);
		}

		uc_fpm_read_json([
			'ok' => true,
			'pid' => $pid,
			'action' => $action,
			'case' => $caseName,
			'backend' => $backendName,
			'collected' => $collected,
		]);
	}

	if ($action !== 'measure') {
		throw new InvalidArgumentException('Unknown action: ' . $action);
	}

	$operations = uc_fpm_read_param_int('operations', 1, 1, 100000);
	$holdUs = uc_fpm_read_param_int('hold_us', 0, 0, 1000000);
	$expectedDigest = array_key_exists('expected_digest', $_GET) && is_scalar($_GET['expected_digest'])
		? (string) $_GET['expected_digest']
		: $case['digest']($case['build']());
	$mutate = $case['mutate'];
	$probe = $case['probe'];
	$gcWasEnabled = $case['collect_cycles_after_fetch'] && gc_enabled();
	$touch = 0;
	$value = null;

	if ($gcWasEnabled) {
		gc_disable();
	}

	$started = hrtime(true);
	$elapsedNs = 0;
	try {
		for ($i = 0; $i < $operations; $i++) {
			$value = $backend->fetch($key);
			if ($probe !== null) {
				$touch ^= $probe($value, $i);
			}
			if ($mutate !== null) {
				$mutate($value, $i);
			}
		}

		$elapsedNs = hrtime(true) - $started;
	} finally {
		if ($gcWasEnabled) {
			gc_enable();
		}
	}

	$verify = $backend->fetch($key);
	$actualDigest = $case['digest']($verify);
	if ($actualDigest !== $expectedDigest) {
		throw new RuntimeException('Digest mismatch after measure: expected ' . $expectedDigest . ', got ' . $actualDigest);
	}

	if ($holdUs > 0) {
		usleep($holdUs);
	}

	uc_fpm_read_json([
		'ok' => true,
		'pid' => $pid,
		'action' => $action,
		'case' => $caseName,
		'backend' => $backendName,
		'operations' => $operations,
		'elapsed_ns' => $elapsedNs,
		'server_us_per_op' => ($elapsedNs / 1000) / $operations,
		'touch' => $touch,
	]);
} catch (Throwable $throwable) {
	uc_fpm_read_json([
		'ok' => false,
		'pid' => getmypid(),
		'error_class' => $throwable::class,
		'error' => $throwable->getMessage(),
	], 500);
}
