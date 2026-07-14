<?php

declare(strict_types=1);
const UC_BENCH_VERSION = 'user-cache-benchmark-1';

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

$ucBenchAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($ucBenchAutoload)) {
	require_once $ucBenchAutoload;
}

final class UcBenchMetadataLeaf
{
	public function __construct(
		public string $name,
		public int $score,
		public array $flags,
	) {
	}
}

final class UcBenchSerializeEntity
{
	public function __construct(
		private int $id,
		private string $name,
		private array $attributes,
		private ?UcBenchSerializeEntity $related = null,
	) {
	}

	public function __serialize(): array
	{
		return [
			'id' => $this->id,
			'name' => $this->name,
			'attributes' => $this->attributes,
			'related' => $this->related,
		];
	}

	public function __unserialize(array $data): void
	{
		$this->id = $data['id'];
		$this->name = $data['name'];
		$this->attributes = $data['attributes'];
		$this->related = $data['related'];
	}

	public function summary(): int
	{
		return $this->id + count($this->attributes) + ($this->related?->id ?? 0);
	}

	public function relatedEntity(): ?UcBenchSerializeEntity
	{
		return $this->related;
	}
}

final class UcBenchUnserializeOnlyEntity
{
	private int $checksum;

	public function __construct(
		private int $id,
		private string $name,
		private array $attributes,
		private ?UcBenchUnserializeOnlyEntity $related = null,
	) {
		$this->checksum = self::calculateChecksum($id, $name, $attributes, $related);
	}

	public function __unserialize(array $data): void
	{
		$this->id = $data["\0" . self::class . "\0id"];
		$this->name = $data["\0" . self::class . "\0name"];
		$this->attributes = $data["\0" . self::class . "\0attributes"];
		$this->related = $data["\0" . self::class . "\0related"];
		$this->checksum = self::calculateChecksum($this->id, $this->name, $this->attributes, $this->related);
	}

	public function summary(): int
	{
		return $this->checksum + ($this->related?->checksum ?? 0);
	}

	public function relatedEntity(): ?UcBenchUnserializeOnlyEntity
	{
		return $this->related;
	}

	private static function calculateChecksum(
		int $id,
		string $name,
		array $attributes,
		?UcBenchUnserializeOnlyEntity $related
	): int {
		return $id + strlen($name) + count($attributes) + ($related?->id ?? 0);
	}
}

final class UcBenchSleepEntity
{
	public string $loadedFrom = 'constructor';

	public function __construct(
		public int $id,
		public string $email,
		public array $roles,
		public array $profile,
	) {
	}

	public function __sleep(): array
	{
		return ['id', 'email', 'roles', 'profile'];
	}

	public function __wakeup(): void
	{
		$this->loadedFrom = 'cache';
	}
}

final class UcBenchTreeNode
{
	public ?UcBenchTreeNode $parent = null;
	/** @var UcBenchTreeNode[] */
	public array $children = [];

	public function __construct(
		public string $name,
		public array $attributes,
	) {
	}

	public function __sleep(): array
	{
		return ['parent', 'children', 'name', 'attributes'];
	}

	public function __wakeup(): void
	{
	}
}

final class UcBenchSleepWakeupPayload
{
	public string $transient = 'not-cached';

	public function __construct(
		public string $name,
		public array $routes,
		public array $settings,
	) {
	}

	public function __sleep(): array
	{
		return ['name', 'routes', 'settings'];
	}

	public function __wakeup(): void
	{
		$this->transient = 'rehydrated';
	}
}

final class UcBenchMetadataNode
{
	public function __construct(
		public int $id,
		public string $name,
		public array $attributes,
		public array $leaves,
		public array $children = [],
	) {
	}
}

final class UcBenchCarbonModel
{
	public function __construct(
		public int $id,
		public string $name,
		public Carbon\CarbonInterface $createdAt,
		public Carbon\CarbonInterface $updatedAt,
		public Carbon\CarbonInterface $publishedAt,
		public array $attributes,
	) {
	}
}

final class UcBenchRawModel
{
	public function __construct(
		public int $id,
		public string $name,
		public DateTimeInterface $createdAt,
		public DateTimeInterface $updatedAt,
		public DateTimeInterface $publishedAt,
		public array $attributes,
	) {
	}
}

final class UcBenchReferencedPayload
{
	public function __construct(
		public string $label,
		public int $revision,
	) {
	}
}

final class UcBenchReferencePayload
{
	public function __construct(
		public string $name,
		public UcBenchReferencedPayload $child,
	) {
	}
}

final class UcBenchCyclePayload
{
	public ?UcBenchCyclePayload $peer = null;

	public function __construct(
		public string $name,
		public int $revision,
	) {
	}
}

final class UcBenchFastPathPayload
{
	public function __construct(
		public DateTimeImmutable $createdAt,
		public DateTimeZone $timezone,
		public DateTimeImmutable $expiresAt,
		public DateInterval $gracePeriod,
	) {
	}
}

final class UcBenchCarbonDateTimePayload
{
	public function __construct(
		public Carbon\CarbonImmutable $createdAt,
		public Carbon\Carbon $updatedAt,
		public Carbon\CarbonTimeZone $timezone,
		public array $timeline,
	) {
	}
}

final class UcBenchRawDateTimePayload
{
	public function __construct(
		public DateTimeImmutable $createdAt,
		public DateTime $updatedAt,
		public DateTimeZone $timezone,
		public array $timeline,
	) {
	}
}

final class UcBenchSplCollectionPayload extends ArrayObject
{
	public function __construct(
		array $storage,
		public string $name,
		public int $revision,
	) {
		parent::__construct($storage, ArrayObject::ARRAY_AS_PROPS);
	}
}

final class UcBenchLargeObjectPayload
{
	public function __construct(
		public string $name,
		public array $rows,
		public UcBenchReferencedPayload $child,
	) {
	}
}

final class UcBenchMetadataPayload
{
	public function __construct(
		public string $name,
		public array $routes,
		public array $services,
		public UcBenchReferencedPayload $owner,
	) {
	}
}

final class UcBenchProductCard
{
	public function __construct(
		public int $id,
		public string $sku,
		public string $title,
		public int $priceCents,
		public array $badges,
		public UcBenchReferencedPayload $seller,
	) {
	}
}

final class UcBenchProductListingPayload
{
	public function __construct(
		public string $cacheKey,
		public DateTimeImmutable $generatedAt,
		public array $products,
		public array $facets,
		public array $context,
	) {
	}
}

interface UcBenchBackend
{
	public function name(): string;
	public function label(): string;
	public function available(): bool;
	public function unavailableReason(): ?string;
	public function clear(): void;
	public function store(string $key, mixed $value): void;
	public function fetch(string $key): mixed;
}

function uc_bench_unavailable_reason_to_string(mixed $reason): ?string
{
	if ($reason === null) {
		return null;
	}

	if ($reason instanceof UnitEnum) {
		if ($reason->name === 'Available') {
			return null;
		}

		return $reason->name;
	}

	return (string) $reason;
}

function uc_bench_user_cache_status(): array
{
	$status = UserCache\Cache::getStatus();
	$availability = $status->getAvailability();

	return [
		$availability instanceof UnitEnum && $availability->name === 'Available',
		uc_bench_unavailable_reason_to_string($availability),
	];
}

abstract class UcBenchAbstractBackend implements UcBenchBackend
{
	protected bool $available = false;
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

final class UcBenchUserCacheBackend extends UcBenchAbstractBackend
{
	private ?UserCache\Cache $cache = null;
	private static ?stdClass $default = null;

	public function __construct(private readonly string $scope)
	{
		if (!class_exists('UserCache\Cache')) {
			$this->unavailable('UserCache\Cache class is not available');
			return;
		}

		try {
			$this->cache = UserCache\Cache::getPool($scope);
			[$this->available, $this->unavailableReason] = uc_bench_user_cache_status();
			if (!$this->available && $this->unavailableReason === null) {
				$this->unavailableReason = 'UserCache\Cache is disabled or unavailable';
			}
		} catch (Throwable $exception) {
			$this->unavailable($exception->getMessage());
		}
	}

	public function name(): string
	{
		return 'user_cache';
	}

	public function label(): string
	{
		return 'UserCache\Cache store/fetch';
	}

	public function clear(): void
	{
		if ($this->cache === null || !$this->cache->clear()) {
			throw new RuntimeException('UserCache\Cache::clear() failed for scope ' . $this->scope);
		}
	}

	public function store(string $key, mixed $value): void
	{
		if ($this->cache === null || !$this->cache->store($key, $value)) {
			throw new RuntimeException('UserCache\Cache::store() failed for key ' . $key);
		}
	}

	public function fetch(string $key): mixed
	{
		$default = self::$default ??= new stdClass();
		$value = $this->cache?->fetch($key, $default);
		if ($value === $default) {
			throw new RuntimeException('UserCache\Cache::fetch() missed key ' . $key);
		}

		return $value;
	}
}

abstract class UcBenchApcuBasedBackend extends UcBenchAbstractBackend
{
	public function __construct(
		private readonly string $backendName,
		private readonly string $backendLabel,
		private readonly ?string $expectedSerializer,
		private readonly bool $requiresIgbinary,
	) {
		$this->initializeAvailability();
	}

	public function name(): string
	{
		return $this->backendName;
	}

	public function label(): string
	{
		return $this->backendLabel;
	}

	public function clear(): void
	{
		if (!apcu_clear_cache()) {
			throw new RuntimeException('apcu_clear_cache() failed for ' . $this->backendName);
		}
	}

	private function initializeAvailability(): void
	{
		if (!function_exists('apcu_fetch') || !function_exists('apcu_store') || !function_exists('apcu_clear_cache')) {
			$this->unavailable('APCu extension is not loaded');
			return;
		}

		if (function_exists('apcu_enabled') && !apcu_enabled()) {
			$this->unavailable('APCu is loaded but disabled; use apc.enable_cli=1 for CLI runs');
			return;
		}

		if ($this->expectedSerializer !== null) {
			$actualSerializer = (string) ini_get('apc.serializer');
			if ($actualSerializer !== $this->expectedSerializer) {
				$this->unavailable('APCu serializer is ' . $actualSerializer . '; expected ' . $this->expectedSerializer);
				return;
			}
		}

		if ($this->requiresIgbinary && (!function_exists('igbinary_serialize') || !function_exists('igbinary_unserialize'))) {
			$this->unavailable('igbinary extension is not loaded');
			return;
		}

		$this->available = true;
	}
}

final class UcBenchApcuBackend extends UcBenchApcuBasedBackend
{
	public function __construct(
		string $name = 'apcu',
		string $label = 'APCu/php store/fetch',
		?string $expectedSerializer = 'php',
		bool $requiresIgbinary = false,
	) {
		parent::__construct($name, $label, $expectedSerializer, $requiresIgbinary);
	}

	public function store(string $key, mixed $value): void
	{
		if (!apcu_store($key, $value)) {
			throw new RuntimeException('apcu_store() failed for key ' . $key);
		}
	}

	public function fetch(string $key): mixed
	{
		$success = false;
		$value = apcu_fetch($key, $success);
		if (!$success) {
			throw new RuntimeException('apcu_fetch() missed key ' . $key);
		}

		return $value;
	}
}

final class UcBenchPayloadFactory
{
	private const LARGE_ROW_COUNT = 192;
	private const CARBON_TIMELINE_COUNT = 64;
	private const SPL_COLLECTION_COUNT = 64;
	private const MULTI_KEY_CONFIG_COUNT = 32;
	private const PRODUCT_CARD_COUNT = 80;
	private const LARGE_STRING_REPEAT_COUNT = 4096;

	private const CONSTANT_ARRAY_PAYLOAD = [
		'routes' => [
			'/catalog/books/0' => ['controller' => 'CatalogController::show', 'methods' => ['GET'], 'flags' => [true, false, true], 'score' => 100],
			'/catalog/books/1' => ['controller' => 'CatalogController::show', 'methods' => ['GET'], 'flags' => [false, true, true], 'score' => 101],
			'/catalog/books/2' => ['controller' => 'CatalogController::show', 'methods' => ['GET'], 'flags' => [true, true, false], 'score' => 102],
			'/catalog/books/3' => ['controller' => 'CatalogController::show', 'methods' => ['GET'], 'flags' => [false, false, true], 'score' => 103],
			'/catalog/books/4' => ['controller' => 'CatalogController::show', 'methods' => ['GET'], 'flags' => [true, false, false], 'score' => 104],
			'/catalog/books/5' => ['controller' => 'CatalogController::show', 'methods' => ['GET'], 'flags' => [false, true, false], 'score' => 105],
			'/catalog/books/6' => ['controller' => 'CatalogController::show', 'methods' => ['GET'], 'flags' => [true, true, true], 'score' => 106],
			'/catalog/books/7' => ['controller' => 'CatalogController::show', 'methods' => ['GET'], 'flags' => [false, false, false], 'score' => 107],
		],
		'headers' => [
			'cache-control' => 'public, max-age=60',
			'x-benchmark' => 'user-cache',
		],
		'weights' => [3, 5, 8, 13, 21, 34, 55, 89],
	];

	public static function all(): array
	{
		return [
			'constant_array' => self::case(
				'Constant array',
				'Small immutable constant-array payload.',
				static fn (): mixed => self::CONSTANT_ARRAY_PAYLOAD,
				static fn (mixed $payload): string => self::constantArrayDigest($payload),
				null,
				static fn (mixed $payload, int $operation): int => self::constantArrayProbe($payload, $operation),
			),
			'route_table_read' => self::case(
				'Compiled route table read',
				'Framework-shaped compiled route table and URL generator arrays.',
				static fn (): mixed => [
					'name' => 'compiled-route-table',
					'routes' => self::buildRouteTable(),
					'generators' => self::buildUrlGenerators(),
				],
				static fn (mixed $payload): string => self::routeTableDigest($payload),
				null,
				static fn (mixed $payload, int $operation): int => self::routeTableProbe($payload, $operation),
			),
			'large_array' => self::case(
				'Large nested array',
				'Larger object-free nested array with repeated scalar leaves.',
				static fn (): mixed => [
					'name' => 'large-array',
					'rows' => self::buildLargeRows('array'),
					'flags' => [true, false, true, true],
				],
				static fn (mixed $payload): string => self::largeArrayDigest($payload),
				null,
				static fn (mixed $payload, int $operation): int => self::largeArrayProbe($payload, $operation),
			),
			'large_string' => self::case(
				'Large immutable string',
				'Large string payload that stresses byte-copy avoidance on repeated reads.',
				static fn (): mixed => self::buildLargeString(),
				static fn (mixed $payload): string => self::largeStringDigest($payload),
				null,
				static fn (mixed $payload, int $operation): int => self::largeStringProbe($payload, $operation),
			),
			'large_object_graph' => self::case(
				'Large direct userland-object graph',
				'Large payload object containing many array rows and a child object.',
				static fn (): mixed => new UcBenchLargeObjectPayload(
					'large-object-graph',
					self::buildLargeRows('object'),
					new UcBenchReferencedPayload('large-object-child', 3),
				),
				static fn (mixed $payload): string => self::largeObjectDigest($payload),
				null,
				static fn (mixed $payload, int $operation): int => self::largeObjectProbe($payload, $operation),
			),
			'metadata_object_read' => self::case(
				'Application metadata object read',
				'Application metadata object with route and service metadata arrays.',
				static fn (): mixed => self::buildMetadataPayload('application-metadata'),
				static fn (mixed $payload): string => self::metadataObjectDigest($payload),
				null,
				static fn (mixed $payload, int $operation): int => self::metadataObjectProbe($payload, $operation),
			),
			'metadata_object_fetch_mutate' => self::case(
				'Application metadata object fetch and mutate',
				'Fetches a cached userland object graph, then mutates the fetched copy.',
				static fn (): mixed => self::buildMetadataPayload('application-metadata-fetch-mutate'),
				static fn (mixed $payload): string => self::metadataObjectDigest($payload),
				static function (mixed &$payload, int $operation): void {
					self::mutateMetadataPayload($payload, $operation);
				},
				static fn (mixed $payload, int $operation): int => self::metadataObjectProbe($payload, $operation),
			),
			'sleep_wakeup_object' => self::case(
				'__sleep()/__wakeup() contract object',
				'Object routed through the serialization contract: __sleep() filters state on store, __wakeup() runs per fetch.',
				static fn (): mixed => self::buildSleepWakeupPayload('sleep-wakeup'),
				static fn (mixed $payload): string => self::sleepWakeupDigest($payload),
				null,
				static fn (mixed $payload, int $operation): int => self::sleepWakeupProbe($payload, $operation),
			),
			'serialize_magic_entity' => self::case(
				'__serialize()/__unserialize() entity',
				'Modern entity pair using the __serialize()/__unserialize() contract with a related entity.',
				static fn (): mixed => self::buildSerializeEntityPayload(),
				static fn (mixed $payload): string => self::serializeEntityDigest($payload),
				null,
				static fn (mixed $payload, int $operation): int => self::serializeEntityProbe($payload, $operation),
			),
			'unserialize_only_entity' => self::case(
				'__unserialize() only entity',
				'Userland entity declaring __unserialize() without __serialize(), matching the legacy object-data format.',
				static fn (): mixed => self::buildUnserializeOnlyEntityPayload(),
				static fn (mixed $payload): string => self::unserializeOnlyEntityDigest($payload),
				null,
				static fn (mixed $payload, int $operation): int => self::unserializeOnlyEntityProbe($payload, $operation),
			),
			'sleep_wakeup_entity_collection' => self::case(
				'__sleep() entity result-set collection',
				'ORM-style result set: 100 small entities each running the __sleep()/__wakeup() contract.',
				static fn (): mixed => self::buildSleepEntityCollection(),
				static fn (mixed $payload): string => self::sleepEntityCollectionDigest($payload),
				null,
				static fn (mixed $payload, int $operation): int => self::sleepEntityCollectionProbe($payload, $operation),
			),
			'sleep_wakeup_large_dataset' => self::case(
				'__sleep() object holding a large dataset',
				'Report-style object with the standard large row set behind the serialization contract.',
				static fn (): mixed => new UcBenchSleepWakeupPayload(
					'large-dataset',
					self::buildLargeRows('serialized'),
					['limits' => ['rate' => 500], 'source' => 'report'],
				),
				static fn (mixed $payload): string => self::sleepWakeupLargeDatasetDigest($payload),
				null,
				static fn (mixed $payload, int $operation): int => self::sleepWakeupLargeDatasetProbe($payload, $operation),
			),
			'recursive_reference_graph' => self::case(
				'Recursive parent/child reference graph',
				'Category-tree object graph with parent back-references and the __sleep()/__wakeup() contract.',
				static fn (): mixed => self::buildRecursiveReferenceGraph(),
				static fn (mixed $payload): string => self::recursiveReferenceGraphDigest($payload),
				null,
				static fn (mixed $payload, int $operation): int => self::recursiveReferenceGraphProbe($payload, $operation),
				true,
			),
			'mixed_serialization_payload' => self::case(
				'Mixed plain and contract payload',
				'Realistic cache entry mixing plain arrays, __serialize() entities, and __sleep() objects.',
				static fn (): mixed => self::buildMixedSerializationPayload(),
				static fn (mixed $payload): string => self::mixedSerializationDigest($payload),
				null,
				static fn (mixed $payload, int $operation): int => self::mixedSerializationProbe($payload, $operation),
			),
			'product_listing_view_model' => self::case(
				'Product listing view-model',
				'Production-shaped product-list cache entry mixing DTO objects, facet arrays, pagination context, and generated-at metadata.',
				static fn (): mixed => self::buildProductListingPayload(),
				static fn (mixed $payload): string => self::productListingDigest($payload),
				null,
				static fn (mixed $payload, int $operation): int => self::productListingProbe($payload, $operation),
			),
			'multi_key_config_read' => self::case(
				'Multi-key configuration read',
				'Configuration-entry map carried over from the old multi-key workload.',
				static fn (): mixed => ['entries' => self::buildConfigEntries()],
				static fn (mixed $payload): string => self::multiKeyConfigDigest($payload),
				null,
				static fn (mixed $payload, int $operation): int => self::multiKeyConfigProbe($payload, $operation),
			),
			'safe_direct_object' => self::case(
				'DateTime/DateInterval direct-restore object',
				'DateTimeImmutable, DateTimeZone, and DateInterval safe-direct state.',
				static fn (): mixed => new UcBenchFastPathPayload(
					new DateTimeImmutable('2026-05-01 10:30:45.123456', new DateTimeZone('Asia/Tokyo')),
					new DateTimeZone('Europe/Paris'),
					new DateTimeImmutable('2027-07-04 14:35:51.654321', new DateTimeZone('Europe/Paris')),
					new DateInterval('P3DT5H8M13S'),
				),
				static fn (mixed $payload): string => self::safeDirectDigest($payload),
				null,
				static fn (mixed $payload, int $operation): int => self::safeDirectProbe($payload, $operation),
			),
			'spl_collection_object' => self::case(
				'SPL collection direct-restore object',
				'ArrayObject, ArrayIterator, RecursiveArrayIterator, and SplFixedArray state.',
				static fn (): mixed => self::buildSplCollectionPayload(),
				static fn (mixed $payload): string => self::splCollectionDigest($payload),
				null,
				static fn (mixed $payload, int $operation): int => self::splCollectionProbe($payload, $operation),
			),
			'spl_linked_collection_object' => self::case(
				'SPL linked-list direct-restore object',
				'SplDoublyLinkedList, SplQueue, and SplStack state.',
				static fn (): mixed => self::buildSplLinkedCollectionPayload(),
				static fn (mixed $payload): string => self::splLinkedCollectionDigest($payload),
				null,
				static fn (mixed $payload, int $operation): int => self::splLinkedCollectionProbe($payload, $operation),
			),
			'spl_heap_object' => self::case(
				'SPL heap direct-restore object',
				'SplMinHeap, SplMaxHeap, and SplPriorityQueue state.',
				static fn (): mixed => self::buildSplHeapPayload(),
				static fn (mixed $payload): string => self::splHeapDigest($payload),
				null,
				static fn (mixed $payload, int $operation): int => self::splHeapProbe($payload, $operation),
			),
			'carbon_datetime_object' => self::case(
				'Carbon DateTime serializer object',
				'CarbonImmutable/Carbon/CarbonTimeZone object graph used to compare DateTime-derived restore costs.',
				static fn (): mixed => new UcBenchCarbonDateTimePayload(
					new Carbon\CarbonImmutable('2026-05-01 10:30:45.123456', new Carbon\CarbonTimeZone('Asia/Tokyo')),
					new Carbon\Carbon('2027-07-04 14:35:51.654321', new Carbon\CarbonTimeZone('Europe/Paris')),
					new Carbon\CarbonTimeZone('Europe/Paris'),
					self::buildCarbonTimeline(),
				),
				static fn (mixed $payload): string => self::carbonDateTimeDigest($payload),
				null,
				static fn (mixed $payload, int $operation): int => self::carbonDateTimeProbe($payload, $operation),
			),
			'carbon_model_object' => self::case(
				'Model with Carbon properties',
				'Dummy model objects with three Carbon properties: createdAt, updatedAt, and publishedAt.',
				static fn (): mixed => self::buildCarbonModelObject(),
				static fn (mixed $payload): string => self::carbonModelDigest($payload),
				null,
				static fn (mixed $payload, int $operation): int => self::carbonModelProbe($payload, $operation),
			),
			'raw_datetime_object' => self::case(
				'Raw DateTime serializer object',
				'DateTimeImmutable/DateTime/DateTimeZone object graph mirroring carbon_datetime_object with native date types.',
				static fn (): mixed => new UcBenchRawDateTimePayload(
					new DateTimeImmutable('2026-05-01 10:30:45.123456', new DateTimeZone('Asia/Tokyo')),
					new DateTime('2027-07-04 14:35:51.654321', new DateTimeZone('Europe/Paris')),
					new DateTimeZone('Europe/Paris'),
					self::buildRawTimeline(),
				),
				static fn (mixed $payload): string => self::rawDateTimeDigest($payload),
				null,
				static fn (mixed $payload, int $operation): int => self::rawDateTimeProbe($payload, $operation),
			),
			'raw_model_object' => self::case(
				'Model with DateTime properties',
				'Dummy model objects with three native DateTime properties: createdAt, updatedAt, and publishedAt.',
				static fn (): mixed => self::buildRawModelObject(),
				static fn (mixed $payload): string => self::rawModelDigest($payload),
				null,
				static fn (mixed $payload, int $operation): int => self::rawModelProbe($payload, $operation),
			),
			'serialized_cycle_object' => self::case(
				'Serialized cyclic object',
				'Cyclic object graph workload used to exercise serialization fallback behavior.',
				static fn (): mixed => self::buildCyclePayload('serialized-root', 'serialized-peer', 1),
				static fn (mixed $payload): string => self::serializedCycleDigest($payload),
				null,
				static fn (mixed $payload, int $operation): int => self::cycleProbe($payload, $operation),
				true,
			),
			'reference_assignment_object' => self::case(
				'Assigned object with child reference',
				'Userland object with a nested referenced child object.',
				static fn (): mixed => new UcBenchReferencePayload(
					'reference-root',
					new UcBenchReferencedPayload('reference-child', 1),
				),
				static fn (mixed $payload): string => self::referenceDigest($payload),
				null,
				static fn (mixed $payload, int $operation): int => self::referenceProbe($payload, $operation),
			),
			'cycle_assignment_object' => self::case(
				'Assigned cyclic object',
				'Cyclic userland object graph with identity-preserving peer reference.',
				static fn (): mixed => self::buildCyclePayload('cycle-root', 'cycle-peer', 1),
				static fn (mixed $payload): string => self::cycleDigest($payload),
				null,
				static fn (mixed $payload, int $operation): int => self::cycleProbe($payload, $operation),
				true,
			),
			'nested_array_assignment' => self::case(
				'Assigned nested array mutation',
				'Nested array payload used by the old assignment mutation workload.',
				static fn (): mixed => [
					'name' => 'nested-array-root',
					'nodes' => self::buildNestedArrayNodes(1),
				],
				static fn (mixed $payload): string => self::nestedArrayDigest($payload),
				null,
				static fn (mixed $payload, int $operation): int => self::nestedArrayProbe($payload, $operation),
			),
		];
	}

	public static function select(?array $names): array
	{
		$all = self::all();
		if ($names === null) {
			return $all;
		}

		$selected = [];
		foreach ($names as $name) {
			if (!isset($all[$name])) {
				throw new InvalidArgumentException('Unknown payload case: ' . $name);
			}
			$selected[$name] = $all[$name];
		}

		return $selected;
	}

	private static function case(string $label, string $description, callable $build, callable $digest, ?callable $mutate = null, ?callable $probe = null, bool $collectCyclesAfterFetch = false): array
	{
		return [
			'label' => $label,
			'description' => $description,
			'build' => $build,
			'digest' => $digest,
			'mutate' => $mutate,
			'probe' => $probe,
			'collect_cycles_after_fetch' => $collectCyclesAfterFetch,
		];
	}

	private static function buildMetadataPayload(string $name): UcBenchMetadataPayload
	{
		return new UcBenchMetadataPayload(
			$name,
			self::buildRouteTable(),
			self::buildServiceMetadata(),
			new UcBenchReferencedPayload('metadata-owner', 7),
		);
	}

	private static function mutateMetadataPayload(mixed &$payload, int $operation): void
	{
		if (!$payload instanceof UcBenchMetadataPayload) {
			throw new RuntimeException('Fetch-mutation payload has an unexpected type');
		}

		$payload->owner->label = 'metadata-owner-mutated-' . ($operation % 17);
		$payload->owner->revision = 100000 + $operation;
	}

	private static function buildCyclePayload(string $rootName, string $peerName, int $revision): UcBenchCyclePayload
	{
		$root = new UcBenchCyclePayload($rootName, $revision);
		$peer = new UcBenchCyclePayload($peerName, $revision);
		$root->peer = $peer;
		$peer->peer = $root;

		return $root;
	}

	private static function buildCarbonTimeline(): array
	{
		$timeline = [];
		for ($index = 0; $index < self::CARBON_TIMELINE_COUNT; $index++) {
			$timezone = new Carbon\CarbonTimeZone($index % 2 === 0 ? 'Asia/Tokyo' : 'Europe/Paris');
			$base = new Carbon\CarbonImmutable('2026-05-01 10:30:45.123456', $timezone);
			$timeline[] = [
				'created' => $base->addDays($index),
				'updated' => Carbon\Carbon::instance($base->addDays($index + 1)->toMutable()),
				'timezone' => $timezone,
				'label' => 'carbon-timeline-' . $index,
			];
		}

		return $timeline;
	}

	private static function buildRawTimeline(): array
	{
		$timeline = [];
		for ($index = 0; $index < self::CARBON_TIMELINE_COUNT; $index++) {
			$timezone = new DateTimeZone($index % 2 === 0 ? 'Asia/Tokyo' : 'Europe/Paris');
			$base = new DateTimeImmutable('2026-05-01 10:30:45.123456', $timezone);
			$timeline[] = [
				'created' => $base->modify('+' . $index . ' days'),
				'updated' => DateTime::createFromImmutable($base->modify('+' . ($index + 1) . ' days')),
				'timezone' => $timezone,
				'label' => 'raw-timeline-' . $index,
			];
		}

		return $timeline;
	}

	private static function buildSplCollectionPayload(): UcBenchSplCollectionPayload
	{
		$fixed = new SplFixedArray(self::SPL_COLLECTION_COUNT);
		$rows = [];
		for ($index = 0; $index < self::SPL_COLLECTION_COUNT; $index++) {
			$row = [
				'id' => $index,
				'label' => 'spl-row-' . $index,
				'score' => 1000 + ($index * 7),
			];
			$fixed[$index] = $row;
			$rows['row_' . $index] = $row;
		}

		return new UcBenchSplCollectionPayload([
			'fixed' => $fixed,
			'map' => new ArrayObject($rows, ArrayObject::ARRAY_AS_PROPS),
			'iterator' => new ArrayIterator($rows),
			'recursive' => new RecursiveArrayIterator([
				'branch' => [
					'leaf_17' => ['score' => 17017],
					'leaf_31' => ['score' => 31031],
				],
			]),
		], 'spl-collection', 11);
	}

	private static function buildSplLinkedCollectionPayload(): UcBenchSplCollectionPayload
	{
		$list = new SplDoublyLinkedList();
		$queue = new SplQueue();
		$stack = new SplStack();

		for ($index = 0; $index < self::SPL_COLLECTION_COUNT; $index++) {
			$row = [
				'id' => $index,
				'label' => 'spl-linked-row-' . $index,
				'score' => 2000 + ($index * 11),
			];
			$list->push($row);
			$queue->enqueue($row);
			$stack->push($row);
		}

		return new UcBenchSplCollectionPayload([
			'list' => $list,
			'queue' => $queue,
			'stack' => $stack,
		], 'spl-linked-collection', 13);
	}

	private static function buildSplHeapPayload(): UcBenchSplCollectionPayload
	{
		$min = new SplMinHeap();
		$max = new SplMaxHeap();
		$priorityQueue = new SplPriorityQueue();
		$priorityQueue->setExtractFlags(SplPriorityQueue::EXTR_BOTH);

		for ($index = 0; $index < self::SPL_COLLECTION_COUNT; $index++) {
			$row = [
				'id' => $index,
				'label' => 'spl-heap-row-' . $index,
				'score' => 3000 + ($index * 13),
			];
			$heapEntry = [
				'priority' => $index,
				'row' => $row,
			];
			$min->insert($heapEntry);
			$max->insert($heapEntry);
			$priorityQueue->insert($row, $index);
		}

		return new UcBenchSplCollectionPayload([
			'min' => $min,
			'max' => $max,
			'priority_queue' => $priorityQueue,
		], 'spl-heap', 17);
	}

	private static function buildLargeRows(string $label): array
	{
		$rows = [];
		for ($index = 0; $index < self::LARGE_ROW_COUNT; $index++) {
			$rows[] = [
				'id' => $index,
				'path' => '/catalog/large/' . $index,
				'controller' => 'LargeCatalogController::show',
				'title' => $label . '-route-' . $index . '-' . str_repeat((string) ($index % 10), 64),
				'flags' => [$index % 2 === 0, $index % 3 === 0, $index % 5 === 0],
				'weights' => [$index, $index + 1, $index + 2, $index + 3, $index + 5],
				'metadata' => [
					'tenant' => 'tenant-' . ($index % 8),
					'locale' => $index % 2 === 0 ? 'ja_JP' : 'en_US',
					'tags' => ['cache', 'benchmark', 'row-' . ($index % 16)],
				],
			];
		}

		return $rows;
	}

	private static function buildLargeString(): string
	{
		return str_repeat(
			'UserCache-large-string-payload-0123456789abcdef:',
			self::LARGE_STRING_REPEAT_COUNT,
		);
	}

	private static function buildRouteTable(): array
	{
		$routes = [];
		for ($index = 0; $index < self::LARGE_ROW_COUNT * 2; $index++) {
			$routes['route_' . $index] = [
				'path' => '/tenant/{tenant}/catalog/' . $index . '/{slug}',
				'controller' => 'App\\Controller\\CatalogController::show' . ($index % 8),
				'methods' => $index % 3 === 0 ? ['GET', 'HEAD'] : ['GET'],
				'variables' => ['tenant', 'slug'],
				'defaults' => [
					'_locale' => $index % 2 === 0 ? 'ja' : 'en',
					'_format' => 'html',
				],
				'requirements' => [
					'tenant' => '[a-z0-9_-]+',
					'slug' => '[a-z0-9-]+',
				],
				'score' => 1000 + $index,
			];
		}

		return $routes;
	}

	private static function buildUrlGenerators(): array
	{
		$generators = [];
		for ($index = 0; $index < 64; $index++) {
			$generators['route_' . $index] = [
				'prefix' => '/tenant/' . ($index % 12),
				'tokens' => ['text', 'variable', 'separator', 'variable'],
				'host' => 'example' . ($index % 4) . '.test',
			];
		}

		return $generators;
	}

	private static function buildServiceMetadata(): array
	{
		$services = [];
		for ($index = 0; $index < 96; $index++) {
			$services['service.' . $index] = [
				'class' => 'App\\Service\\GeneratedService' . $index,
				'factory' => $index % 5 === 0 ? ['container', 'make'] : null,
				'tags' => ['user_cache.warmable', 'tenant.' . ($index % 8)],
				'arguments' => ['@logger', '%kernel.project_dir%', $index],
			];
		}

		return $services;
	}

	private static function buildConfigEntries(): array
	{
		$entries = [];
		for ($index = 0; $index < self::MULTI_KEY_CONFIG_COUNT; $index++) {
			$entries['config_' . $index] = [
				'name' => 'config_' . $index,
				'tenant' => 'tenant-' . ($index % 8),
				'features' => [
					'search' => $index % 2 === 0,
					'recommendations' => $index % 3 === 0,
					'checkout' => true,
				],
				'limits' => [
					'items' => 100 + $index,
					'burst' => 10 + ($index % 5),
				],
				'checksum_seed' => str_repeat((string) ($index % 10), 48),
			];
		}

		return $entries;
	}

	private static function buildNestedArrayNodes(int $revision): array
	{
		$nodes = [];
		for ($index = 0; $index < 24; $index++) {
			$children = [];
			for ($childIndex = 0; $childIndex < 4; $childIndex++) {
				$children[] = [
					'label' => 'node-' . $index . '-child-' . $childIndex,
					'enabled' => $childIndex % 2 === 0,
				];
			}

			$nodes[] = [
				'label' => 'node-' . $index,
				'revision' => $revision,
				'children' => $children,
			];
		}

		return $nodes;
	}

	private static function buildCarbonModelObject(): array
	{
		$models = [];
		$base = Carbon\CarbonImmutable::parse('2026-06-29 00:00:00', 'UTC');

		for ($i = 0; $i < 48; $i++) {
			$models[] = new UcBenchCarbonModel(
				1000 + $i,
				'model_' . $i,
				$base->subDays($i)->setTimezone('UTC'),
				Carbon\Carbon::parse('2026-06-29 12:00:00', 'UTC')->addMinutes($i * 7),
				$base->addDays($i % 21)->setTimezone('America/Los_Angeles'),
				[
					'status' => ['draft', 'published', 'archived'][$i % 3],
					'score' => $i * 17,
					'flags' => ['featured' => $i % 5 === 0, 'locked' => $i % 7 === 0],
				],
			);
		}

		return [
			'models' => $models,
			'count' => count($models),
		];
	}

	private static function buildRawModelObject(): array
	{
		$models = [];
		$base = new DateTimeImmutable('2026-06-29 00:00:00', new DateTimeZone('UTC'));

		for ($i = 0; $i < 48; $i++) {
			$models[] = new UcBenchRawModel(
				1000 + $i,
				'model_' . $i,
				$base->modify('-' . $i . ' days')->setTimezone(new DateTimeZone('UTC')),
				(new DateTime('2026-06-29 12:00:00', new DateTimeZone('UTC')))->modify('+' . ($i * 7) . ' minutes'),
				$base->modify('+' . ($i % 21) . ' days')->setTimezone(new DateTimeZone('America/Los_Angeles')),
				[
					'status' => ['draft', 'published', 'archived'][$i % 3],
					'score' => $i * 17,
					'flags' => ['featured' => $i % 5 === 0, 'locked' => $i % 7 === 0],
				],
			);
		}

		return [
			'models' => $models,
			'count' => count($models),
		];
	}

	private static function constantArrayDigest(mixed $payload): string
	{
		if (!is_array($payload)) {
			throw new RuntimeException('Constant array payload has an unexpected type');
		}

		$route = $payload['routes']['/catalog/books/6'] ?? null;
		if (!is_array($route)) {
			throw new RuntimeException('Constant array payload is incomplete');
		}

		return $route['controller'] . ':' . $route['score'] . ':' . (int) $route['flags'][2] . ':' . $payload['weights'][7];
	}

	private static function routeTableDigest(mixed $payload): string
	{
		if (!is_array($payload) || !isset($payload['routes']['route_257'])) {
			throw new RuntimeException('Route table payload has an unexpected type');
		}

		$route = $payload['routes']['route_257'];
		$generator = $payload['generators']['route_17'] ?? null;
		if (!is_array($route) || !is_array($generator)) {
			throw new RuntimeException('Route table payload is incomplete');
		}

		return $payload['name'] . ':' . $route['controller'] . ':' . $route['score'] . ':' . $generator['host'];
	}

	private static function largeArrayDigest(mixed $payload): string
	{
		if (!is_array($payload)) {
			throw new RuntimeException('Large array payload has an unexpected type');
		}

		$row = $payload['rows'][96] ?? null;
		if (!is_array($row)) {
			throw new RuntimeException('Large array payload is incomplete');
		}

		return $payload['name'] . ':' . $row['path'] . ':' . $row['title'] . ':' . $row['weights'][3] . ':' . $row['metadata']['tenant'];
	}

	private static function largeStringDigest(mixed $payload): string
	{
		if (!is_string($payload)) {
			throw new RuntimeException('Large string payload has an unexpected type');
		}

		return strlen($payload) . ':' . substr($payload, 0, 24) . ':' . substr($payload, -16);
	}

	private static function constantArrayProbe(mixed $payload, int $operation): int
	{
		if (!is_array($payload)) {
			throw new RuntimeException('Constant array payload has an unexpected type');
		}

		$routeIndex = $operation % 8;
		$route = $payload['routes']['/catalog/books/' . $routeIndex] ?? null;
		if (!is_array($route)) {
			throw new RuntimeException('Constant array payload is incomplete');
		}

		return $route['score'] + $payload['weights'][$routeIndex];
	}

	private static function routeTableProbe(mixed $payload, int $operation): int
	{
		if (!is_array($payload) || !isset($payload['routes'])) {
			throw new RuntimeException('Route table payload has an unexpected type');
		}

		$routeIndex = ($operation * 17) % (self::LARGE_ROW_COUNT * 2);
		$route = $payload['routes']['route_' . $routeIndex] ?? null;
		if (!is_array($route)) {
			throw new RuntimeException('Route table payload is incomplete');
		}

		return $route['score'] + strlen($route['controller']) + count($route['methods']);
	}

	private static function largeArrayProbe(mixed $payload, int $operation): int
	{
		if (!is_array($payload)) {
			throw new RuntimeException('Large array payload has an unexpected type');
		}

		$row = $payload['rows'][($operation * 13) % self::LARGE_ROW_COUNT] ?? null;
		if (!is_array($row)) {
			throw new RuntimeException('Large array payload is incomplete');
		}

		return $row['id'] + $row['weights'][3] + strlen($row['metadata']['tenant']);
	}

	private static function largeStringProbe(mixed $payload, int $operation): int
	{
		if (!is_string($payload)) {
			throw new RuntimeException('Large string payload has an unexpected type');
		}

		return ord($payload[($operation * 97) % strlen($payload)]);
	}

	private static function largeObjectProbe(mixed $payload, int $operation): int
	{
		if (!$payload instanceof UcBenchLargeObjectPayload) {
			throw new RuntimeException('Large object payload has an unexpected type');
		}

		$row = $payload->rows[($operation * 19) % self::LARGE_ROW_COUNT] ?? null;
		if (!is_array($row)) {
			throw new RuntimeException('Large object payload is incomplete');
		}

		return $payload->child->revision + $row['weights'][2] + strlen($row['title']);
	}

	private static function metadataObjectProbe(mixed $payload, int $operation): int
	{
		if (!$payload instanceof UcBenchMetadataPayload) {
			throw new RuntimeException('Metadata payload has an unexpected type');
		}

		$routeIndex = ($operation * 17) % (self::LARGE_ROW_COUNT * 2);
		$serviceIndex = ($operation * 11) % 96;
		$route = $payload->routes['route_' . $routeIndex] ?? null;
		$service = $payload->services['service.' . $serviceIndex] ?? null;
		if (!is_array($route) || !is_array($service)) {
			throw new RuntimeException('Metadata payload is incomplete');
		}

		return $payload->owner->revision + $route['score'] + strlen($service['class']);
	}

	private static function buildSleepWakeupPayload(string $name): UcBenchSleepWakeupPayload
	{
		$routes = [];
		for ($i = 0; $i < 48; $i++) {
			$routes['route_' . $i] = [
				'path' => '/api/v1/resource/' . $i,
				'controller' => 'App\\Controller\\Resource' . ($i % 7) . 'Controller::show',
				'methods' => ['GET', 'POST'],
				'score' => $i * 3,
			];
		}

		$settings = [
			'cache' => ['ttl' => 300, 'jitter' => 15, 'tags' => ['a', 'b', 'c']],
			'limits' => ['rate' => 1000, 'burst' => 50],
			'flags' => array_fill_keys(range('a', 'p'), true),
		];

		return new UcBenchSleepWakeupPayload($name, $routes, $settings);
	}

	private static function sleepWakeupDigest(mixed $payload): string
	{
		if (!$payload instanceof UcBenchSleepWakeupPayload) {
			throw new RuntimeException('Sleep/wakeup payload has an unexpected type');
		}

		return sha1($payload->name . '|' . count($payload->routes) . '|' . json_encode($payload->settings));
	}

	private static function sleepWakeupProbe(mixed $payload, int $operation): int
	{
		if (!$payload instanceof UcBenchSleepWakeupPayload) {
			throw new RuntimeException('Sleep/wakeup payload has an unexpected type');
		}

		$route = $payload->routes['route_' . ($operation % 48)] ?? null;
		if (!is_array($route)) {
			throw new RuntimeException('Sleep/wakeup payload is incomplete');
		}

		return $route['score'] + strlen($route['controller']) + $payload->settings['limits']['rate'];
	}

	private static function buildSerializeEntityPayload(): UcBenchSerializeEntity
	{
		$attributes = [];
		for ($i = 0; $i < 40; $i++) {
			$attributes['attr_' . $i] = [
				'value' => 'value-' . $i . '-' . str_repeat('e', 24),
				'rank' => $i * 7,
			];
		}

		$related = new UcBenchSerializeEntity(9001, 'related-entity', ['kind' => ['value' => 'child', 'rank' => 1]]);

		return new UcBenchSerializeEntity(4242, 'primary-entity', $attributes, $related);
	}

	private static function serializeEntityDigest(mixed $payload): string
	{
		if (!$payload instanceof UcBenchSerializeEntity) {
			throw new RuntimeException('Serialize-entity payload has an unexpected type');
		}

		return sha1($payload->summary() . '|' . ($payload->relatedEntity()?->summary() ?? -1));
	}

	private static function serializeEntityProbe(mixed $payload, int $operation): int
	{
		if (!$payload instanceof UcBenchSerializeEntity) {
			throw new RuntimeException('Serialize-entity payload has an unexpected type');
		}

		return $payload->summary() + ($operation % 13);
	}

	private static function buildUnserializeOnlyEntityPayload(): UcBenchUnserializeOnlyEntity
	{
		$attributes = [];
		for ($i = 0; $i < 40; $i++) {
			$attributes['attr_' . $i] = [
				'value' => 'value-' . $i . '-' . str_repeat('u', 24),
				'rank' => $i * 11,
			];
		}

		$related = new UcBenchUnserializeOnlyEntity(7001, 'related-unserialize-only', ['kind' => ['value' => 'child', 'rank' => 1]]);

		return new UcBenchUnserializeOnlyEntity(31337, 'primary-unserialize-only', $attributes, $related);
	}

	private static function unserializeOnlyEntityDigest(mixed $payload): string
	{
		if (!$payload instanceof UcBenchUnserializeOnlyEntity) {
			throw new RuntimeException('Unserialize-only entity payload has an unexpected type');
		}

		return sha1($payload->summary() . '|' . ($payload->relatedEntity()?->summary() ?? -1));
	}

	private static function unserializeOnlyEntityProbe(mixed $payload, int $operation): int
	{
		if (!$payload instanceof UcBenchUnserializeOnlyEntity) {
			throw new RuntimeException('Unserialize-only entity payload has an unexpected type');
		}

		return $payload->summary() + ($operation % 13);
	}

	private static function buildSleepEntityCollection(): array
	{
		$entities = [];
		for ($i = 0; $i < 100; $i++) {
			$entities[] = new UcBenchSleepEntity(
				$i,
				'user' . $i . '@example.test',
				$i % 3 === 0 ? ['admin', 'editor'] : ['viewer'],
				[
					'display_name' => 'User ' . $i,
					'preferences' => ['locale' => $i % 2 === 0 ? 'ja_JP' : 'en_US', 'theme' => 'dark'],
					'counters' => [$i, $i * 2, $i * 3],
				],
			);
		}

		return $entities;
	}

	private static function sleepEntityCollectionDigest(mixed $payload): string
	{
		if (!is_array($payload) || count($payload) !== 100) {
			throw new RuntimeException('Sleep-entity collection has an unexpected shape');
		}

		$sum = 0;
		foreach ($payload as $entity) {
			if (!$entity instanceof UcBenchSleepEntity) {
				throw new RuntimeException('Sleep-entity collection element has an unexpected type');
			}

			$sum += $entity->id + count($entity->roles) + strlen($entity->email);
		}

		return sha1((string) $sum);
	}

	private static function sleepEntityCollectionProbe(mixed $payload, int $operation): int
	{
		$entity = $payload[$operation % 100] ?? null;
		if (!$entity instanceof UcBenchSleepEntity) {
			throw new RuntimeException('Sleep-entity collection is incomplete');
		}

		return $entity->id + count($entity->profile['counters']) + strlen($entity->profile['display_name']);
	}

	private static function sleepWakeupLargeDatasetDigest(mixed $payload): string
	{
		if (!$payload instanceof UcBenchSleepWakeupPayload) {
			throw new RuntimeException('Large-dataset payload has an unexpected type');
		}

		return sha1($payload->name . '|' . count($payload->routes) . '|' . ($payload->routes[0]['title'] ?? ''));
	}

	private static function sleepWakeupLargeDatasetProbe(mixed $payload, int $operation): int
	{
		if (!$payload instanceof UcBenchSleepWakeupPayload) {
			throw new RuntimeException('Large-dataset payload has an unexpected type');
		}

		$row = $payload->routes[$operation % self::LARGE_ROW_COUNT] ?? null;
		if (!is_array($row)) {
			throw new RuntimeException('Large-dataset payload is incomplete');
		}

		return $row['id'] + strlen($row['title']) + count($row['weights']);
	}

	private static function buildRecursiveReferenceGraph(): UcBenchTreeNode
	{
		$root = new UcBenchTreeNode('root', ['depth' => 0]);
		for ($i = 0; $i < 12; $i++) {
			$category = new UcBenchTreeNode('category-' . $i, ['depth' => 1, 'index' => $i]);
			$category->parent = $root;
			for ($j = 0; $j < 6; $j++) {
				$item = new UcBenchTreeNode('item-' . $i . '-' . $j, [
					'depth' => 2,
					'sku' => sprintf('SKU-%03d-%02d', $i, $j),
					'tags' => ['tree', 'node', 'leaf-' . ($j % 3)],
				]);
				$item->parent = $category;
				$category->children[] = $item;
			}
			$root->children[] = $category;
		}

		return $root;
	}

	private static function recursiveReferenceGraphDigest(mixed $payload): string
	{
		if (!$payload instanceof UcBenchTreeNode) {
			throw new RuntimeException('Recursive graph payload has an unexpected type');
		}

		/* Cycle-safe digest: back-references are validated by identity, not
		 * by re-walking them. */
		$parts = [$payload->name, count($payload->children)];
		foreach ($payload->children as $category) {
			$parts[] = $category->name;
			$parts[] = $category->parent === $payload ? 'p1' : 'p0';
			foreach ($category->children as $item) {
				$parts[] = $item->attributes['sku'];
				$parts[] = $item->parent === $category ? 'p1' : 'p0';
			}
		}

		return sha1(implode('|', $parts));
	}

	private static function recursiveReferenceGraphProbe(mixed $payload, int $operation): int
	{
		if (!$payload instanceof UcBenchTreeNode) {
			throw new RuntimeException('Recursive graph payload has an unexpected type');
		}

		$category = $payload->children[$operation % 12] ?? null;
		$item = $category?->children[$operation % 6] ?? null;
		if ($item === null || $item->parent !== $category || $category->parent !== $payload) {
			throw new RuntimeException('Recursive graph back-references are broken');
		}

		return strlen($item->attributes['sku']) + $category->attributes['index'];
	}

	private static function buildMixedSerializationPayload(): array
	{
		return [
			'config' => ['ttl' => 600, 'tags' => ['mixed', 'payload'], 'limits' => ['rate' => 250, 'burst' => 25]],
			'entity' => self::buildSerializeEntityPayload(),
			'report' => new UcBenchSleepWakeupPayload('mixed-report', ['route_0' => ['score' => 3]], ['flags' => []]),
			'rows' => array_slice(self::buildLargeRows('mixed'), 0, 24),
		];
	}

	private static function buildProductListingPayload(): UcBenchProductListingPayload
	{
		$products = [];
		for ($index = 0; $index < self::PRODUCT_CARD_COUNT; $index++) {
			$products[] = new UcBenchProductCard(
				100000 + $index,
				sprintf('SKU-%05d', $index),
				'Benchmark Product ' . $index . ' ' . str_repeat(chr(65 + ($index % 26)), 12),
				1200 + ($index * 37),
				[
					'new' => $index % 7 === 0,
					'discount' => $index % 5 === 0 ? 10 + ($index % 4) * 5 : 0,
					'tags' => ['tenant-' . ($index % 8), 'category-' . ($index % 12)],
				],
				new UcBenchReferencedPayload('seller-' . ($index % 9), 500 + ($index % 9)),
			);
		}

		$facets = [];
		for ($facet = 0; $facet < 12; $facet++) {
			$buckets = [];
			for ($bucket = 0; $bucket < 8; $bucket++) {
				$buckets[] = [
					'value' => 'facet-' . $facet . '-bucket-' . $bucket,
					'count' => 1000 - ($facet * 17) - ($bucket * 11),
					'selected' => $bucket === $facet % 8,
				];
			}
			$facets['facet_' . $facet] = $buckets;
		}

		return new UcBenchProductListingPayload(
			'tenant-3:catalog:query:summer-sale',
			new DateTimeImmutable('2026-06-30 09:15:00', new DateTimeZone('UTC')),
			$products,
			$facets,
			[
				'tenant' => 'tenant-3',
				'locale' => 'ja_JP',
				'currency' => 'JPY',
				'page' => 3,
				'per_page' => self::PRODUCT_CARD_COUNT,
				'sort' => ['field' => 'popularity', 'direction' => 'desc'],
			],
		);
	}

	private static function mixedSerializationDigest(mixed $payload): string
	{
		if (!is_array($payload) || !$payload['entity'] instanceof UcBenchSerializeEntity || !$payload['report'] instanceof UcBenchSleepWakeupPayload) {
			throw new RuntimeException('Mixed serialization payload has an unexpected shape');
		}

		return sha1($payload['entity']->summary() . '|' . $payload['report']->name . '|' . count($payload['rows']));
	}

	private static function productListingDigest(mixed $payload): string
	{
		if (!$payload instanceof UcBenchProductListingPayload || count($payload->products) !== self::PRODUCT_CARD_COUNT) {
			throw new RuntimeException('Product listing payload has an unexpected type');
		}
		$product = $payload->products[37] ?? null;
		$facet = $payload->facets['facet_5'][3] ?? null;
		if (!$product instanceof UcBenchProductCard || !is_array($facet)) {
			throw new RuntimeException('Product listing payload is incomplete');
		}

		return $payload->cacheKey . ':' . $payload->generatedAt->format(DATE_ATOM)
			. ':' . $product->sku . ':' . $product->seller->label
			. ':' . $facet['value'] . ':' . $payload->context['sort']['field'];
	}

	private static function mixedSerializationProbe(mixed $payload, int $operation): int
	{
		$row = $payload['rows'][$operation % 24] ?? null;
		if (!is_array($row)) {
			throw new RuntimeException('Mixed serialization payload is incomplete');
		}

		return $payload['entity']->summary() + $row['id'] + $payload['config']['limits']['rate'];
	}

	private static function productListingProbe(mixed $payload, int $operation): int
	{
		if (!$payload instanceof UcBenchProductListingPayload) {
			throw new RuntimeException('Product listing payload has an unexpected type');
		}
		$product = $payload->products[($operation * 17) % self::PRODUCT_CARD_COUNT] ?? null;
		$facet = $payload->facets['facet_' . ($operation % 12)][($operation * 3) % 8] ?? null;
		if (!$product instanceof UcBenchProductCard || !is_array($facet)) {
			throw new RuntimeException('Product listing payload is incomplete');
		}

		return $product->id
			+ $product->priceCents
			+ $product->seller->revision
			+ (int) $facet['count']
			+ strlen($payload->context['locale']);
	}

	private static function multiKeyConfigProbe(mixed $payload, int $operation): int
	{
		if (!is_array($payload) || !isset($payload['entries'])) {
			throw new RuntimeException('Multi-key config payload has an unexpected type');
		}

		$entry = $payload['entries']['config_' . ($operation % self::MULTI_KEY_CONFIG_COUNT)] ?? null;
		if (!is_array($entry)) {
			throw new RuntimeException('Multi-key config payload is incomplete');
		}

		return $entry['limits']['items'] + strlen($entry['tenant']) + (int) $entry['features']['recommendations'];
	}

	private static function safeDirectProbe(mixed $payload, int $operation): int
	{
		if (!$payload instanceof UcBenchFastPathPayload) {
			throw new RuntimeException('SafeDirect payload has an unexpected type');
		}

		return (int) $payload->createdAt->format('U')
			+ strlen($payload->timezone->getName())
			+ (int) $payload->expiresAt->format('s')
			+ (int) $payload->gracePeriod->format('%h');
	}

	private static function splCollectionProbe(mixed $payload, int $operation): int
	{
		if (!$payload instanceof UcBenchSplCollectionPayload) {
			throw new RuntimeException('SPL collection payload has an unexpected type');
		}

		$index = ($operation * 13) % self::SPL_COLLECTION_COUNT;
		$fixed = $payload['fixed'] ?? null;
		$map = $payload['map'] ?? null;
		if (!$fixed instanceof SplFixedArray || !$map instanceof ArrayObject) {
			throw new RuntimeException('SPL collection payload is incomplete');
		}
		$fixedRow = $fixed[$index];
		$mapRow = $map['row_' . (($operation * 17) % self::SPL_COLLECTION_COUNT)];
		if (!is_array($fixedRow) || !is_array($mapRow)) {
			throw new RuntimeException('SPL collection rows are incomplete');
		}

		return $fixed->getSize() + $fixedRow['score'] + $mapRow['score'];
	}

	private static function splLinkedCollectionProbe(mixed $payload, int $operation): int
	{
		if (!$payload instanceof UcBenchSplCollectionPayload) {
			throw new RuntimeException('SPL linked collection payload has an unexpected type');
		}

		$list = $payload['list'] ?? null;
		$queue = $payload['queue'] ?? null;
		if (!$list instanceof SplDoublyLinkedList || !$queue instanceof SplQueue) {
			throw new RuntimeException('SPL linked collection payload is incomplete');
		}
		$listRow = $list[($operation * 13) % self::SPL_COLLECTION_COUNT];
		$queueRow = $queue[($operation * 17) % self::SPL_COLLECTION_COUNT];
		if (!is_array($listRow) || !is_array($queueRow)) {
			throw new RuntimeException('SPL linked collection rows are incomplete');
		}

		return $list->count() + $queue->count() + $listRow['score'] + $queueRow['score'];
	}

	private static function splHeapProbe(mixed $payload, int $operation): int
	{
		if (!$payload instanceof UcBenchSplCollectionPayload) {
			throw new RuntimeException('SPL heap payload has an unexpected type');
		}

		$min = $payload['min'] ?? null;
		$max = $payload['max'] ?? null;
		$priorityQueue = $payload['priority_queue'] ?? null;
		if (!$min instanceof SplMinHeap || !$max instanceof SplMaxHeap || !$priorityQueue instanceof SplPriorityQueue) {
			throw new RuntimeException('SPL heap payload is incomplete');
		}
		$minTop = $min->top();
		$maxTop = $max->top();
		$priorityTop = $priorityQueue->top();
		if (!is_array($minTop) || !is_array($maxTop) || !is_array($priorityTop)) {
			throw new RuntimeException('SPL heap rows are incomplete');
		}

		return $min->count() + $max->count() + $priorityQueue->count()
			+ $minTop['row']['score'] + $maxTop['row']['score'] + $priorityTop['priority'];
	}

	private static function carbonDateTimeProbe(mixed $payload, int $operation): int
	{
		if (!$payload instanceof UcBenchCarbonDateTimePayload) {
			throw new RuntimeException('Carbon DateTime payload has an unexpected type');
		}

		$item = $payload->timeline[$operation % count($payload->timeline)] ?? null;
		if (!is_array($item) || !$item['created'] instanceof Carbon\CarbonInterface) {
			throw new RuntimeException('Carbon timeline payload is incomplete');
		}

		return (int) $payload->createdAt->format('U')
			+ (int) $payload->updatedAt->format('s')
			+ (int) $item['created']->format('d')
			+ strlen($payload->timezone->getName());
	}

	private static function carbonModelProbe(mixed $payload, int $operation): int
	{
		if (!is_array($payload) || !isset($payload['models']) || !is_array($payload['models'])) {
			throw new RuntimeException('Carbon model payload has an unexpected type');
		}

		$model = $payload['models'][$operation % count($payload['models'])] ?? null;
		if (!$model instanceof UcBenchCarbonModel) {
			throw new RuntimeException('Carbon model payload is incomplete');
		}

		return $model->id
			+ (int) $model->createdAt->format('d')
			+ (int) $model->updatedAt->format('H')
			+ $model->attributes['score'];
	}

	private static function rawDateTimeProbe(mixed $payload, int $operation): int
	{
		if (!$payload instanceof UcBenchRawDateTimePayload) {
			throw new RuntimeException('Raw DateTime payload has an unexpected type');
		}

		$item = $payload->timeline[$operation % count($payload->timeline)] ?? null;
		if (!is_array($item) || !$item['created'] instanceof DateTimeInterface) {
			throw new RuntimeException('Raw timeline payload is incomplete');
		}

		return (int) $payload->createdAt->format('U')
			+ (int) $payload->updatedAt->format('s')
			+ (int) $item['created']->format('d')
			+ strlen($payload->timezone->getName());
	}

	private static function rawModelProbe(mixed $payload, int $operation): int
	{
		if (!is_array($payload) || !isset($payload['models']) || !is_array($payload['models'])) {
			throw new RuntimeException('Raw model payload has an unexpected type');
		}

		$model = $payload['models'][$operation % count($payload['models'])] ?? null;
		if (!$model instanceof UcBenchRawModel) {
			throw new RuntimeException('Raw model payload is incomplete');
		}

		return $model->id
			+ (int) $model->createdAt->format('d')
			+ (int) $model->updatedAt->format('H')
			+ $model->attributes['score'];
	}

	private static function referenceProbe(mixed $payload, int $operation): int
	{
		if (!$payload instanceof UcBenchReferencePayload) {
			throw new RuntimeException('Reference payload has an unexpected type');
		}

		return $payload->child->revision + strlen($payload->child->label) + $operation;
	}

	private static function cycleProbe(mixed $payload, int $operation): int
	{
		if (!$payload instanceof UcBenchCyclePayload || !$payload->peer instanceof UcBenchCyclePayload) {
			throw new RuntimeException('Cycle payload has an unexpected type');
		}

		return $payload->revision + $payload->peer->revision + strlen($payload->peer->name) + $operation;
	}

	private static function nestedArrayProbe(mixed $payload, int $operation): int
	{
		if (!is_array($payload) || !isset($payload['nodes']) || !is_array($payload['nodes'])) {
			throw new RuntimeException('Nested array payload has an unexpected type');
		}

		$node = $payload['nodes'][$operation % count($payload['nodes'])] ?? null;
		if (!is_array($node)) {
			throw new RuntimeException('Nested array payload is incomplete');
		}

		return $node['revision'] + strlen($node['label']) + (int) $node['children'][2]['enabled'];
	}

	private static function largeObjectDigest(mixed $payload): string
	{
		if (!$payload instanceof UcBenchLargeObjectPayload) {
			throw new RuntimeException('Large object payload has an unexpected type');
		}

		$row = $payload->rows[96] ?? null;
		if (!is_array($row)) {
			throw new RuntimeException('Large object payload is incomplete');
		}

		return $payload->name . ':' . $payload->child->label . ':' . $payload->child->revision
			. ':' . $row['path'] . ':' . $row['weights'][3] . ':' . $row['metadata']['tenant'];
	}

	private static function metadataObjectDigest(mixed $payload): string
	{
		if (!$payload instanceof UcBenchMetadataPayload) {
			throw new RuntimeException('Metadata payload has an unexpected type');
		}

		$route = $payload->routes['route_257'] ?? null;
		$service = $payload->services['service.41'] ?? null;
		if (!is_array($route) || !is_array($service)) {
			throw new RuntimeException('Metadata payload is incomplete');
		}

		return $payload->name . ':' . $payload->owner->label . ':' . $payload->owner->revision . ':' . $route['score'] . ':' . $service['class'];
	}

	private static function multiKeyConfigDigest(mixed $payload): string
	{
		if (!is_array($payload) || !isset($payload['entries']['config_17'])) {
			throw new RuntimeException('Multi-key config payload has an unexpected type');
		}

		$entry = $payload['entries']['config_17'];
		return $entry['name'] . ':' . $entry['tenant'] . ':' . (int) $entry['features']['recommendations'] . ':' . $entry['limits']['items'];
	}

	private static function safeDirectDigest(mixed $payload): string
	{
		if (!$payload instanceof UcBenchFastPathPayload) {
			throw new RuntimeException('SafeDirect payload has an unexpected type');
		}

		return $payload->createdAt->format('Y-m-d H:i:s.u e')
			. ':' . $payload->timezone->getName()
			. ':' . $payload->expiresAt->format('Y-m-d H:i:s.u e')
			. ':' . $payload->gracePeriod->format('%a')
			. ':' . $payload->gracePeriod->format('%H:%I:%S');
	}

	private static function splCollectionDigest(mixed $payload): string
	{
		if (!$payload instanceof UcBenchSplCollectionPayload) {
			throw new RuntimeException('SPL collection payload has an unexpected type');
		}

		$fixed = $payload['fixed'] ?? null;
		$map = $payload['map'] ?? null;
		$iterator = $payload['iterator'] ?? null;
		$recursive = $payload['recursive'] ?? null;
		if (!$fixed instanceof SplFixedArray
				|| !$map instanceof ArrayObject
				|| !$iterator instanceof ArrayIterator
				|| !$recursive instanceof RecursiveArrayIterator) {
			throw new RuntimeException('SPL collection payload is incomplete');
		}

		$fixedRow = $fixed[17];
		$mapRow = $map['row_31'];
		$iteratorRow = $iterator['row_43'];
		$branch = $recursive['branch'];
		if (!is_array($fixedRow) || !is_array($mapRow) || !is_array($iteratorRow) || !is_array($branch)) {
			throw new RuntimeException('SPL collection payload rows are incomplete');
		}

		return $payload->name . ':' . $payload->revision
			. ':' . $fixed->getSize()
			. ':' . $fixedRow['label']
			. ':' . $mapRow['score']
			. ':' . $iteratorRow['label']
			. ':' . $branch['leaf_31']['score'];
	}

	private static function splLinkedCollectionDigest(mixed $payload): string
	{
		if (!$payload instanceof UcBenchSplCollectionPayload) {
			throw new RuntimeException('SPL linked collection payload has an unexpected type');
		}

		$list = $payload['list'] ?? null;
		$queue = $payload['queue'] ?? null;
		$stack = $payload['stack'] ?? null;
		if (!$list instanceof SplDoublyLinkedList
				|| !$queue instanceof SplQueue
				|| !$stack instanceof SplStack) {
			throw new RuntimeException('SPL linked collection payload is incomplete');
		}

		$listRow = $list[17];
		$queueRow = $queue[31];
		$stackRow = $stack[43];
		if (!is_array($listRow) || !is_array($queueRow) || !is_array($stackRow)) {
			throw new RuntimeException('SPL linked collection payload rows are incomplete');
		}

		return $payload->name . ':' . $payload->revision
			. ':' . $list->count()
			. ':' . $queue->count()
			. ':' . $stack->count()
			. ':' . $listRow['label']
			. ':' . $queueRow['score']
			. ':' . $stackRow['label'];
	}

	private static function splHeapDigest(mixed $payload): string
	{
		if (!$payload instanceof UcBenchSplCollectionPayload) {
			throw new RuntimeException('SPL heap payload has an unexpected type');
		}

		$min = $payload['min'] ?? null;
		$max = $payload['max'] ?? null;
		$priorityQueue = $payload['priority_queue'] ?? null;
		if (!$min instanceof SplMinHeap
				|| !$max instanceof SplMaxHeap
				|| !$priorityQueue instanceof SplPriorityQueue) {
			throw new RuntimeException('SPL heap payload is incomplete');
		}

		$minTop = $min->top();
		$maxTop = $max->top();
		$priorityTop = $priorityQueue->top();
		if (!is_array($minTop) || !is_array($maxTop) || !is_array($priorityTop)) {
			throw new RuntimeException('SPL heap payload rows are incomplete');
		}

		return $payload->name . ':' . $payload->revision
			. ':' . $min->count()
			. ':' . $max->count()
			. ':' . $priorityQueue->count()
			. ':' . $minTop['row']['label']
			. ':' . $maxTop['row']['score']
			. ':' . $priorityTop['data']['label']
			. ':' . $priorityTop['priority'];
	}

	private static function carbonDateTimeDigest(mixed $payload): string
	{
		if (!$payload instanceof UcBenchCarbonDateTimePayload) {
			throw new RuntimeException('Carbon DateTime payload has an unexpected type');
		}

		$middle = $payload->timeline[intdiv(count($payload->timeline), 2)] ?? null;
		$last = $payload->timeline[count($payload->timeline) - 1] ?? null;
		if (!is_array($middle) || !is_array($last)) {
			throw new RuntimeException('Carbon DateTime timeline payload is incomplete');
		}

		return $payload->createdAt->format('Y-m-d H:i:s.u e')
			. ':' . $payload->updatedAt->format('Y-m-d H:i:s.u e')
			. ':' . $payload->timezone->getName()
			. ':' . count($payload->timeline)
			. ':' . $middle['created']->format('Y-m-d H:i:s.u e')
			. ':' . $last['updated']->format('Y-m-d H:i:s.u e')
			. ':' . $last['timezone']->getName();
	}

	private static function carbonModelDigest(mixed $payload): string
	{
		if (!is_array($payload) || !isset($payload['models'])) {
			throw new RuntimeException('Carbon model payload has an unexpected type');
		}

		$model = $payload['models'][17] ?? null;
		if (!$model instanceof UcBenchCarbonModel) {
			throw new RuntimeException('Carbon model payload is incomplete');
		}

		return $payload['count']
			. ':' . $model->id
			. ':' . $model->name
			. ':' . $model->createdAt->format('Y-m-d H:i:s.u e')
			. ':' . $model->updatedAt->format('Y-m-d H:i:s.u e')
			. ':' . $model->publishedAt->format('Y-m-d H:i:s.u e')
			. ':' . $model->attributes['status']
			. ':' . $model->attributes['score'];
	}

	private static function rawDateTimeDigest(mixed $payload): string
	{
		if (!$payload instanceof UcBenchRawDateTimePayload) {
			throw new RuntimeException('Raw DateTime payload has an unexpected type');
		}

		$middle = $payload->timeline[intdiv(count($payload->timeline), 2)] ?? null;
		$last = $payload->timeline[count($payload->timeline) - 1] ?? null;
		if (!is_array($middle) || !is_array($last)) {
			throw new RuntimeException('Raw DateTime timeline payload is incomplete');
		}

		return $payload->createdAt->format('Y-m-d H:i:s.u e')
			. ':' . $payload->updatedAt->format('Y-m-d H:i:s.u e')
			. ':' . $payload->timezone->getName()
			. ':' . count($payload->timeline)
			. ':' . $middle['created']->format('Y-m-d H:i:s.u e')
			. ':' . $last['updated']->format('Y-m-d H:i:s.u e')
			. ':' . $last['timezone']->getName();
	}

	private static function rawModelDigest(mixed $payload): string
	{
		if (!is_array($payload) || !isset($payload['models'])) {
			throw new RuntimeException('Raw model payload has an unexpected type');
		}

		$model = $payload['models'][17] ?? null;
		if (!$model instanceof UcBenchRawModel) {
			throw new RuntimeException('Raw model payload is incomplete');
		}

		return $payload['count']
			. ':' . $model->id
			. ':' . $model->name
			. ':' . $model->createdAt->format('Y-m-d H:i:s.u e')
			. ':' . $model->updatedAt->format('Y-m-d H:i:s.u e')
			. ':' . $model->publishedAt->format('Y-m-d H:i:s.u e')
			. ':' . $model->attributes['status']
			. ':' . $model->attributes['score'];
	}

	private static function referenceDigest(mixed $payload): string
	{
		if (!$payload instanceof UcBenchReferencePayload) {
			throw new RuntimeException('Reference payload has an unexpected type');
		}

		return $payload->name . ':' . $payload->child->label . ':' . $payload->child->revision;
	}

	private static function cycleDigest(mixed $payload): string
	{
		if (!$payload instanceof UcBenchCyclePayload
				|| !$payload->peer instanceof UcBenchCyclePayload
				|| $payload->peer->peer !== $payload) {
			throw new RuntimeException('Cyclic payload is incomplete');
		}

		return $payload->name . ':' . $payload->revision . ':' . $payload->peer->name . ':' . $payload->peer->revision;
	}

	private static function serializedCycleDigest(mixed $payload): string
	{
		if (!$payload instanceof UcBenchCyclePayload
				|| !$payload->peer instanceof UcBenchCyclePayload
				|| !$payload->peer->peer instanceof UcBenchCyclePayload) {
			throw new RuntimeException('Serialized cyclic payload is incomplete');
		}

		return $payload->name . ':' . $payload->revision
			. ':' . $payload->peer->name . ':' . $payload->peer->revision
			. ':' . $payload->peer->peer->name . ':' . $payload->peer->peer->revision;
	}

	private static function nestedArrayDigest(mixed $payload): string
	{
		if (!is_array($payload) || !isset($payload['nodes'][7]) || !is_array($payload['nodes'][7])) {
			throw new RuntimeException('Nested array payload has an unexpected type');
		}

		$node = $payload['nodes'][7];
		return $payload['name'] . ':' . $node['label'] . ':' . $node['revision'] . ':' . (int) $node['children'][2]['enabled'];
	}
}

final class UcBenchRunner
{
	private array $backendOrder = ['user_cache', 'apcu', 'apcu_igbinary'];

	public function __construct(private readonly array $options)
	{
	}

	public function run(): array
	{
		$cases = UcBenchPayloadFactory::select($this->options['cases']);
		$backends = $this->selectBackends($this->options['backends']);
		$backendInfo = [];
		$readRows = [];
		$writeRows = [];
		$failures = [];

		foreach ($backends as $backend) {
			$backendInfo[$backend->name()] = [
				'label' => $backend->label(),
				'available' => $backend->available(),
				'unavailable_reason' => $backend->unavailableReason(),
			];
		}

		if ($this->options['run_read']) {
			foreach ($cases as $caseName => $case) {
				foreach ($backends as $backend) {
					if (!$backend->available()) {
						continue;
					}
					try {
						$readRows[] = $this->measureRead($caseName, $case, $backend);
					} catch (Throwable $exception) {
						$failures[] = $this->failureRow('read', $caseName, $case, $backend, $exception);
					}
				}
			}
		}

		if ($this->options['run_write']) {
			foreach ($cases as $caseName => $case) {
				foreach ($backends as $backend) {
					if (!$backend->available()) {
						continue;
					}
					try {
						$writeRows[] = $this->measureWrite($caseName, $case, $backend);
					} catch (Throwable $exception) {
						$failures[] = $this->failureRow('write', $caseName, $case, $backend, $exception);
					}
				}
			}
		}

		if ($readRows === [] && $writeRows === [] && $failures === []) {
			throw new RuntimeException('No benchmark rows were measured; all selected backends are unavailable');
		}

		$this->addRelativeColumns($readRows, 'mean_operation_us');
		$this->addRelativeColumns($writeRows, 'mean_store_us');

		return [
			'version' => UC_BENCH_VERSION,
			'generated_at' => date(DATE_ATOM),
			'options' => $this->options,
			'environment' => $this->environment(),
			'backends' => $backendInfo,
			'cases' => array_map(
				static fn (array $case): array => [
					'label' => $case['label'],
					'description' => $case['description'],
					'mutates_after_fetch' => $case['mutate'] !== null,
					'collects_cycles_after_fetch' => $case['collect_cycles_after_fetch'],
				],
				$cases,
			),
			'read' => $readRows,
			'write' => $writeRows,
			'failures' => $failures,
		];
	}

	public function runIsolatedByWorkload(): array
	{
		$cases = UcBenchPayloadFactory::select($this->options['cases']);
		$result = [
			'version' => UC_BENCH_VERSION,
			'generated_at' => date(DATE_ATOM),
			'options' => array_merge($this->options, [
				'case_isolation' => true,
				'case_isolation_order' => 'store_then_fetch',
				'worker_output' => null,
			]),
			'environment' => null,
			'backends' => [],
			'cases' => [],
			'read' => [],
			'write' => [],
			'failures' => [],
		];

		uc_bench_ensure_dir($this->options['results_dir']);
		if ($this->options['run_write']) {
			$this->runCaseWorkersForMode($result, $cases, 'write');
		}
		if ($this->options['run_read']) {
			$this->runCaseWorkersForMode($result, $cases, 'read');
		}

		if ($result['environment'] === null) {
			$result['environment'] = $this->environment();
		}

		if ($result['read'] === [] && $result['write'] === [] && $result['failures'] === []) {
			throw new RuntimeException('No benchmark rows were measured; all selected backends are unavailable');
		}

		$this->addRelativeColumns($result['read'], 'mean_operation_us');
		$this->addRelativeColumns($result['write'], 'mean_store_us');

		return $result;
	}

	private function runCaseWorkersForMode(array &$result, array $cases, string $mode): void
	{
		$backendNames = $this->selectedBackendNames();
		foreach ($cases as $caseName => $_case) {
			foreach ($backendNames as $backendName) {
				$workerOutput = tempnam($this->options['results_dir'], 'ucbench-worker-');
				if ($workerOutput === false) {
					throw new RuntimeException('Failed to create worker result file in: ' . $this->options['results_dir']);
				}

				try {
					$this->appendWorkerResult($result, $this->runCaseWorker($caseName, $mode, $workerOutput, $backendName));
				} finally {
					if (is_file($workerOutput)) {
						unlink($workerOutput);
					}
				}
			}
		}
	}

	private function runCaseWorker(string $caseName, string $mode, string $workerOutput, string $backendName): array
	{
		$workerArgs = [
			'--no-isolate',
			'--worker-output',
			$workerOutput,
			'--cases',
			$caseName,
			'--iterations',
			(string) $this->options['iterations'],
			'--warmup',
			(string) $this->options['warmup'],
			'--read-operations',
			(string) $this->options['read_operations'],
			'--write-operations',
			(string) $this->options['write_operations'],
			'--key-space',
			(string) $this->options['key_space'],
			'--backends',
			$backendName,
		];
		$json = '';
		$result = null;

		if ($mode === 'write') {
			$workerArgs[] = '--write-only';
		} else if ($mode === 'read') {
			$workerArgs[] = '--read-only';
		} else {
			throw new InvalidArgumentException('Unknown worker mode: ' . $mode);
		}

		uc_bench_run_worker_process($workerArgs, $mode . '/' . $caseName . '/' . $backendName, $backendName);

		$json = file_get_contents($workerOutput);
		if ($json === false) {
			throw new RuntimeException('Failed to read worker result for workload: ' . $mode . '/' . $caseName);
		}

		$result = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($result)) {
			throw new RuntimeException('Worker result is not an object for workload: ' . $mode . '/' . $caseName);
		}

		return $result;
	}

	private function appendWorkerResult(array &$result, array $workerResult): void
	{
		if ($result['environment'] === null && isset($workerResult['environment']) && is_array($workerResult['environment'])) {
			$result['environment'] = $workerResult['environment'];
		}

		foreach (($workerResult['backends'] ?? []) as $name => $backendInfo) {
			if (!isset($result['backends'][$name])) {
				$result['backends'][$name] = $backendInfo;
			}
		}

		foreach (($workerResult['cases'] ?? []) as $name => $caseInfo) {
			$result['cases'][$name] = $caseInfo;
		}

		array_push($result['read'], ...($workerResult['read'] ?? []));
		array_push($result['write'], ...($workerResult['write'] ?? []));
		array_push($result['failures'], ...($workerResult['failures'] ?? []));
	}

	private function selectBackends(?array $names): array
	{
		$all = $this->allBackends();

		if ($names === null) {
			$names = $this->backendOrder;
		}

		$selected = [];
		foreach ($names as $name) {
			if (!isset($all[$name])) {
				throw new InvalidArgumentException('Unknown backend: ' . $name);
			}
			$selected[] = $all[$name];
		}

		return $selected;
	}

	private function selectedBackendNames(): array
	{
		$names = $this->options['backends'] ?? $this->backendOrder;
		$all = $this->allBackends();
		foreach ($names as $name) {
			if (!isset($all[$name])) {
				throw new InvalidArgumentException('Unknown backend: ' . $name);
			}
		}

		return $names;
	}

	private function allBackends(): array
	{
		return [
			'user_cache' => new UcBenchUserCacheBackend('benchmark_user_cache'),
			'apcu' => new UcBenchApcuBackend(),
			'apcu_igbinary' => new UcBenchApcuBackend(
				'apcu_igbinary',
				'APCu/igbinary store/fetch',
				'igbinary',
				true,
			),
		];
	}

	private function measureRead(string $caseName, array $case, UcBenchBackend $backend): array
	{
		$key = $this->cacheKey('read', $backend->name(), $caseName);
		$payload = $case['build']();
		$expectedDigest = $case['digest']($payload);
		$samples = [];

		$backend->clear();
		$backend->store($key, $payload);
		$this->assertDigest($case, $backend->fetch($key), $expectedDigest, $backend->name(), $caseName);

		for ($i = 0; $i < $this->options['warmup']; $i++) {
			$this->readSample($backend, $key, $case, $expectedDigest, $caseName);
		}

		for ($i = 0; $i < $this->options['iterations']; $i++) {
			$samples[] = $this->readSample($backend, $key, $case, $expectedDigest, $caseName);
		}

		return $this->row(
			'read',
			$caseName,
			$case,
			$backend,
			$this->options['read_operations'],
			$samples,
			'mean_operation_us',
		);
	}

	private function readSample(UcBenchBackend $backend, string $key, array $case, string $expectedDigest, string $caseName): float
	{
		$operations = $this->options['read_operations'];
		$mutate = $case['mutate'];
		$probe = $case['probe'];
		$collectCycles = $case['collect_cycles_after_fetch'];
		$gcWasEnabled = $collectCycles && gc_enabled();
		$value = null;
		$readScore = 0;

		if ($gcWasEnabled) {
			gc_disable();
		}

		$start = hrtime(true);
		$elapsed = 0;

		try {
			for ($i = 0; $i < $operations; $i++) {
				$value = $backend->fetch($key);
				if ($probe !== null) {
					$readScore ^= $probe($value, $i);
				}
				if ($mutate !== null) {
					$mutate($value, $i);
				}
			}

			$elapsed = hrtime(true) - $start;
		} finally {
			if ($gcWasEnabled) {
				gc_enable();
			}
		}

		$this->assertDigest($case, $backend->fetch($key), $expectedDigest, $backend->name(), $caseName);
		if ($readScore === PHP_INT_MIN) {
			throw new RuntimeException('Unreachable read score guard was hit');
		}

		return ($elapsed / 1000) / $operations;
	}

	private function measureWrite(string $caseName, array $case, UcBenchBackend $backend): array
	{
		$payload = $case['build']();
		$expectedDigest = $case['digest']($payload);
		$samples = [];

		for ($i = 0; $i < $this->options['warmup']; $i++) {
			$this->writeSample($backend, $caseName, $payload, false);
		}

		for ($i = 0; $i < $this->options['iterations']; $i++) {
			$samples[] = $this->writeSample($backend, $caseName, $payload, true);
		}

		$this->assertWrittenKeys($backend, $caseName, $case, $expectedDigest);

		return $this->row(
			'write',
			$caseName,
			$case,
			$backend,
			$this->options['write_operations'],
			$samples,
			'mean_store_us',
		);
	}

	private function writeSample(
		UcBenchBackend $backend,
		string $caseName,
		mixed $payload,
		bool $measured,
	): float {
		$operations = $this->options['write_operations'];
		$keySpace = $this->options['key_space'];
		$baseKey = $this->cacheKey('write', $backend->name(), $caseName);

		$backend->clear();
		$start = hrtime(true);
		for ($i = 0; $i < $operations; $i++) {
			$backend->store($baseKey . '.' . ($i % $keySpace), $payload);
		}
		$elapsed = hrtime(true) - $start;

		if (!$measured) {
			return 0.0;
		}

		return ($elapsed / 1000) / $operations;
	}

	private function assertWrittenKeys(
		UcBenchBackend $backend,
		string $caseName,
		array $case,
		string $expectedDigest,
	): void {
		$operations = $this->options['write_operations'];
		$keySpace = $this->options['key_space'];
		$baseKey = $this->cacheKey('write', $backend->name(), $caseName);
		$writtenKeyCount = min($operations, $keySpace);

		for ($i = 0; $i < $writtenKeyCount; $i++) {
			$this->assertDigest($case, $backend->fetch($baseKey . '.' . $i), $expectedDigest, $backend->name(), $caseName);
		}
	}

	private function row(
		string $mode,
		string $caseName,
		array $case,
		UcBenchBackend $backend,
		int $operations,
		array $samples,
		string $metricName,
	): array {
		$mean = self::mean($samples);
		$median = self::median($samples);
		$min = min($samples);
		$max = max($samples);
		$stddev = self::stddev($samples, $mean);

		return [
			'mode' => $mode,
			'case' => $caseName,
			'case_label' => $case['label'],
			'backend' => $backend->name(),
			'backend_label' => $backend->label(),
			'iterations' => count($samples),
			'operation_count' => $operations,
			$metricName => $mean,
			'median_us' => $median,
			'min_us' => $min,
			'max_us' => $max,
			'stddev_us' => $stddev,
			'operations_per_second' => $mean > 0.0 ? 1000000.0 / $mean : 0.0,
			'samples_us' => $samples,
			'mutates_after_fetch' => $case['mutate'] !== null,
		];
	}

	private function failureRow(
		string $mode,
		string $caseName,
		array $case,
		UcBenchBackend $backend,
		Throwable $exception,
	): array {
		return [
			'mode' => $mode,
			'case' => $caseName,
			'case_label' => $case['label'],
			'backend' => $backend->name(),
			'backend_label' => $backend->label(),
			'error_class' => $exception::class,
			'error' => $exception->getMessage(),
		];
	}

	private function addRelativeColumns(array &$rows, string $metricName): void
	{
		$groups = [];
		foreach ($rows as $offset => $row) {
			$groups[$row['case']][] = $offset;
		}

		foreach ($groups as $offsets) {
			$sortedOffsets = $offsets;
			usort($sortedOffsets, static fn (int $a, int $b): int => $rows[$a][$metricName] <=> $rows[$b][$metricName]);
			$best = null;
			$userCache = null;
			foreach ($offsets as $offset) {
				$value = (float) $rows[$offset][$metricName];
				$best = $best === null ? $value : min($best, $value);
				if ($rows[$offset]['backend'] === 'user_cache') {
					$userCache = $value;
				}
			}
			foreach ($sortedOffsets as $rank => $offset) {
				$rows[$offset]['rank'] = $rank + 1;
				$rows[$offset]['best_backend'] = $rows[$sortedOffsets[0]]['backend'];
				$rows[$offset]['best_metric_us'] = (float) $rows[$sortedOffsets[0]][$metricName];
			}
			foreach ($offsets as $offset) {
				$value = (float) $rows[$offset][$metricName];
				$rows[$offset]['ratio_to_best'] = $best !== null && $best > 0.0 ? $value / $best : null;
				$rows[$offset]['delta_vs_user_cache_percent'] = $userCache !== null && $userCache > 0.0
					? (($value - $userCache) / $userCache) * 100.0
					: null;
			}
		}
	}

	private function assertDigest(array $case, mixed $value, string $expectedDigest, string $backendName, string $caseName): void
	{
		$actualDigest = $case['digest']($value);
		if ($actualDigest !== $expectedDigest) {
			throw new RuntimeException(
				'Payload digest mismatch for ' . $backendName . ' / ' . $caseName . ': expected ' . $expectedDigest . ', got ' . $actualDigest,
			);
		}
	}

	private function cacheKey(string $mode, string $backend, string $caseName): string
	{
		return 'user_cache_benchmark.' . UC_BENCH_VERSION . '.' . $mode . '.' . $backend . '.' . $caseName;
	}

	private function environment(): array
	{
		$opcacheStatus = function_exists('opcache_get_status') ? opcache_get_status(false) : false;

		$loadedExtensions = get_loaded_extensions();
		sort($loadedExtensions, SORT_NATURAL | SORT_FLAG_CASE);

		return [
			'php_version' => PHP_VERSION,
			'php_sapi' => PHP_SAPI,
			'php_binary' => PHP_BINARY,
			'php_zts' => PHP_ZTS,
			'uname' => php_uname(),
			'loaded_extensions' => $loadedExtensions,
			'ini' => [
				'opcache.enable' => ini_get('opcache.enable'),
				'opcache.enable_cli' => ini_get('opcache.enable_cli'),
				'user_cache.shm_size' => ini_get('user_cache.shm_size'),
				'apc.enable_cli' => ini_get('apc.enable_cli'),
			],
			'opcache_jit' => is_array($opcacheStatus) ? ($opcacheStatus['jit'] ?? null) : null,
		];
	}

	private static function mean(array $values): float
	{
		return array_sum($values) / count($values);
	}

	private static function median(array $values): float
	{
		sort($values, SORT_NUMERIC);
		$count = count($values);
		$middle = intdiv($count, 2);
		if ($count % 2 === 1) {
			return (float) $values[$middle];
		}

		return ((float) $values[$middle - 1] + (float) $values[$middle]) / 2.0;
	}

	private static function stddev(array $values, float $mean): float
	{
		$sum = 0.0;
		foreach ($values as $value) {
			$sum += ($value - $mean) ** 2;
		}

		return sqrt($sum / count($values));
	}
}

final class UcBenchHtmlReport
{
	public static function render(array $result, string $jsonPath): string
	{
		$title = 'UserCache\Cache Benchmark Result';
		$readTable = self::metricTable($result['read'], 'mean_operation_us', 'Read latency');
		$writeTable = self::metricTable($result['write'], 'mean_store_us', 'Store latency');
		$failures = $result['failures'] ?? [];
		$readComparison = self::comparisonTable($result['read'], $failures, 'read', 'mean_operation_us', 'Read Workloads');
		$writeComparison = self::comparisonTable($result['write'], $failures, 'write', 'mean_store_us', 'Write Workloads');
		$backendTable = self::backendTable($result['backends']);
		$caseTable = self::caseTable($result['cases']);
		$failureTable = self::failureTable($failures);
		$sampleDetails = self::sampleDetails($result['read'], $result['write']);
		$environment = self::environmentTable($result['environment'], $result['options'], $jsonPath);

		return '<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>' . self::h($title) . '</title>
<style>
:root {
  color-scheme: light;
  --ink: #17202a;
  --muted: #5f6b7a;
  --line: #d9e1ea;
  --panel: #f7f9fb;
  --accent: #1b766f;
  --accent-soft: #d9f0ed;
  --warn: #9b5a00;
  --warn-soft: #fff1d7;
}
body {
  margin: 0;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
  color: var(--ink);
  background: #fff;
}
main {
  max-width: 1180px;
  margin: 0 auto;
  padding: 28px 20px 48px;
}
h1 {
  margin: 0 0 8px;
  font-size: 30px;
  line-height: 1.2;
  letter-spacing: 0;
}
h2 {
  margin: 32px 0 12px;
  font-size: 20px;
  letter-spacing: 0;
}
h3 {
  margin: 22px 0 8px;
  font-size: 16px;
  letter-spacing: 0;
}
p {
  color: var(--muted);
  line-height: 1.55;
}
.summary {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 12px;
  margin: 20px 0 12px;
}
.stat {
  border: 1px solid var(--line);
  border-radius: 8px;
  padding: 12px 14px;
  background: var(--panel);
}
.stat strong {
  display: block;
  font-size: 22px;
  margin-top: 4px;
}
.note {
  padding: 10px 12px;
  border: 1px solid var(--line);
  border-radius: 8px;
  background: var(--panel);
  color: var(--muted);
}
.warn {
  border-color: #f0c36a;
  background: var(--warn-soft);
  color: var(--warn);
}
.takeaways {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap: 12px;
  margin: 12px 0 18px;
}
.takeaway {
  border: 1px solid var(--line);
  border-radius: 8px;
  padding: 12px 14px;
  background: #fff;
}
.takeaway span {
  display: block;
  color: var(--muted);
  margin-top: 4px;
}
.backend-chip {
  display: inline-block;
  padding: 2px 7px;
  border-radius: 999px;
  background: #edf1f5;
  color: var(--ink);
  font-weight: 650;
  font-size: 12px;
}
.winner-chip {
  background: var(--accent-soft);
  color: var(--accent);
}
.relation {
  display: block;
  margin-top: 2px;
  font-size: 12px;
}
.faster {
  color: #146c43;
}
.slower {
  color: #9b2c2c;
}
.neutral {
  color: var(--muted);
}
.score {
  display: block;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}
.score-winner {
  color: var(--accent);
}
.score-error {
  color: #9b2c2c;
}
.score-note {
  display: block;
  margin-top: 2px;
  color: var(--muted);
  font-size: 12px;
  line-height: 1.35;
}
table {
  width: 100%;
  border-collapse: collapse;
  margin: 8px 0 20px;
  font-size: 14px;
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
.status {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 650;
}
.ok {
  background: var(--accent-soft);
  color: var(--accent);
}
.skip {
  background: var(--warn-soft);
  color: var(--warn);
}
.barbox {
  width: 130px;
  height: 10px;
  background: #edf1f5;
  border-radius: 999px;
  overflow: hidden;
}
.bar {
  display: block;
  height: 10px;
  background: var(--accent);
}
code {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 0.94em;
}
details {
  border: 1px solid var(--line);
  border-radius: 8px;
  padding: 10px 12px;
  margin-top: 10px;
}
summary {
  cursor: pointer;
  font-weight: 650;
}
</style>
</head>
<body>
<main>
<h1>' . self::h($title) . '</h1>
<p>Generated at <code>' . self::h($result['generated_at']) . '</code>. Lower microseconds per operation is better.</p>
' . self::summaryCards($result) . '
<h2>At A Glance</h2>
' . self::outcomeSummary($result) . '
<p class="note">Each workload row uses the fastest successful backend as 100%. Other cells show relative throughput and the measured milliseconds per operation.</p>
' . $readComparison . '
' . $writeComparison . '
' . $failureTable . '
' . $environment . '
<h2>Backend Availability</h2>
' . $backendTable . '
<h2>Workloads</h2>
' . $caseTable . '
<h2>Read Results</h2>
' . $readTable . '
<h2>Write Results</h2>
' . $writeTable . '
' . $sampleDetails . '
</main>
</body>
</html>
';
	}

	private static function summaryCards(array $result): string
	{
		$readRows = count($result['read']);
		$writeRows = count($result['write']);
		$failureRows = count($result['failures'] ?? []);
		$available = 0;
		$skipped = 0;
		foreach ($result['backends'] as $backend) {
			if ($backend['available']) {
				$available++;
			} else {
				$skipped++;
			}
		}

		return '<div class="summary">'
			. '<div class="stat">Available backends<strong>' . self::h((string) $available) . '</strong></div>'
			. '<div class="stat">Skipped backends<strong>' . self::h((string) $skipped) . '</strong></div>'
			. '<div class="stat">Read rows<strong>' . self::h((string) $readRows) . '</strong></div>'
			. '<div class="stat">Write rows<strong>' . self::h((string) $writeRows) . '</strong></div>'
			. '<div class="stat">Errors<strong>' . self::h((string) $failureRows) . '</strong></div>'
			. '</div>';
	}

	private static function outcomeSummary(array $result): string
	{
		return '<div class="takeaways">'
			. self::outcomeCard('Read winner count', $result['read'], 'mean_operation_us')
			. self::outcomeCard('Write winner count', $result['write'], 'mean_store_us')
			. '</div>';
	}

	private static function outcomeCard(string $title, array $rows, string $metricName): string
	{
		$groups = self::groupRowsByCase($rows);
		$counts = [];
		foreach ($groups as $caseRows) {
			$best = self::bestRow($caseRows, $metricName);
			if ($best !== null) {
				$counts[$best['backend']] = ($counts[$best['backend']] ?? 0) + 1;
			}
		}
		arsort($counts);

		if ($counts === []) {
			return '<div class="takeaway"><strong>' . self::h($title) . '</strong><span>No rows measured.</span></div>';
		}

		$parts = [];
		foreach ($counts as $backend => $count) {
			$parts[] = self::backendName($backend) . ' ' . $count . '/' . count($groups);
		}

		$leader = array_key_first($counts);

		return '<div class="takeaway"><strong>' . self::h($title) . ': '
			. '<span class="backend-chip winner-chip">' . self::h(self::backendName((string) $leader)) . '</span></strong>'
			. '<span>' . self::h(implode(', ', $parts)) . '</span></div>';
	}

	private static function comparisonTable(array $rows, array $failures, string $mode, string $metricName, string $title): string
	{
		$modeFailures = self::failuresByCaseAndBackend($failures, $mode);
		if ($rows === [] && $modeFailures === []) {
			return '<h3>' . self::h($title) . '</h3><p class="note warn">No rows were measured.</p>';
		}

		$groups = self::groupRowsByCase($rows);
		foreach ($modeFailures as $caseName => $backendFailures) {
			if (!isset($groups[$caseName])) {
				$groups[$caseName] = [];
			}
		}

		$backendNames = self::comparisonBackendOrder($rows, $modeFailures);
		$html = '<h3>' . self::h($title) . '</h3><table><thead><tr><th>Workload</th>';
		foreach ($backendNames as $backendName) {
			$html .= '<th class="num">' . self::h(self::backendName($backendName)) . '</th>';
		}
		$html .= '<th class="num">Faster/UserCache</th></tr></thead><tbody>';

		foreach ($groups as $caseName => $caseRows) {
			$best = self::bestRow($caseRows, $metricName);
			$bestValue = $best !== null ? (float) $best[$metricName] : null;
			$caseLabel = $caseRows[0]['case_label']
				?? self::caseLabelFromFailures($modeFailures[$caseName] ?? [])
				?? $caseName;

			$html .= '<tr><td><code>' . self::h($caseName) . '</code><br>' . self::h($caseLabel)
				. '</td>';
			foreach ($backendNames as $backendName) {
				$html .= '<td class="num">' . self::scoreCell(self::rowForBackend($caseRows, $backendName), $modeFailures[$caseName][$backendName] ?? null, $bestValue, $metricName)
					. '</td>';
			}
			$html .= '<td class="num">' . self::fasterVsUserCacheCell($caseRows, $metricName) . '</td></tr>';
		}

		$html .= '</tbody></table>';

		return $html;
	}

	private static function comparisonBackendOrder(array $rows, array $modeFailures): array
	{
		$preferred = ['user_cache', 'apcu', 'apcu_igbinary'];
		$seen = [];
		foreach ($rows as $row) {
			if (isset($row['backend'])) {
				$seen[(string) $row['backend']] = true;
			}
		}
		foreach ($modeFailures as $backendFailures) {
			foreach ($backendFailures as $backendName => $_failure) {
				$seen[(string) $backendName] = true;
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

	private static function scoreCell(?array $row, ?array $failure, ?float $bestValue, string $metricName): string
	{
		if ($row === null) {
			if ($failure !== null) {
				return '<span class="score score-error">error</span><span class="score-note">'
					. self::h(self::shortError($failure))
					. '</span>';
			}

			return '<span class="score-note">not measured</span>';
		}

		$value = (float) $row[$metricName];
		$score = $bestValue !== null && $value > 0.0 ? ($bestValue / $value) * 100.0 : 0.0;
		$class = $score >= 99.995 ? ' score-winner' : '';

		return '<span class="score' . $class . '">' . self::h(self::formatScore($score))
			. ' (' . self::formatMs($value) . ')</span>';
	}

	private static function fasterVsUserCacheCell(array $caseRows, string $metricName): string
	{
		$userCache = self::rowForBackend($caseRows, 'user_cache');
		$best = self::bestRow($caseRows, $metricName);
		if ($userCache === null || $best === null) {
			return '<span class="score-note">n/a</span>';
		}

		$userValue = (float) $userCache[$metricName];
		$bestValue = (float) $best[$metricName];
		if ($userValue <= 0.0 || $bestValue <= 0.0) {
			return '<span class="score-note">n/a</span>';
		}

		return '<span class="score">' . self::h(self::number($userValue / $bestValue, 2) . 'x (' . self::backendName((string) $best['backend']) . ')') . '</span>';
	}

	private static function failuresByCaseAndBackend(array $failures, string $mode): array
	{
		$indexed = [];
		foreach ($failures as $failure) {
			if (($failure['mode'] ?? null) !== $mode) {
				continue;
			}
			$indexed[$failure['case']][$failure['backend']] = $failure;
		}

		return $indexed;
	}

	private static function caseLabelFromFailures(array $failures): ?string
	{
		foreach ($failures as $failure) {
			if (isset($failure['case_label'])) {
				return $failure['case_label'];
			}
		}

		return null;
	}

	private static function groupRowsByCase(array $rows): array
	{
		$groups = [];
		foreach ($rows as $row) {
			$groups[$row['case']][] = $row;
		}

		return $groups;
	}

	private static function bestRow(array $rows, string $metricName): ?array
	{
		if ($rows === []) {
			return null;
		}

		usort($rows, static fn (array $a, array $b): int => $a[$metricName] <=> $b[$metricName]);

		return $rows[0];
	}

	private static function rowForBackend(array $rows, string $backend): ?array
	{
		foreach ($rows as $row) {
			if ($row['backend'] === $backend) {
				return $row;
			}
		}

		return null;
	}

	private static function backendTable(array $backends): string
	{
		$html = '<table><thead><tr><th>Backend</th><th>API</th><th>Status</th><th>Reason</th></tr></thead><tbody>';
		foreach ($backends as $name => $backend) {
			$status = $backend['available']
				? '<span class="status ok">available</span>'
				: '<span class="status skip">skipped</span>';
			$html .= '<tr><td><code>' . self::h($name) . '</code></td><td>'
				. self::h($backend['label'])
				. '</td><td>' . $status . '</td><td>'
				. self::h((string) ($backend['unavailable_reason'] ?? ''))
				. '</td></tr>';
		}
		$html .= '</tbody></table>';

		return $html;
	}

	private static function failureTable(array $failures): string
	{
		if ($failures === []) {
			return '';
		}

		$html = '<h3>Errors</h3><table><thead><tr><th>Mode</th><th>Workload</th><th>Backend</th><th>Error</th></tr></thead><tbody>';
		foreach ($failures as $failure) {
			$html .= '<tr><td>' . self::h($failure['mode'])
				. '</td><td><code>' . self::h($failure['case']) . '</code><br>' . self::h($failure['case_label'])
				. '</td><td><code>' . self::h($failure['backend']) . '</code><br>' . self::h($failure['backend_label'])
				. '</td><td><code>' . self::h($failure['error_class']) . '</code><br>' . self::h($failure['error'])
				. '</td></tr>';
		}
		$html .= '</tbody></table>';

		return $html;
	}

	private static function caseTable(array $cases): string
	{
		$html = '<table><thead><tr><th>Case</th><th>Description</th><th>Fetch mutation</th></tr></thead><tbody>';
		foreach ($cases as $name => $case) {
			$html .= '<tr><td><code>' . self::h($name) . '</code><br>' . self::h($case['label']) . '</td><td>'
				. self::h($case['description'])
				. '</td><td>' . ($case['mutates_after_fetch'] ? 'yes' : 'no') . '</td></tr>';
		}
		$html .= '</tbody></table>';

		return $html;
	}

	private static function metricTable(array $rows, string $metricName, string $caption): string
	{
		if ($rows === []) {
			return '<p class="note warn">No ' . self::h(strtolower($caption)) . ' rows were measured.</p>';
		}

		$maxByCase = [];
		foreach ($rows as $row) {
			$caseName = $row['case'];
			$value = (float) $row[$metricName];
			$maxByCase[$caseName] = max($maxByCase[$caseName] ?? 0.0, $value);
		}

		$html = '<table><thead><tr>'
			. '<th>Workload</th><th class="num">Rank</th><th>Backend</th><th class="num">Mean us/op</th><th class="num">vs UserCache</th><th class="num">vs Best</th>'
			. '<th class="num">Median</th><th class="num">Min</th><th class="num">Max</th><th class="num">Ops/sec</th><th>Scale</th>'
			. '</tr></thead><tbody>';
		foreach ($rows as $row) {
			$value = (float) $row[$metricName];
			$width = $maxByCase[$row['case']] > 0.0 ? max(2.0, ($value / $maxByCase[$row['case']]) * 100.0) : 2.0;
			$deltaText = self::detailVsUserCacheText($row);
			$bestText = self::detailVsBestText($row);
			$html .= '<tr><td><code>' . self::h($row['case']) . '</code><br>' . self::h($row['case_label']) . '</td><td><code>'
				. self::h((string) ($row['rank'] ?? ''))
				. '</code></td><td><code>'
				. self::h($row['backend'])
				. '</code><br>' . self::h($row['backend_label'])
				. '</td><td class="num">' . self::number($value, 3)
				. '</td><td class="num">' . $deltaText
				. '</td><td class="num">' . $bestText
				. '</td><td class="num">' . self::number((float) $row['median_us'], 3)
				. '</td><td class="num">' . self::number((float) $row['min_us'], 3)
				. '</td><td class="num">' . self::number((float) $row['max_us'], 3)
				. '</td><td class="num">' . self::number((float) $row['operations_per_second'], 0)
				. '</td><td><div class="barbox"><span class="bar" style="width: ' . self::number($width, 1) . '%"></span></div></td></tr>';
		}
		$html .= '</tbody></table>';

		return $html;
	}

	private static function environmentTable(array $environment, array $options, string $jsonPath): string
	{
		$threadSafety = !empty($environment['php_zts']) ? 'ZTS' : 'NTS';
		$rows = [
			'PHP' => $environment['php_version'] . ' (' . $environment['php_sapi'] . ')',
			'Thread Safety' => $threadSafety,
			'Binary' => $environment['php_binary'],
			'System' => $environment['uname'],
			'opcache.enable_cli' => (string) $environment['ini']['opcache.enable_cli'],
			'user_cache.shm_size' => (string) $environment['ini']['user_cache.shm_size'],
			'Iterations' => (string) $options['iterations'],
			'Warmup' => (string) $options['warmup'],
			'Read operations' => (string) $options['read_operations'],
			'Write operations' => (string) $options['write_operations'],
			'Case isolation' => !empty($options['case_isolation'])
				? 'serial store workers, then serial fetch workers'
				: 'single PHP request',
			'Raw JSON' => $jsonPath,
		];

		$html = '<h2>Environment</h2><table><tbody>';
		foreach ($rows as $key => $value) {
			$html .= '<tr><th>' . self::h($key) . '</th><td><code>' . self::h($value) . '</code></td></tr>';
		}
		$html .= '</tbody></table>';

		return $html;
	}

	private static function sampleDetails(array $readRows, array $writeRows): string
	{
		$rows = array_merge($readRows, $writeRows);
		if ($rows === []) {
			return '';
		}

		$html = '<h2>Samples</h2><details><summary>Per-iteration samples</summary>'
			. '<table><thead><tr><th>Mode</th><th>Case</th><th>Backend</th><th>Samples us/op</th></tr></thead><tbody>';
		foreach ($rows as $row) {
			$html .= '<tr><td>' . self::h($row['mode']) . '</td><td><code>' . self::h($row['case'])
				. '</code></td><td><code>' . self::h($row['backend'])
				. '</code></td><td><code>' . self::h(implode(', ', array_map(
					static fn (float $value): string => self::number($value, 3),
					$row['samples_us'],
				))) . '</code></td></tr>';
		}
		$html .= '</tbody></table></details>';

		return $html;
	}

	private static function detailVsUserCacheText(array $row): string
	{
		$delta = $row['delta_vs_user_cache_percent'];
		if ($row['backend'] === 'user_cache') {
			return '<span class="neutral">baseline</span>';
		}
		if ($delta === null) {
			return '<span class="neutral">n/a</span>';
		}

		$value = (float) $delta;
		if (abs($value) < 1.0) {
			return '<span class="neutral">same</span>';
		}
		$class = $value < 0.0 ? 'faster' : 'slower';
		$word = $value < 0.0 ? 'faster' : 'slower';

		return '<span class="' . $class . '">' . self::h(self::signedPercent($value) . ' ' . $word) . '</span>';
	}

	private static function detailVsBestText(array $row): string
	{
		$ratio = $row['ratio_to_best'] ?? null;
		if ($ratio === null) {
			return '<span class="neutral">n/a</span>';
		}
		$ratio = (float) $ratio;
		if ($ratio <= 1.0005) {
			return '<span class="faster">best</span>';
		}
		if ($ratio < 1.01) {
			return '<span class="neutral">about same as best</span>';
		}

		return '<span class="slower">' . self::h(self::number($ratio, 2) . 'x slower') . '</span>';
	}

	private static function backendName(string $backend): string
	{
		return match ($backend) {
			'user_cache' => 'UserCache',
			'apcu' => 'APCu',
			'apcu_igbinary' => 'APCu/igbinary',
			default => $backend,
		};
	}

	private static function formatUs(float $value): string
	{
		return self::number($value, 3) . ' us/op';
	}

	private static function formatMs(float $microseconds): string
	{
		return self::number($microseconds / 1000.0, 3) . ' ms/op';
	}

	private static function formatScore(float $score): string
	{
		if ($score >= 99.995) {
			return '100%';
		}

		return self::number($score, 1) . '%';
	}

	private static function shortError(array $failure): string
	{
		$error = $failure['error_class'] . ': ' . $failure['error'];
		if (strlen($error) <= 120) {
			return $error;
		}

		return substr($error, 0, 117) . '...';
	}

	private static function signedPercent(float $value): string
	{
		if (abs($value) < 0.005) {
			return 'baseline';
		}

		return ($value > 0.0 ? '+' : '') . self::number($value, 1) . '%';
	}

	private static function number(float $value, int $decimals): string
	{
		return number_format($value, $decimals, '.', ',');
	}

	private static function h(string $value): string
	{
		return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}

function uc_bench_usage(): string
{
	return <<<'TXT'
Usage: php scripts/UserCacheBenchmark.php [options]

Options:
  --output FILE             HTML output file. Default: BENCH_RESULT.html
  --results-dir DIR         Directory for raw JSON. Default: results
  --iterations N            Measured iterations. Default: 30
  --warmup N                Warmup iterations. Default: 5
  --read-operations N       Fetch operations per measured read iteration. Default: 1000
  --write-operations N      Store operations per measured write iteration. Default: 1000
  --key-space N             Distinct write keys per case/backend. Default: 32
  --cases a,b,c             Comma-separated payload cases.
  --backends a,b,c          Comma-separated backends: user_cache,apcu,apcu_igbinary.
  --read-only               Run read benchmarks only.
  --write-only              Run write benchmarks only.
  --no-write                Alias for --read-only.
  --no-isolate              Run all workloads in the current PHP request.
  -h, --help                Show this help.
TXT;
}

function uc_bench_parse_options(array $argv): array
{
	$root = dirname(__DIR__);
	$options = [
		'output' => $root . '/BENCH_RESULT.html',
		'results_dir' => $root . '/results',
		'iterations' => 30,
		'warmup' => 5,
		'read_operations' => 1000,
		'write_operations' => 1000,
		'key_space' => 32,
		'cases' => null,
		'backends' => null,
		'run_read' => true,
		'run_write' => true,
		'case_isolation' => true,
		'worker_output' => null,
	];

	for ($i = 1; $i < count($argv); $i++) {
		$arg = $argv[$i];
		switch ($arg) {
			case '--output':
				$options['output'] = uc_bench_next_value($argv, ++$i, $arg);
				break;
			case '--results-dir':
				$options['results_dir'] = uc_bench_next_value($argv, ++$i, $arg);
				break;
			case '--iterations':
				$options['iterations'] = uc_bench_positive_int(uc_bench_next_value($argv, ++$i, $arg), $arg);
				break;
			case '--warmup':
				$options['warmup'] = uc_bench_non_negative_int(uc_bench_next_value($argv, ++$i, $arg), $arg);
				break;
			case '--read-operations':
				$options['read_operations'] = uc_bench_positive_int(uc_bench_next_value($argv, ++$i, $arg), $arg);
				break;
			case '--write-operations':
				$options['write_operations'] = uc_bench_positive_int(uc_bench_next_value($argv, ++$i, $arg), $arg);
				break;
			case '--key-space':
				$options['key_space'] = uc_bench_positive_int(uc_bench_next_value($argv, ++$i, $arg), $arg);
				break;
			case '--cases':
				$options['cases'] = uc_bench_csv(uc_bench_next_value($argv, ++$i, $arg));
				break;
			case '--backends':
				$options['backends'] = uc_bench_csv(uc_bench_next_value($argv, ++$i, $arg));
				break;
			case '--read-only':
			case '--no-write':
				$options['run_read'] = true;
				$options['run_write'] = false;
				break;
			case '--write-only':
				$options['run_read'] = false;
				$options['run_write'] = true;
				break;
			case '--no-isolate':
				$options['case_isolation'] = false;
				break;
			case '--worker-output':
				$options['worker_output'] = uc_bench_next_value($argv, ++$i, $arg);
				break;
			case '-h':
			case '--help':
				fwrite(STDOUT, uc_bench_usage() . PHP_EOL);
				exit(0);
			default:
				throw new InvalidArgumentException('Unknown argument: ' . $arg);
		}
	}

	$options['output'] = uc_bench_absolute_path($options['output']);
	$options['results_dir'] = uc_bench_absolute_path($options['results_dir']);
	if ($options['worker_output'] !== null) {
		$options['worker_output'] = uc_bench_absolute_path($options['worker_output']);
		$options['case_isolation'] = false;
	}

	return $options;
}

function uc_bench_next_value(array $argv, int $offset, string $option): string
{
	if (!isset($argv[$offset]) || str_starts_with($argv[$offset], '--')) {
		throw new InvalidArgumentException($option . ' requires a value');
	}

	return $argv[$offset];
}

function uc_bench_positive_int(string $value, string $option): int
{
	if (!preg_match('/^[1-9][0-9]*$/', $value)) {
		throw new InvalidArgumentException($option . ' must be a positive integer');
	}

	return (int) $value;
}

function uc_bench_non_negative_int(string $value, string $option): int
{
	if (!preg_match('/^(0|[1-9][0-9]*)$/', $value)) {
		throw new InvalidArgumentException($option . ' must be a non-negative integer');
	}

	return (int) $value;
}

function uc_bench_csv(string $value): array
{
	$parts = array_filter(array_map('trim', explode(',', $value)), static fn (string $part): bool => $part !== '');
	if ($parts === []) {
		throw new InvalidArgumentException('Comma-separated option must not be empty');
	}

	return array_values($parts);
}

function uc_bench_absolute_path(string $path): string
{
	if ($path === '') {
		throw new InvalidArgumentException('Path must not be empty');
	}

	if ($path[0] === '/') {
		return $path;
	}

	return getcwd() . '/' . $path;
}

function uc_bench_ensure_dir(string $dir): void
{
	if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
		throw new RuntimeException('Failed to create directory: ' . $dir);
	}
}

function uc_bench_worker_php_args(): array
{
	$json = getenv('UC_BENCH_PHP_ARGS_JSON');
	$args = null;

	if ($json === false || $json === '') {
		return [];
	}

	$args = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
	if (!is_array($args)) {
		throw new RuntimeException('UC_BENCH_PHP_ARGS_JSON must contain a JSON array');
	}

	foreach ($args as $arg) {
		if (!is_string($arg)) {
			throw new RuntimeException('UC_BENCH_PHP_ARGS_JSON must contain only string arguments');
		}
	}

	return array_values($args);
}

function uc_bench_backend_php_args(?string $backendName): array
{
	if ($backendName === null) {
		return [];
	}

	$json = getenv('UC_BENCH_BACKEND_PHP_ARGS_JSON');
	if ($json === false || $json === '') {
		return [];
	}

	$config = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
	if (!is_array($config)) {
		throw new RuntimeException('UC_BENCH_BACKEND_PHP_ARGS_JSON must contain a JSON object');
	}
	if (!isset($config[$backendName])) {
		return [];
	}
	if (!is_array($config[$backendName])) {
		throw new RuntimeException('UC_BENCH_BACKEND_PHP_ARGS_JSON values must be JSON arrays');
	}

	foreach ($config[$backendName] as $arg) {
		if (!is_string($arg)) {
			throw new RuntimeException('UC_BENCH_BACKEND_PHP_ARGS_JSON arrays must contain only string arguments');
		}
	}

	return array_values($config[$backendName]);
}

function uc_bench_run_worker_process(array $workerArgs, string $caseName, ?string $backendName = null): void
{
	$stdoutPath = tempnam(sys_get_temp_dir(), 'ucbench-out-');
	$stderrPath = tempnam(sys_get_temp_dir(), 'ucbench-err-');
	$command = [];
	$descriptors = [];
	$pipes = [];
	$process = null;
	$exitCode = 1;
	$stdout = '';
	$stderr = '';

	if ($stdoutPath === false || $stderrPath === false) {
		throw new RuntimeException('Failed to create worker stdio files');
	}

	try {
		$command = array_merge([PHP_BINARY], uc_bench_worker_php_args(), uc_bench_backend_php_args($backendName), [__FILE__], $workerArgs);
		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['file', $stdoutPath, 'w'],
			2 => ['file', $stderrPath, 'w'],
		];
		$process = proc_open($command, $descriptors, $pipes, dirname(__DIR__));
		if (!is_resource($process)) {
			throw new RuntimeException('Failed to start worker for workload: ' . $caseName);
		}

		fclose($pipes[0]);
		$exitCode = proc_close($process);
		$stdout = (string) file_get_contents($stdoutPath);
		$stderr = (string) file_get_contents($stderrPath);

		if ($exitCode !== 0) {
			$message = 'Worker failed for workload ' . $caseName . ' (exit ' . $exitCode . ')';
			if (trim($stderr) !== '') {
				$message .= PHP_EOL . 'STDERR:' . PHP_EOL . trim($stderr);
			}
			if (trim($stdout) !== '') {
				$message .= PHP_EOL . 'STDOUT:' . PHP_EOL . trim($stdout);
			}

			throw new RuntimeException($message);
		}
	} finally {
		if (is_file($stdoutPath)) {
			unlink($stdoutPath);
		}
		if (is_file($stderrPath)) {
			unlink($stderrPath);
		}
	}
}

function uc_bench_write_file(string $path, string $contents): void
{
	uc_bench_ensure_dir(dirname($path));
	if (file_put_contents($path, $contents) === false) {
		throw new RuntimeException('Failed to write file: ' . $path);
	}
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
	return;
}

try {
	$options = uc_bench_parse_options($argv);
	$runner = new UcBenchRunner($options);
	$result = $options['case_isolation'] ? $runner->runIsolatedByWorkload() : $runner->run();
	$json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	if ($json === false) {
		throw new RuntimeException('Failed to encode result JSON: ' . json_last_error_msg());
	}
	if ($options['worker_output'] !== null) {
		uc_bench_write_file($options['worker_output'], $json . PHP_EOL);
		exit(0);
	}

	$stamp = gmdate('Ymd-His');
	$jsonPath = $options['results_dir'] . '/user-cache-benchmark-' . $stamp . '.json';
	uc_bench_write_file($jsonPath, $json . PHP_EOL);
	$html = UcBenchHtmlReport::render($result, $jsonPath);
	uc_bench_write_file($options['output'], $html);

	fwrite(STDOUT, 'Wrote HTML report: ' . $options['output'] . PHP_EOL);
	fwrite(STDOUT, 'Wrote raw JSON: ' . $jsonPath . PHP_EOL);
} catch (Throwable $exception) {
	fwrite(STDERR, 'Benchmark failed: ' . $exception->getMessage() . PHP_EOL);
	fwrite(STDERR, uc_bench_usage() . PHP_EOL);
	exit(1);
}
