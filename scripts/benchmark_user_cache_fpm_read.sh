#!/bin/sh

set -eu

ROOT=$(CDPATH= cd "$(dirname "${0}")/.." && pwd)
PHP_FPM_BIN=${PHP_FPM_BIN:-"${ROOT}/../sapi/fpm/php-fpm"}
PHP_CLI_BIN=${PHP_CLI_BIN:-"${ROOT}/../sapi/cli/php"}
NGINX_BIN=${NGINX_BIN:-/usr/sbin/nginx}
BASE_URL=${BASE_URL:-http://127.0.0.1:8080/user_cache_fpm_read_bench.php}
APCU_SO=${APCU_SO:-"${ROOT}/runtime/extensions/apcu/apcu.so"}
DEEPCLONE_SO=${DEEPCLONE_SO:-"${ROOT}/runtime/extensions/deepclone/deepclone.so"}
SHM_SIZE=${OPCACHE_USER_CACHE_SHM_SIZE:-64M}
APC_SHM_SIZE=${APC_SHM_SIZE:-128M}
OUTPUT_DIR=${OUTPUT_DIR:-"${ROOT}/results"}
NGINX_PID=
PHP_FPM_PID=

usage() {
	cat <<'EOF'
Usage: ./scripts/benchmark_user_cache_fpm_read.sh WRAPPER_OPTIONS RUNNER_OPTIONS

Wrapper options must appear before runner options.

Wrapper options:
  --php-fpm FILE        php-fpm binary. Default: ../sapi/fpm/php-fpm
  --php FILE            PHP CLI binary. Default: ../sapi/cli/php
  --nginx-bin FILE      nginx binary. Default: /usr/sbin/nginx
  --base-url URL        Benchmark endpoint URL.
  --apcu-so FILE        APCu extension module.
  --deepclone-so FILE   DeepClone extension module.
  --shm-size SIZE       opcache.user_cache_shm_size. Default: 64M
  --apc-shm-size SIZE   apc.shm_size. Default: 128M
  --output-dir DIR      Result directory. Default: results

Runner options are passed through to benchmark_user_cache_fpm_read.php:
  --cases, --backends, --operations, --requests, --warmup, --concurrency,
  --hold-us, --output
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

require_file() {
	PATH_VALUE=${1}
	LABEL_VALUE=${2}
	if test ! -f "${PATH_VALUE}"; then
		printf '%s not found: %s\n' "${LABEL_VALUE}" "${PATH_VALUE}" >&2
		exit 1
	fi
}

base_url_port() {
	WITHOUT_SCHEME=${BASE_URL#*://}
	HOST_PORT=${WITHOUT_SCHEME%%/*}
	PORT_VALUE=${HOST_PORT##*:}
	if test "${PORT_VALUE}" = "${HOST_PORT}"; then
		case "${BASE_URL}" in
			http://*)
				PORT_VALUE=80
				;;
			https://*)
				PORT_VALUE=443
				;;
			*)
				PORT_VALUE=
				;;
		esac
	fi
	printf '%s\n' "${PORT_VALUE}"
}

port_in_use() {
	PORT_VALUE=${1}
	if command -v ss >/dev/null 2>&1; then
		ss -ltn 2>/dev/null | awk -v PORT_ARG=":${PORT_VALUE}" '
			$1 == "LISTEN" && index($4, PORT_ARG) { found = 1 }
			END { exit(found ? 0 : 1) }
		'
		return "${?}"
	fi
	return 1
}

assert_ports_free() {
	HTTP_PORT=$(base_url_port)
	if test -n "${HTTP_PORT}" && port_in_use "${HTTP_PORT}"; then
		printf 'benchmark HTTP port is already in use: %s\n' "${HTTP_PORT}" >&2
		exit 1
	fi
	if port_in_use 9000; then
		printf 'php-fpm port is already in use: 9000\n' >&2
		exit 1
	fi
}

cleanup() {
	EXIT_CODE=${?}
	trap - 0 2 15
	if test -n "${NGINX_PID}"; then
		kill "${NGINX_PID}" >/dev/null 2>&1 || true
		wait "${NGINX_PID}" >/dev/null 2>&1 || true
	fi
	if test -n "${PHP_FPM_PID}"; then
		kill "${PHP_FPM_PID}" >/dev/null 2>&1 || true
		wait "${PHP_FPM_PID}" >/dev/null 2>&1 || true
	fi
	exit "${EXIT_CODE}"
}

wait_for_runtime() {
	ATTEMPT=1
	while test "${ATTEMPT}" -le 60; do
		if curl -fsS "${BASE_URL}?action=describe" >/dev/null 2>&1; then
			return 0
		fi
		sleep 1
		ATTEMPT=$((ATTEMPT + 1))
	done
	return 1
}

while test "${#}" -gt 0; do
	case "${1}" in
		--php-fpm)
			PHP_FPM_BIN=$(absolute_path "${2:?--php-fpm requires a value}")
			shift 2
			;;
		--php)
			PHP_CLI_BIN=$(absolute_path "${2:?--php requires a value}")
			shift 2
			;;
		--nginx-bin)
			NGINX_BIN=$(absolute_path "${2:?--nginx-bin requires a value}")
			shift 2
			;;
		--base-url)
			BASE_URL=${2:?--base-url requires a value}
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
		--shm-size)
			SHM_SIZE=${2:?--shm-size requires a value}
			shift 2
			;;
		--apc-shm-size)
			APC_SHM_SIZE=${2:?--apc-shm-size requires a value}
			shift 2
			;;
		--output-dir)
			OUTPUT_DIR=$(absolute_path "${2:?--output-dir requires a value}")
			shift 2
			;;
		-h|--help-wrapper)
			usage
			exit 0
			;;
		*)
			break
			;;
	esac
done

PHP_FPM_BIN=$(absolute_path "${PHP_FPM_BIN}")
PHP_CLI_BIN=$(absolute_path "${PHP_CLI_BIN}")
APCU_SO=$(absolute_path "${APCU_SO}")
DEEPCLONE_SO=$(absolute_path "${DEEPCLONE_SO}")
OUTPUT_DIR=$(absolute_path "${OUTPUT_DIR}")

require_executable "${PHP_FPM_BIN}" "php-fpm"
require_executable "${PHP_CLI_BIN}" "PHP CLI"
require_executable "${NGINX_BIN}" "nginx"
require_file "${APCU_SO}" "APCu extension"
require_file "${DEEPCLONE_SO}" "DeepClone extension"
mkdir -p "${OUTPUT_DIR}"

trap cleanup 0 2 15
assert_ports_free

cd "${ROOT}"

"${PHP_FPM_BIN}" \
	-n \
	-d "extension=${APCU_SO}" \
	-d "extension=${DEEPCLONE_SO}" \
	-d apc.enabled=1 \
	-d "apc.shm_size=${APC_SHM_SIZE}" \
	-d opcache.enable=1 \
	-d opcache.enable_cli=0 \
	-d opcache.validate_timestamps=0 \
	-d opcache.jit=0 \
	-d "opcache.user_cache_shm_size=${SHM_SIZE}" \
	-y "${ROOT}/php-fpm.conf" &
PHP_FPM_PID=${!}

"${NGINX_BIN}" -p "${ROOT}" -c "${ROOT}/nginx.conf" &
NGINX_PID=${!}

if ! wait_for_runtime; then
	printf 'php-fpm benchmark runtime did not become ready: %s?action=describe\n' "${BASE_URL}" >&2
	exit 1
fi

"${PHP_CLI_BIN}" "${ROOT}/scripts/benchmark_user_cache_fpm_read.php" \
	--base-url "${BASE_URL}" \
	--output "${OUTPUT_DIR}/user-cache-fpm-read-$(date -u +%Y%m%dT%H%M%SZ).json" \
	"${@}"
