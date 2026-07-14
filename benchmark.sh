#!/bin/sh

set -eu

ROOT=$(CDPATH= cd "$(dirname "${0}")" && pwd)
PHP_CLI_BIN=${PHP_CLI_BIN:-"${ROOT}/../sapi/cli/php"}
PHP_FPM_BIN=${PHP_FPM_BIN:-"${ROOT}/../sapi/fpm/php-fpm"}
NGINX_BIN=${NGINX_BIN:-/usr/sbin/nginx}
APCU_SO=${APCU_SO:-"${ROOT}/runtime/extensions/apcu/apcu.so"}
IGBINARY_SO=${IGBINARY_SO:-"${ROOT}/runtime/extensions/igbinary/igbinary.so"}
SHM_SIZE_MB=${USER_CACHE_SHM_SIZE_MB:-128}
OUTPUT=${OUTPUT:-"${ROOT}/BENCH_RESULT.html"}
RESULTS_DIR=${RESULTS_DIR:-}
LOCK_DIR=${UC_BENCH_LOCK_DIR:-"${ROOT}/runtime/benchmark.lock"}
LOCK_ACQUIRED=0
RUN_FPM=1
QUICK=0
RUNS=${UC_BENCH_RUNS:-3}
RUNS_SET=0

READ_CASES=constant_array,route_table_read,large_array,large_string,large_object_graph,metadata_object_read,metadata_object_fetch_mutate,sleep_wakeup_object,serialize_magic_entity,unserialize_only_entity,sleep_wakeup_entity_collection,sleep_wakeup_large_dataset,recursive_reference_graph,mixed_serialization_payload,product_listing_view_model,multi_key_config_read,safe_direct_object,spl_collection_object,spl_linked_collection_object,spl_heap_object,carbon_datetime_object,carbon_model_object,raw_datetime_object,raw_model_object,serialized_cycle_object,reference_assignment_object,cycle_assignment_object,nested_array_assignment
WRITE_CASES=${WRITE_CASES:-${READ_CASES}}
RESIDENT_CASES=constant_array,route_table_read,large_array,large_string,product_listing_view_model
FPM_HOT_CASES=route_table_read,large_array,large_string,large_object_graph,metadata_object_read,metadata_object_fetch_mutate,sleep_wakeup_object,serialize_magic_entity,unserialize_only_entity,sleep_wakeup_entity_collection,sleep_wakeup_large_dataset,recursive_reference_graph,mixed_serialization_payload,product_listing_view_model,multi_key_config_read,safe_direct_object,spl_collection_object,spl_heap_object,carbon_datetime_object,carbon_model_object,raw_datetime_object,raw_model_object,serialized_cycle_object,reference_assignment_object,cycle_assignment_object,nested_array_assignment
BACKENDS=user_cache,apcu,apcu_igbinary
FPM_PHP_BACKENDS=user_cache,apcu
FPM_IGBINARY_BACKENDS=user_cache,apcu_igbinary

CLI_ITERATIONS=12
CLI_WARMUP=2
CLI_READ_OPERATIONS=2000
WRITE_ITERATIONS=6
WRITE_WARMUP=2
WRITE_OPERATIONS=200
WRITE_KEY_SPACE=32
RESIDENT_ITERATIONS=12
RESIDENT_WARMUP=2
RESIDENT_OPERATIONS=2000
BULK_ITERATIONS=12
BULK_WARMUP=2
BULK32_OPERATIONS=1500
BULK128_OPERATIONS=600
FPM_ONCE_REQUESTS=180
FPM_ONCE_WARMUP=20
FPM_ONCE_HOLD_US=2000
FPM_HOT_REQUESTS=24
FPM_HOT_WARMUP=4
FPM_HOT_OPERATIONS=100
FPM_HOT_HOLD_US=1000
FPM_CONCURRENCY=5

usage() {
	cat <<'EOF'
Usage: ./benchmark.sh OPTIONS

Runs the UserCache read-heavy benchmark set, adds write workload
context, and writes a combined BENCH_RESULT.html.

By default the full suite is executed 3 times and every published metric is
the median across runs (single runs swing by tens of percent on the FPM
one-fetch workloads); BENCH_RESULT.html is rendered from the aggregate.
Use --runs 1 for a single measurement run. --quick defaults to a single run.

Options:
  --quick               Use short smoke-test iteration counts (default: 1 run).
  --runs N              Number of full runs to aggregate by median. Default: 3.
  --no-fpm              Skip php-fpm/nginx request benchmarks.
  --php FILE            PHP CLI binary. Default: ../sapi/cli/php
  --php-fpm FILE        php-fpm binary. Default: ../sapi/fpm/php-fpm
  --nginx-bin FILE      nginx binary. Default: /usr/sbin/nginx
  --apcu-so FILE        APCu extension module path.
  --igbinary-so FILE    igbinary extension module path.
  --shm-size-mb N       user_cache.shm_size in MiB. Default: 128
  --results-dir DIR     Directory for raw JSON/HTML artifacts.
  --output FILE         Combined HTML report. Default: BENCH_RESULT.html
EOF
}

absolute_path() {
	PATH_VALUE=${1}
	case "${PATH_VALUE}" in
		/*)
			printf '%s\n' "${PATH_VALUE}"
			;;
		*)
			printf '%s/%s\n' "$(pwd)" "${PATH_VALUE}"
			;;
	esac
}

require_executable() {
	PATH_VALUE=${1}
	LABEL_VALUE=${2}
	if test -f "${PATH_VALUE}" && test ! -x "${PATH_VALUE}"; then
		chmod u+x "${PATH_VALUE}"
	fi
	if test ! -x "${PATH_VALUE}"; then
		printf '%s is not executable: %s\n' "${LABEL_VALUE}" "${PATH_VALUE}" >&2
		exit 1
	fi
}

latest_json() {
	DIR_VALUE=${1}
	JSON_VALUE=$(ls -t "${DIR_VALUE}"/user-cache-benchmark-*.json 2>/dev/null | sed -n '1p')
	if test -z "${JSON_VALUE}"; then
		printf 'No benchmark JSON was produced in: %s\n' "${DIR_VALUE}" >&2
		exit 1
	fi
	printf '%s\n' "${JSON_VALUE}"
}

acquire_benchmark_lock() {
	if test "${UC_BENCH_LOCK_HELD:-0}" = 1; then
		return
	fi

	mkdir -p "$(dirname "${LOCK_DIR}")"
	if ! mkdir "${LOCK_DIR}" 2>/dev/null; then
		if test -f "${LOCK_DIR}/pid"; then
			printf 'benchmark lock is already held by pid %s: %s\n' "$(cat "${LOCK_DIR}/pid")" "${LOCK_DIR}" >&2
		else
			printf 'benchmark lock is already held: %s\n' "${LOCK_DIR}" >&2
		fi
		exit 1
	fi

	printf '%s\n' "$$" > "${LOCK_DIR}/pid"
	LOCK_ACQUIRED=1
	export UC_BENCH_LOCK_HELD=1
	export UC_BENCH_LOCK_DIR="${LOCK_DIR}"
}

release_benchmark_lock() {
	if test "${LOCK_ACQUIRED}" = 1; then
		rm -rf "${LOCK_DIR}"
		LOCK_ACQUIRED=0
	fi
}

cleanup() {
	EXIT_CODE=${?}
	trap - 0 2 15
	release_benchmark_lock
	exit "${EXIT_CODE}"
}

while test "${#}" -gt 0; do
	case "${1}" in
		--quick)
			QUICK=1
			shift
			;;
		--runs)
			RUNS=${2:?--runs requires a value}
			RUNS_SET=1
			shift 2
			;;
		--no-fpm)
			RUN_FPM=0
			shift
			;;
		--php)
			PHP_CLI_BIN=$(absolute_path "${2:?--php requires a value}")
			shift 2
			;;
		--php-fpm)
			PHP_FPM_BIN=$(absolute_path "${2:?--php-fpm requires a value}")
			shift 2
			;;
		--nginx-bin)
			NGINX_BIN=$(absolute_path "${2:?--nginx-bin requires a value}")
			shift 2
			;;
		--apcu-so)
			APCU_SO=$(absolute_path "${2:?--apcu-so requires a value}")
			shift 2
			;;
		--igbinary-so)
			IGBINARY_SO=$(absolute_path "${2:?--igbinary-so requires a value}")
			shift 2
			;;
		--shm-size-mb)
			SHM_SIZE_MB=${2:?--shm-size-mb requires a value}
			shift 2
			;;
		--results-dir)
			RESULTS_DIR=$(absolute_path "${2:?--results-dir requires a value}")
			shift 2
			;;
		--output)
			OUTPUT=$(absolute_path "${2:?--output requires a value}")
			shift 2
			;;
		-h|--help)
			usage
			exit 0
			;;
		*)
			printf 'Unknown argument: %s\n' "${1}" >&2
			usage >&2
			exit 1
			;;
	esac
done

trap cleanup 0 2 15
acquire_benchmark_lock

NON_DIGIT_SHM_SIZE=$(printf '%s' "${SHM_SIZE_MB}" | tr -d '0123456789')
if test -z "${SHM_SIZE_MB}" || test -n "${NON_DIGIT_SHM_SIZE}"; then
	printf '%s\n' '--shm-size-mb must be an integer' >&2
	exit 1
fi

PHP_CLI_BIN=$(absolute_path "${PHP_CLI_BIN}")
PHP_FPM_BIN=$(absolute_path "${PHP_FPM_BIN}")
APCU_SO=$(absolute_path "${APCU_SO}")
IGBINARY_SO=$(absolute_path "${IGBINARY_SO}")
OUTPUT=$(absolute_path "${OUTPUT}")

if test -z "${RESULTS_DIR}"; then
	RESULTS_DIR="${ROOT}/results/read-workloads-$(date -u +%Y%m%dT%H%M%SZ)"
fi

# Quick mode is a smoke test; a single run is enough unless --runs was given.
if test "${QUICK}" = 1 && test "${RUNS_SET}" = 0; then
	RUNS=1
fi
NON_DIGIT_RUNS=$(printf '%s' "${RUNS}" | tr -d '0123456789')
if test -z "${RUNS}" || test -n "${NON_DIGIT_RUNS}" || test "${RUNS}" -lt 1; then
	printf '%s\n' '--runs must be a positive integer' >&2
	exit 1
fi

# Published numbers must be robust to run-to-run variance, so the default is
# several full runs whose per-(case, backend) metrics are median-combined
# before the report is rendered. Children inherit the held benchmark lock.
if test "${RUNS}" -gt 1; then
	printf 'Aggregated benchmark: %s runs, median-combined report\n' "${RUNS}"
	printf 'Base results directory: %s\n' "${RESULTS_DIR}"
	printf 'Final report: %s\n' "${OUTPUT}"

	CHILD_FLAGS=""
	test "${QUICK}" = 1 && CHILD_FLAGS="${CHILD_FLAGS} --quick"
	test "${RUN_FPM}" = 0 && CHILD_FLAGS="${CHILD_FLAGS} --no-fpm"

	RUN_INDEX=1
	while test "${RUN_INDEX}" -le "${RUNS}"; do
		printf '\n===== Run %s/%s =====\n' "${RUN_INDEX}" "${RUNS}"
		# shellcheck disable=SC2086
		"${0}" ${CHILD_FLAGS} \
			--runs 1 \
			--php "${PHP_CLI_BIN}" \
			--php-fpm "${PHP_FPM_BIN}" \
			--nginx-bin "${NGINX_BIN}" \
			--apcu-so "${APCU_SO}" \
			--igbinary-so "${IGBINARY_SO}" \
			--shm-size-mb "${SHM_SIZE_MB}" \
			--results-dir "${RESULTS_DIR}/run${RUN_INDEX}" \
			--output "${RESULTS_DIR}/run${RUN_INDEX}/report.html"
		RUN_INDEX=$((RUN_INDEX + 1))
	done

	printf '\nAggregating %s runs by median\n' "${RUNS}"
	MEDIAN_DIR="${RESULTS_DIR}/median"
	RUN_DIRS=""
	RUN_INDEX=1
	while test "${RUN_INDEX}" -le "${RUNS}"; do
		RUN_DIRS="${RUN_DIRS} ${RESULTS_DIR}/run${RUN_INDEX}"
		RUN_INDEX=$((RUN_INDEX + 1))
	done
	# shellcheck disable=SC2086
	"${PHP_CLI_BIN}" "${ROOT}/scripts/aggregate_results_median.php" --output "${MEDIAN_DIR}" ${RUN_DIRS}

	printf 'Writing median-combined report\n'
	set -- \
		--cli-read "${MEDIAN_DIR}/cli-read/user-cache-benchmark-median.json" \
		--cli-write "${MEDIAN_DIR}/cli-write/user-cache-benchmark-median.json" \
		--resident "${MEDIAN_DIR}/resident-payload-probe.json" \
		--bulk "${MEDIAN_DIR}/bulk-read-32.json" \
		--bulk "${MEDIAN_DIR}/bulk-read-128.json"
	if test -f "${MEDIAN_DIR}/fpm-read-once-php.json"; then
		set -- "$@" --fpm-once "${MEDIAN_DIR}/fpm-read-once-php.json"
	fi
	if test -f "${MEDIAN_DIR}/fpm-read-once-igbinary.json"; then
		set -- "$@" --fpm-once "${MEDIAN_DIR}/fpm-read-once-igbinary.json"
	fi
	if test -f "${MEDIAN_DIR}/fpm-read-hot-php.json"; then
		set -- "$@" --fpm-hot "${MEDIAN_DIR}/fpm-read-hot-php.json"
	fi
	if test -f "${MEDIAN_DIR}/fpm-read-hot-igbinary.json"; then
		set -- "$@" --fpm-hot "${MEDIAN_DIR}/fpm-read-hot-igbinary.json"
	fi
	"${PHP_CLI_BIN}" "${ROOT}/scripts/render_user_cache_performance_report.php" "$@" --output "${OUTPUT}"

	printf 'Wrote median-combined report: %s\n' "${OUTPUT}"
	exit 0
fi

CLI_READ_DIR="${RESULTS_DIR}/cli-read"
CLI_WRITE_DIR="${RESULTS_DIR}/cli-write"
mkdir -p "${RESULTS_DIR}" "${CLI_READ_DIR}" "${CLI_WRITE_DIR}" "$(dirname "${OUTPUT}")"

if test "${QUICK}" = 1; then
	CLI_ITERATIONS=3
	CLI_WARMUP=1
	CLI_READ_OPERATIONS=300
	WRITE_ITERATIONS=3
	WRITE_WARMUP=1
	WRITE_OPERATIONS=40
	RESIDENT_ITERATIONS=3
	RESIDENT_WARMUP=1
	RESIDENT_OPERATIONS=300
	BULK_ITERATIONS=3
	BULK_WARMUP=1
	BULK32_OPERATIONS=100
	BULK128_OPERATIONS=40
	FPM_ONCE_REQUESTS=10
	FPM_ONCE_WARMUP=2
	FPM_ONCE_HOLD_US=1000
	FPM_HOT_REQUESTS=5
	FPM_HOT_WARMUP=1
	FPM_HOT_OPERATIONS=20
	FPM_HOT_HOLD_US=1000
fi

require_executable "${PHP_CLI_BIN}" "PHP CLI"
if test "${RUN_FPM}" = 1; then
	require_executable "${PHP_FPM_BIN}" "php-fpm"
	require_executable "${NGINX_BIN}" "nginx"
fi
if test ! -f "${ROOT}/vendor/autoload.php"; then
	printf 'Carbon workloads require dependencies. Run this first: cd %s && composer install\n' "${ROOT}" >&2
	exit 1
fi

printf 'Results directory: %s\n' "${RESULTS_DIR}"
printf 'Final report: %s\n' "${OUTPUT}"

printf '\nStep 1/7: CLI repeated read workloads\n'
PHP_BIN="${PHP_CLI_BIN}" \
USER_CACHE_SHM_SIZE_MB="${SHM_SIZE_MB}" \
"${ROOT}/scripts/benchmark_user_cache.sh" \
	--php "${PHP_CLI_BIN}" \
	--apcu-so "${APCU_SO}" \
	--igbinary-so "${IGBINARY_SO}" \
	--read-only \
	--iterations "${CLI_ITERATIONS}" \
	--warmup "${CLI_WARMUP}" \
	--read-operations "${CLI_READ_OPERATIONS}" \
	--cases "${READ_CASES}" \
	--backends "${BACKENDS}" \
	--results-dir "${CLI_READ_DIR}" \
	--output "${CLI_READ_DIR}/cli-read.html"
CLI_JSON=$(latest_json "${CLI_READ_DIR}")

printf '\nStep 2/7: CLI write workloads\n'
PHP_BIN="${PHP_CLI_BIN}" \
USER_CACHE_SHM_SIZE_MB="${SHM_SIZE_MB}" \
"${ROOT}/scripts/benchmark_user_cache.sh" \
	--php "${PHP_CLI_BIN}" \
	--apcu-so "${APCU_SO}" \
	--igbinary-so "${IGBINARY_SO}" \
	--write-only \
	--iterations "${WRITE_ITERATIONS}" \
	--warmup "${WRITE_WARMUP}" \
	--write-operations "${WRITE_OPERATIONS}" \
	--key-space "${WRITE_KEY_SPACE}" \
	--cases "${WRITE_CASES}" \
	--backends "${BACKENDS}" \
	--results-dir "${CLI_WRITE_DIR}" \
	--output "${CLI_WRITE_DIR}/cli-write.html"
CLI_WRITE_JSON=$(latest_json "${CLI_WRITE_DIR}")

printf '\nStep 3/7: Resident payload probe\n'
RESIDENT_JSON="${RESULTS_DIR}/resident-payload-probe.json"
"${PHP_CLI_BIN}" \
	-d opcache.enable_cli=1 \
	-d user_cache.enable=1 \
	-d user_cache.enable_cli=1 \
	-d opcache.jit=0 \
	"${ROOT}/scripts/benchmark_user_cache_resident_probe.php" \
	--cases "${RESIDENT_CASES}" \
	--iterations "${RESIDENT_ITERATIONS}" \
	--warmup "${RESIDENT_WARMUP}" \
	--operations "${RESIDENT_OPERATIONS}" \
	--output "${RESIDENT_JSON}"

printf '\nStep 4/7: Bulk read, 32 keys\n'
BULK32_JSON="${RESULTS_DIR}/bulk-read-32.json"
"${PHP_CLI_BIN}" \
	-d opcache.enable=1 \
	-d opcache.enable_cli=1 \
	-d user_cache.enable=1 \
	-d user_cache.enable_cli=1 \
	-d opcache.jit=0 \
	-d "user_cache.shm_size=${SHM_SIZE_MB}M" \
	-d apc.enable_cli=1 \
	-d "extension=${APCU_SO}" \
	"${ROOT}/scripts/benchmark_user_cache_bulk_read.php" \
	--key-count 32 \
	--operations "${BULK32_OPERATIONS}" \
	--iterations "${BULK_ITERATIONS}" \
	--warmup "${BULK_WARMUP}" \
	--output "${BULK32_JSON}"

printf '\nStep 5/7: Bulk read, 128 keys\n'
BULK128_JSON="${RESULTS_DIR}/bulk-read-128.json"
"${PHP_CLI_BIN}" \
	-d opcache.enable=1 \
	-d opcache.enable_cli=1 \
	-d user_cache.enable=1 \
	-d user_cache.enable_cli=1 \
	-d opcache.jit=0 \
	-d "user_cache.shm_size=${SHM_SIZE_MB}M" \
	-d apc.enable_cli=1 \
	-d "extension=${APCU_SO}" \
	"${ROOT}/scripts/benchmark_user_cache_bulk_read.php" \
	--key-count 128 \
	--operations "${BULK128_OPERATIONS}" \
	--iterations "${BULK_ITERATIONS}" \
	--warmup "${BULK_WARMUP}" \
	--output "${BULK128_JSON}"

if test "${RUN_FPM}" = 1; then
	printf '\nStep 6/7: FPM one fetch per request\n'
	FPM_ONCE_JSON="${RESULTS_DIR}/fpm-read-once-php.json"
	FPM_ONCE_IGBINARY_JSON="${RESULTS_DIR}/fpm-read-once-igbinary.json"
	"${ROOT}/scripts/benchmark_user_cache_fpm_read.sh" \
		--php "${PHP_CLI_BIN}" \
		--php-fpm "${PHP_FPM_BIN}" \
		--nginx-bin "${NGINX_BIN}" \
		--apcu-so "${APCU_SO}" \
		--apc-serializer php \
		--shm-size "${SHM_SIZE_MB}M" \
		--output-dir "${RESULTS_DIR}" \
		--operations 1 \
		--requests "${FPM_ONCE_REQUESTS}" \
		--warmup "${FPM_ONCE_WARMUP}" \
		--concurrency "${FPM_CONCURRENCY}" \
		--hold-us "${FPM_ONCE_HOLD_US}" \
		--cases "${READ_CASES}" \
		--backends "${FPM_PHP_BACKENDS}" \
		--output "${FPM_ONCE_JSON}"
	"${ROOT}/scripts/benchmark_user_cache_fpm_read.sh" \
		--php "${PHP_CLI_BIN}" \
		--php-fpm "${PHP_FPM_BIN}" \
		--nginx-bin "${NGINX_BIN}" \
		--apcu-so "${APCU_SO}" \
		--igbinary-so "${IGBINARY_SO}" \
		--apc-serializer igbinary \
		--shm-size "${SHM_SIZE_MB}M" \
		--output-dir "${RESULTS_DIR}" \
		--operations 1 \
		--requests "${FPM_ONCE_REQUESTS}" \
		--warmup "${FPM_ONCE_WARMUP}" \
		--concurrency "${FPM_CONCURRENCY}" \
		--hold-us "${FPM_ONCE_HOLD_US}" \
		--cases "${READ_CASES}" \
		--backends "${FPM_IGBINARY_BACKENDS}" \
		--output "${FPM_ONCE_IGBINARY_JSON}"

	printf '\nStep 7/7: FPM hot read\n'
	FPM_HOT_JSON="${RESULTS_DIR}/fpm-read-hot-php.json"
	FPM_HOT_IGBINARY_JSON="${RESULTS_DIR}/fpm-read-hot-igbinary.json"
	"${ROOT}/scripts/benchmark_user_cache_fpm_read.sh" \
		--php "${PHP_CLI_BIN}" \
		--php-fpm "${PHP_FPM_BIN}" \
		--nginx-bin "${NGINX_BIN}" \
		--apcu-so "${APCU_SO}" \
		--apc-serializer php \
		--shm-size "${SHM_SIZE_MB}M" \
		--output-dir "${RESULTS_DIR}" \
		--operations "${FPM_HOT_OPERATIONS}" \
		--requests "${FPM_HOT_REQUESTS}" \
		--warmup "${FPM_HOT_WARMUP}" \
		--concurrency "${FPM_CONCURRENCY}" \
		--hold-us "${FPM_HOT_HOLD_US}" \
		--cases "${FPM_HOT_CASES}" \
		--backends "${FPM_PHP_BACKENDS}" \
		--output "${FPM_HOT_JSON}"
	"${ROOT}/scripts/benchmark_user_cache_fpm_read.sh" \
		--php "${PHP_CLI_BIN}" \
		--php-fpm "${PHP_FPM_BIN}" \
		--nginx-bin "${NGINX_BIN}" \
		--apcu-so "${APCU_SO}" \
		--igbinary-so "${IGBINARY_SO}" \
		--apc-serializer igbinary \
		--shm-size "${SHM_SIZE_MB}M" \
		--output-dir "${RESULTS_DIR}" \
		--operations "${FPM_HOT_OPERATIONS}" \
		--requests "${FPM_HOT_REQUESTS}" \
		--warmup "${FPM_HOT_WARMUP}" \
		--concurrency "${FPM_CONCURRENCY}" \
		--hold-us "${FPM_HOT_HOLD_US}" \
		--cases "${FPM_HOT_CASES}" \
		--backends "${FPM_IGBINARY_BACKENDS}" \
		--output "${FPM_HOT_IGBINARY_JSON}"

	printf '\nWriting combined report\n'
	"${PHP_CLI_BIN}" "${ROOT}/scripts/render_user_cache_performance_report.php" \
		--cli-read "${CLI_JSON}" \
		--cli-write "${CLI_WRITE_JSON}" \
		--resident "${RESIDENT_JSON}" \
		--bulk "${BULK32_JSON}" \
		--bulk "${BULK128_JSON}" \
		--fpm-once "${FPM_ONCE_JSON}" \
		--fpm-once "${FPM_ONCE_IGBINARY_JSON}" \
		--fpm-hot "${FPM_HOT_JSON}" \
		--fpm-hot "${FPM_HOT_IGBINARY_JSON}" \
		--output "${OUTPUT}"
else
	printf '\nStep 6/7: FPM benchmarks skipped\n'
	printf 'Step 7/7: FPM benchmarks skipped\n'
	printf '\nWriting combined report\n'
	"${PHP_CLI_BIN}" "${ROOT}/scripts/render_user_cache_performance_report.php" \
		--cli-read "${CLI_JSON}" \
		--cli-write "${CLI_WRITE_JSON}" \
		--resident "${RESIDENT_JSON}" \
		--bulk "${BULK32_JSON}" \
		--bulk "${BULK128_JSON}" \
		--output "${OUTPUT}"
fi
