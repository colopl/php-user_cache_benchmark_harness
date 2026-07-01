# OPcache UserCache Benchmark Harness

**[View latest benchmark result](https://colopl.github.io/php-opcache_user_cache_benchmark_harness/)**

This directory contains the minimal benchmark harness used to evaluate
`Opcache\UserCache` against APCu and APCu + DeepClone.

The primary target is read-heavy shared-cache usage. Write workloads are also
reported for context, but they are not the main success criterion because APCu
style caches are normally used to amortize store cost across many reads.

## Requirements

- A PHP source tree built from this checkout.
- `sapi/cli/php` for CLI benchmarks.
- `sapi/fpm/php-fpm` and `nginx` for FPM worker benchmarks.
- `composer install` in this directory for Carbon workloads.
  Composer itself needs a PHP binary with Phar support; the benchmarked PHP
  build does not have to be the one used to install dependencies.
- Network access when APCu or DeepClone must be built by the wrapper.

The wrapper builds APCu and DeepClone into `runtime/extensions/` when their
modules are missing. Generated benchmark output is written under `results/`.

## Quick Run

From the PHP source root:

```sh
php-opcache_user_cache_benchmark_harness/scripts/benchmark_user_cache_read_workloads.sh --quick
```

The quick run executes a short smoke version of the full workload and writes:

- `php-opcache_user_cache_benchmark_harness/BENCH_RESULT.html`
- raw JSON and per-step HTML files under `php-opcache_user_cache_benchmark_harness/results/`

## Full Read-Heavy Report

```sh
php-opcache_user_cache_benchmark_harness/scripts/benchmark_user_cache_read_workloads.sh
```

This performs:

- CLI repeated-read workloads.
- CLI write/store context workloads.
- resident/preloaded direct-access probes, paired with CLI read results to show
  post-store fetch/materialization overhead.
- `fetchMultiple()` bulk-read checks for 32 and 128 keys.
- FPM request benchmarks with multiple workers.
- combined HTML rendering to `BENCH_RESULT.html`.

Use `--no-fpm` to skip the FPM/nginx portion.

## Useful Options

```sh
php-opcache_user_cache_benchmark_harness/scripts/benchmark_user_cache_read_workloads.sh \
  --php /path/to/sapi/cli/php \
  --php-fpm /path/to/sapi/fpm/php-fpm \
  --nginx-bin /usr/sbin/nginx \
  --shm-size-mb 128 \
  --results-dir /tmp/user-cache-bench \
  --output /tmp/user-cache-bench/BENCH_RESULT.html
```

`--shm-size-mb` accepts an integer MiB value and is passed as
`opcache.user_cache_shm_size=${N}M`.

## Lower-Level Entrypoints

`scripts/benchmark_user_cache.sh`
: Runs the core CLI benchmark. Wrapper options must appear before benchmark
  options.

`scripts/benchmark_user_cache_fpm_read.sh`
: Starts local php-fpm and nginx, then runs the FPM read runner. APCu and
  DeepClone modules must already exist or be provided with `--apcu-so` and
  `--deepclone-so`.

`scripts/render_user_cache_performance_report.php`
: Combines existing JSON files into a single HTML report.

`scripts/benchmark_user_cache_bulk_read.php`
: Measures `fetchMultiple()` and looped fetch behavior.

`scripts/benchmark_user_cache_resident_probe.php`
: Measures the cost of probing already-resident payloads.

## Compared Backends

`user_cache`
: `Opcache\UserCache::store()`, `fetch()`, and `fetchMultiple()`.

`apcu`
: `apcu_store()`, `apcu_fetch()`, and `apcu_fetch()` loops.

`deepclone`
: `deepclone_to_array()` + `apcu_store()`, then `apcu_fetch()` +
  `deepclone_from_array()`.

## Workload Coverage

The default workload set includes:

- scalar and array payloads.
- framework-shaped route/config arrays.
- large strings.
- userland metadata object graphs.
- fetch-and-mutate object read cases.
- direct-restore DateTime and SPL objects.
- Carbon datetime graphs.
- dummy model objects containing Carbon properties.

## Generated Files

The following paths are intentionally generated and ignored:

- `BENCH_RESULT.html`
- `results/`
- `runtime/`
- `vendor/`

Run `composer install` again if `vendor/` is absent and Carbon workloads are
needed.
