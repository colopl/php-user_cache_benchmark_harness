#!/bin/sh

set -eu

ROOT=$(CDPATH= cd "$(dirname "${0}")/.." && pwd)
PHP_BIN=${PHP_BIN:-"${ROOT}/../sapi/cli/php"}
SHM_SIZE_MB=${OPCACHE_USER_CACHE_SHM_SIZE_MB:-64}
EXTENSION_BUILD_DIR=${OPCACHE_USER_CACHE_BENCHMARK_EXTENSION_DIR:-"${ROOT}/runtime/extensions"}
BUILD_EXTENSIONS=${OPCACHE_USER_CACHE_BENCHMARK_BUILD_EXTENSIONS:-1}
APCU_SO=${APCU_SO:-}
DEEPCLONE_SO=${DEEPCLONE_SO:-}
PHPIZE=${PHPIZE:-}
PHP_CONFIG=${PHP_CONFIG:-}
BENCH_HELP=0

usage() {
	cat <<'EOF'
Usage: ./scripts/benchmark_user_cache.sh WRAPPER_OPTIONS BENCHMARK_OPTIONS

Wrapper options must appear before benchmark options.

Wrapper options:
  --build-extensions        Build APCu and DeepClone if their .so files are missing. Default.
  --no-build-extensions     Do not build extensions; only load explicitly provided .so files.
  --extension-build-dir DIR Directory used for extension builds. Default: runtime/extensions.
  --apcu-so FILE            Existing APCu extension module to load.
  --deepclone-so FILE       Existing DeepClone extension module to load.
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

worker_args_json() {
	JSON_ARGS=
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
		--deepclone-so)
			DEEPCLONE_SO=$(absolute_path "${2:?--deepclone-so requires a value}")
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

PHP_BIN=$(absolute_path "${PHP_BIN}")
require_executable "${PHP_BIN}" "PHP binary"

BUILD_ROOT=${PHP_BUILD_ROOT:-$(target_build_root "${PHP_BIN}")}
PHPIZE=${PHPIZE:-"${BUILD_ROOT}/scripts/phpize"}
PHP_CONFIG=${PHP_CONFIG:-"${BUILD_ROOT}/scripts/php-config"}
APCU_SO=${APCU_SO:-"${EXTENSION_BUILD_DIR}/apcu/apcu.so"}
DEEPCLONE_SO=${DEEPCLONE_SO:-"${EXTENSION_BUILD_DIR}/deepclone/deepclone.so"}

if test "${BENCH_HELP}" = 1; then
	BUILD_EXTENSIONS=0
fi

if test "${BUILD_EXTENSIONS}" = 1; then
	require_executable "${PHPIZE}" "phpize"
	require_executable "${PHP_CONFIG}" "php-config"
	build_extension_if_needed "APCu" "${APCU_SO}" "${ROOT}/scripts/build_apcu.sh" "${EXTENSION_BUILD_DIR}/apcu"
	build_extension_if_needed "DeepClone" "${DEEPCLONE_SO}" "${ROOT}/scripts/build_deepclone.sh" "${EXTENSION_BUILD_DIR}/deepclone"
fi

if test -f "${APCU_SO}" && test -f "${DEEPCLONE_SO}"; then
	UC_BENCH_PHP_ARGS_JSON=$(worker_args_json \
		-d opcache.enable=1 \
		-d opcache.enable_cli=1 \
		-d opcache.jit=0 \
		-d "opcache.user_cache_shm_size=${SHM_SIZE_MB}M" \
		-d apc.enable_cli=1 \
		-d "extension=${APCU_SO}" \
		-d "extension=${DEEPCLONE_SO}")
	export UC_BENCH_PHP_ARGS_JSON
	exec "${PHP_BIN}" \
		-d opcache.enable=1 \
		-d opcache.enable_cli=1 \
		-d opcache.jit=0 \
		-d "opcache.user_cache_shm_size=${SHM_SIZE_MB}M" \
		-d apc.enable_cli=1 \
		-d "extension=${APCU_SO}" \
		-d "extension=${DEEPCLONE_SO}" \
		"${ROOT}/scripts/UserCacheBenchmark.php" "${@}"
fi

if test -f "${APCU_SO}"; then
	UC_BENCH_PHP_ARGS_JSON=$(worker_args_json \
		-d opcache.enable=1 \
		-d opcache.enable_cli=1 \
		-d opcache.jit=0 \
		-d "opcache.user_cache_shm_size=${SHM_SIZE_MB}M" \
		-d apc.enable_cli=1 \
		-d "extension=${APCU_SO}")
	export UC_BENCH_PHP_ARGS_JSON
	exec "${PHP_BIN}" \
		-d opcache.enable=1 \
		-d opcache.enable_cli=1 \
		-d opcache.jit=0 \
		-d "opcache.user_cache_shm_size=${SHM_SIZE_MB}M" \
		-d apc.enable_cli=1 \
		-d "extension=${APCU_SO}" \
		"${ROOT}/scripts/UserCacheBenchmark.php" "${@}"
fi

if test -f "${DEEPCLONE_SO}"; then
	UC_BENCH_PHP_ARGS_JSON=$(worker_args_json \
		-d opcache.enable=1 \
		-d opcache.enable_cli=1 \
		-d opcache.jit=0 \
		-d "opcache.user_cache_shm_size=${SHM_SIZE_MB}M" \
		-d apc.enable_cli=1 \
		-d "extension=${DEEPCLONE_SO}")
	export UC_BENCH_PHP_ARGS_JSON
	exec "${PHP_BIN}" \
		-d opcache.enable=1 \
		-d opcache.enable_cli=1 \
		-d opcache.jit=0 \
		-d "opcache.user_cache_shm_size=${SHM_SIZE_MB}M" \
		-d apc.enable_cli=1 \
		-d "extension=${DEEPCLONE_SO}" \
		"${ROOT}/scripts/UserCacheBenchmark.php" "${@}"
fi

UC_BENCH_PHP_ARGS_JSON=$(worker_args_json \
	-d opcache.enable=1 \
	-d opcache.enable_cli=1 \
	-d opcache.jit=0 \
	-d "opcache.user_cache_shm_size=${SHM_SIZE_MB}M" \
	-d apc.enable_cli=1)
export UC_BENCH_PHP_ARGS_JSON

exec "${PHP_BIN}" \
	-d opcache.enable=1 \
	-d opcache.enable_cli=1 \
	-d opcache.jit=0 \
	-d "opcache.user_cache_shm_size=${SHM_SIZE_MB}M" \
	-d apc.enable_cli=1 \
	"${ROOT}/scripts/UserCacheBenchmark.php" "${@}"
