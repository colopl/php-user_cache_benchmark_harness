#!/bin/sh

set -eu

ROOT=$(CDPATH= cd "$(dirname "${0}")/.." && pwd)
PHP_BIN=${PHP_BIN:-"${ROOT}/../sapi/cli/php"}
SHM_SIZE_MB=${USER_CACHE_SHM_SIZE_MB:-64}
MEMORY_LIMIT=${USER_CACHE_BENCHMARK_MEMORY_LIMIT:--1}
EXTENSION_BUILD_DIR=${USER_CACHE_BENCHMARK_EXTENSION_DIR:-"${ROOT}/runtime/extensions"}
BUILD_EXTENSIONS=${USER_CACHE_BENCHMARK_BUILD_EXTENSIONS:-1}
APCU_SO=${APCU_SO:-}
IGBINARY_SO=${IGBINARY_SO:-}
PHPIZE=${PHPIZE:-}
PHP_CONFIG=${PHP_CONFIG:-}
LOCK_DIR=${UC_BENCH_LOCK_DIR:-"${ROOT}/runtime/benchmark.lock"}
LOCK_ACQUIRED=0
BENCH_HELP=0

usage() {
	cat <<'EOF'
Usage: ./scripts/benchmark_user_cache.sh WRAPPER_OPTIONS BENCHMARK_OPTIONS

Wrapper options must appear before benchmark options.

Wrapper options:
  --build-extensions        Build APCu and igbinary if their .so files are missing. Default.
  --no-build-extensions     Do not build extensions; only load explicitly provided .so files.
  --extension-build-dir DIR Directory used for extension builds. Default: runtime/extensions.
  --apcu-so FILE            Existing APCu extension module to load.
  --igbinary-so FILE        Existing igbinary extension module to load.
  --phpize FILE             phpize for the target PHP build.
  --php-config FILE         php-config for the target PHP build.
  --php FILE                PHP CLI binary used for the benchmark.
  --help-wrapper            Show this wrapper help.

Benchmark options are passed through to scripts/UserCacheBenchmark.php.
Use --help to show benchmark options.
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

target_build_root() {
	PHP_BIN_VALUE=${1}
	PHP_DIR=$(CDPATH= cd "$(dirname "${PHP_BIN_VALUE}")" && pwd)
	if test -f "${PHP_DIR}/../../scripts/phpize" && test -f "${PHP_DIR}/../../scripts/php-config"; then
		CDPATH= cd "${PHP_DIR}/../.." && pwd
		return
	fi
	CDPATH= cd "${ROOT}/.." && pwd
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

build_extension_if_needed() {
	NAME_VALUE=${1}
	OUTPUT_SO=${2}
	BUILD_SCRIPT=${3}
	OUTPUT_DIR=${4}

	if test -f "${OUTPUT_SO}"; then
		return
	fi
	if test "${BUILD_EXTENSIONS}" != 1; then
		return
	fi

	printf 'Building %s extension into %s\n' "${NAME_VALUE}" "${OUTPUT_DIR}" >&2
	"${BUILD_SCRIPT}" "${PHPIZE}" "${PHP_CONFIG}" "${OUTPUT_DIR}" >/dev/null
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

worker_args_json() {
	JSON_ARGS=
	if test -n "${MEMORY_LIMIT}"; then
		set -- -d "memory_limit=${MEMORY_LIMIT}" "${@}"
	fi

	while test "${#}" -gt 0; do
		ARG_VALUE=$(printf '%s' "${1}" | sed 's|\\|\\\\|g; s|"|\\"|g')
		if test -z "${JSON_ARGS}"; then
			JSON_ARGS="\"${ARG_VALUE}\""
		else
			JSON_ARGS="${JSON_ARGS},\"${ARG_VALUE}\""
		fi
		shift
	done
	printf '\133%s\135' "${JSON_ARGS}"
}

while test "${#}" -gt 0; do
	case "${1}" in
		--build-extensions)
			BUILD_EXTENSIONS=1
			shift
			;;
		--no-build-extensions)
			BUILD_EXTENSIONS=0
			shift
			;;
		--extension-build-dir)
			EXTENSION_BUILD_DIR=$(absolute_path "${2:?--extension-build-dir requires a value}")
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
		--phpize)
			PHPIZE=$(absolute_path "${2:?--phpize requires a value}")
			shift 2
			;;
		--php-config)
			PHP_CONFIG=$(absolute_path "${2:?--php-config requires a value}")
			shift 2
			;;
		--php)
			PHP_BIN=$(absolute_path "${2:?--php requires a value}")
			shift 2
			;;
		--help-wrapper)
			usage
			exit 0
			;;
		--help|-h)
			BENCH_HELP=1
			break
			;;
		*)
			break
			;;
	esac
done

trap cleanup 0 2 15
acquire_benchmark_lock

PHP_BIN=$(absolute_path "${PHP_BIN}")
require_executable "${PHP_BIN}" "PHP binary"

BUILD_ROOT=${PHP_BUILD_ROOT:-$(target_build_root "${PHP_BIN}")}
PHPIZE=${PHPIZE:-"${BUILD_ROOT}/scripts/phpize"}
PHP_CONFIG=${PHP_CONFIG:-"${BUILD_ROOT}/scripts/php-config"}
APCU_SO=${APCU_SO:-"${EXTENSION_BUILD_DIR}/apcu/apcu.so"}
IGBINARY_SO=${IGBINARY_SO:-"${EXTENSION_BUILD_DIR}/igbinary/igbinary.so"}

if test "${BENCH_HELP}" = 1; then
	BUILD_EXTENSIONS=0
fi

if test "${BUILD_EXTENSIONS}" = 1; then
	require_executable "${PHPIZE}" "phpize"
	require_executable "${PHP_CONFIG}" "php-config"
	build_extension_if_needed "APCu" "${APCU_SO}" "${ROOT}/scripts/build_apcu.sh" "${EXTENSION_BUILD_DIR}/apcu"
	build_extension_if_needed "igbinary" "${IGBINARY_SO}" "${ROOT}/scripts/build_igbinary.sh" "${EXTENSION_BUILD_DIR}/igbinary"
fi

UC_BENCH_BACKEND_PHP_ARGS_JSON='{"apcu":["-d","apc.serializer=php"],"apcu_igbinary":["-d","apc.serializer=igbinary"]}'
export UC_BENCH_BACKEND_PHP_ARGS_JSON

if test -f "${APCU_SO}" && test -f "${IGBINARY_SO}"; then
	UC_BENCH_PHP_ARGS_JSON=$(worker_args_json \
		-d opcache.enable=1 \
		-d opcache.enable_cli=1 \
		-d user_cache.enable=1 \
		-d user_cache.enable_cli=1 \
		-d opcache.jit=0 \
		-d "user_cache.shm_size=${SHM_SIZE_MB}M" \
		-d apc.enable_cli=1 \
		-d "extension=${APCU_SO}" \
		-d "extension=${IGBINARY_SO}")
	export UC_BENCH_PHP_ARGS_JSON
	"${PHP_BIN}" \
		-d "memory_limit=${MEMORY_LIMIT}" \
		-d opcache.enable=1 \
		-d opcache.enable_cli=1 \
		-d user_cache.enable=1 \
		-d user_cache.enable_cli=1 \
		-d opcache.jit=0 \
		-d "user_cache.shm_size=${SHM_SIZE_MB}M" \
		-d apc.enable_cli=1 \
		-d "extension=${APCU_SO}" \
		-d "extension=${IGBINARY_SO}" \
		"${ROOT}/scripts/UserCacheBenchmark.php" "${@}"
	exit "${?}"
fi

if test -f "${APCU_SO}"; then
	UC_BENCH_PHP_ARGS_JSON=$(worker_args_json \
		-d opcache.enable=1 \
		-d opcache.enable_cli=1 \
		-d user_cache.enable=1 \
		-d user_cache.enable_cli=1 \
		-d opcache.jit=0 \
		-d "user_cache.shm_size=${SHM_SIZE_MB}M" \
		-d apc.enable_cli=1 \
		-d "extension=${APCU_SO}")
	export UC_BENCH_PHP_ARGS_JSON
	"${PHP_BIN}" \
		-d "memory_limit=${MEMORY_LIMIT}" \
		-d opcache.enable=1 \
		-d opcache.enable_cli=1 \
		-d user_cache.enable=1 \
		-d user_cache.enable_cli=1 \
		-d opcache.jit=0 \
		-d "user_cache.shm_size=${SHM_SIZE_MB}M" \
		-d apc.enable_cli=1 \
		-d "extension=${APCU_SO}" \
		"${ROOT}/scripts/UserCacheBenchmark.php" "${@}"
	exit "${?}"
fi

UC_BENCH_PHP_ARGS_JSON=$(worker_args_json \
	-d opcache.enable=1 \
	-d opcache.enable_cli=1 \
	-d user_cache.enable=1 \
	-d user_cache.enable_cli=1 \
	-d opcache.jit=0 \
	-d "user_cache.shm_size=${SHM_SIZE_MB}M" \
	-d apc.enable_cli=1)
export UC_BENCH_PHP_ARGS_JSON

"${PHP_BIN}" \
	-d "memory_limit=${MEMORY_LIMIT}" \
	-d opcache.enable=1 \
	-d opcache.enable_cli=1 \
	-d user_cache.enable=1 \
	-d user_cache.enable_cli=1 \
	-d opcache.jit=0 \
	-d "user_cache.shm_size=${SHM_SIZE_MB}M" \
	-d apc.enable_cli=1 \
	"${ROOT}/scripts/UserCacheBenchmark.php" "${@}"
exit "${?}"
