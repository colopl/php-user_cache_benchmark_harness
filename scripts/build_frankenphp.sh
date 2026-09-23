#!/usr/bin/env bash
set -euo pipefail
script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
if [[ " ${*} " == *" --help "* || " ${*} " == *" -h "* ]]; then
    exec python3 "$script_dir/frankenphp/build.py" "$@"
fi
if [[ ${UC_BENCH_LOCK_HELD:-0} != 1 ]]; then
    lock_dir=${UC_BENCH_LOCK_DIR:-"$script_dir/../runtime/benchmark.lock"}
    mkdir -p "$(dirname -- "$lock_dir")"
    if ! mkdir "$lock_dir" 2>/dev/null; then
        printf 'Another benchmark/build holds %s; finish it before building FrankenPHP.\n' "$lock_dir" >&2
        exit 1
    fi
    printf '%s\n' "$$" > "$lock_dir/pid"
    trap 'rm -f "$lock_dir/pid"; rmdir "$lock_dir"' EXIT
    export UC_BENCH_LOCK_HELD=1 UC_BENCH_LOCK_DIR="$lock_dir"
fi
python3 "$script_dir/frankenphp/build.py" "$@"
