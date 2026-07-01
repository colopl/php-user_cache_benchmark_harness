#!/bin/sh

set -eu

PHPIZE=${1:?usage: build_apcu.sh /path/to/phpize /path/to/php-config /path/to/output-dir optional-work-dir}
PHP_CONFIG=${2:?usage: build_apcu.sh /path/to/phpize /path/to/php-config /path/to/output-dir optional-work-dir}
OUTPUT_DIR=${3:?usage: build_apcu.sh /path/to/phpize /path/to/php-config /path/to/output-dir optional-work-dir}
WORK_DIR=${4:-${OUTPUT_DIR}/build-src}
APCU_VERSION=${APCU_VERSION:-master}
APCU_REPO=${APCU_REPO:-https://github.com/krakjoe/apcu.git}
SCRIPT_DIR=$(CDPATH= cd "$(dirname "${0}")" && pwd)
SOURCE_ROOT=$(CDPATH= cd "${SCRIPT_DIR}/../.." && pwd)
BUILD_ROOT=$(CDPATH= cd "$(dirname "${PHPIZE}")/.." && pwd)
STAGE_PREFIX=${OUTPUT_DIR}/php-prefix
WRAPPER_PHPIZE=${OUTPUT_DIR}/phpize
WRAPPER_PHP_CONFIG=${OUTPUT_DIR}/php-config
EXTRA_CPPFLAGS=${CPPFLAGS:-}
EXTRA_CFLAGS=${CFLAGS:-}
MAKE_JOBS=$(getconf _NPROCESSORS_ONLN 2>/dev/null || echo 1)

mkdir -p "${OUTPUT_DIR}"
chmod u+x "${PHPIZE}" "${PHP_CONFIG}"

rm -rf "${STAGE_PREFIX}"
mkdir -p "${STAGE_PREFIX}/bin" "${STAGE_PREFIX}/include/php" "${STAGE_PREFIX}/lib/php/build"
cp -a "${SOURCE_ROOT}/main" "${STAGE_PREFIX}/include/php/"
cp -a "${SOURCE_ROOT}/TSRM" "${STAGE_PREFIX}/include/php/"
cp -a "${SOURCE_ROOT}/Zend" "${STAGE_PREFIX}/include/php/"
ln -s "${SOURCE_ROOT}/ext" "${STAGE_PREFIX}/include/php/ext"
cp -a "${BUILD_ROOT}/main/php_config.h" "${STAGE_PREFIX}/include/php/main/php_config.h"
cp -a "${BUILD_ROOT}/main/build-defs.h" "${STAGE_PREFIX}/include/php/main/build-defs.h"
cp -a "${BUILD_ROOT}/Zend/zend_config.h" "${STAGE_PREFIX}/include/php/Zend/zend_config.h"
ln -s "${BUILD_ROOT}/sapi/cli/php" "${STAGE_PREFIX}/bin/php"
cp -a "${SOURCE_ROOT}/build/." "${STAGE_PREFIX}/lib/php/build/"
cp -a "${SOURCE_ROOT}"/run-tests*.php "${STAGE_PREFIX}/lib/php/build/"
ln -s "${SOURCE_ROOT}/scripts/phpize.m4" "${STAGE_PREFIX}/lib/php/build/phpize.m4"

sed \
	-e "s|^prefix='/usr/local'|prefix='${STAGE_PREFIX}'|" \
	-e "s|^datarootdir='/usr/local/php'|datarootdir='${STAGE_PREFIX}/php'|" \
	"${PHPIZE}" > "${WRAPPER_PHPIZE}"
chmod u+x "${WRAPPER_PHPIZE}"

sed \
	-e "s|^prefix=\"/usr/local\"|prefix=\"${STAGE_PREFIX}\"|" \
	-e "s|^datarootdir=\"/usr/local/php\"|datarootdir=\"${STAGE_PREFIX}/php\"|" \
	-e "s|^include_dir=.*|include_dir=\"${STAGE_PREFIX}/include/php\"|" \
	-e "s|^includes=.*|includes=\"-I${STAGE_PREFIX}/include/php -I${STAGE_PREFIX}/include/php/main -I${STAGE_PREFIX}/include/php/TSRM -I${STAGE_PREFIX}/include/php/Zend -I${STAGE_PREFIX}/include/php/ext -I${STAGE_PREFIX}/include/php/ext/date/lib\"|" \
	-e "s|^extension_dir=.*|extension_dir=\"${STAGE_PREFIX}/lib/php/extensions\"|" \
	-e "s|^ini_path=.*|ini_path=\"${STAGE_PREFIX}/lib\"|" \
	"${PHP_CONFIG}" > "${WRAPPER_PHP_CONFIG}"
chmod u+x "${WRAPPER_PHP_CONFIG}"

if test ! -d "${WORK_DIR}/.git"; then
	rm -rf "${WORK_DIR}"
	git clone --depth 1 --branch "${APCU_VERSION}" "${APCU_REPO}" "${WORK_DIR}"
fi

cd "${WORK_DIR}"
if test -f Makefile; then
	make distclean >/dev/null 2>&1 || make clean >/dev/null 2>&1 || true
fi
if grep -q '^#define ZTS 1' "${BUILD_ROOT}/main/php_config.h"; then
	EXTRA_CPPFLAGS="${EXTRA_CPPFLAGS} -DZTS=1"
	EXTRA_CFLAGS="${EXTRA_CFLAGS} -DZTS=1"
fi
"${WRAPPER_PHPIZE}"
CPPFLAGS="${EXTRA_CPPFLAGS}" CFLAGS="${EXTRA_CFLAGS}" ./configure --with-php-config="${WRAPPER_PHP_CONFIG}"
make -j"${MAKE_JOBS}"
cp modules/apcu.so "${OUTPUT_DIR}/apcu.so"

printf '%s\n' "${OUTPUT_DIR}/apcu.so"
