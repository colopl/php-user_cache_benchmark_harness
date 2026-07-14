# UserCache Benchmark Harness

**[View latest benchmark result](https://colopl.github.io/php-user_cache_benchmark_harness/)**

This directory contains the minimal benchmark harness used to evaluate
`UserCache\Cache` against APCu serializer variants.

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
- Network access when APCu or igbinary must be built by the wrapper.

The wrapper builds APCu and igbinary into `runtime/extensions/` when their
modules are missing. Generated benchmark output is written under `results/`.

## Quick Run

From the PHP source root:

```sh
php-user_cache_benchmark_harness/benchmark.sh --quick
```

The quick run executes a short smoke version of the full workload as a single
run (use `--runs N` to aggregate several quick runs) and writes:

- `php-user_cache_benchmark_harness/BENCH_RESULT.html`
- raw JSON and per-step HTML files under `php-user_cache_benchmark_harness/results/`

## Full Read-Heavy Report

```sh
php-user_cache_benchmark_harness/benchmark.sh
```

By default this executes the full suite **3 times** and renders
`BENCH_RESULT.html` from the per-(case, backend) **median across runs**
(single runs swing by tens of percent on the FPM one-fetch workloads, so
published numbers must be multi-run medians). Each run performs:

- CLI repeated-read workloads.
- CLI write/store context workloads.
- resident/preloaded direct-access probes, paired with CLI read results to show
  post-store fetch/materialization overhead.
- `fetchMultiple()` bulk-read checks for 32 and 128 keys.
- FPM request benchmarks with multiple workers.

Per-run artifacts land in `results/<base>/run1..runN/` (each with its own
`report.html`), the aggregate in `results/<base>/median/`, and the combined
median report in `BENCH_RESULT.html`.

FPM rows also include the per-run p25-p75 range in the HTML report. This keeps
the default runtime shorter while making noisy one-fetch comparisons easier to
interpret.

Use `--no-fpm` to skip the FPM/nginx portion and `--runs 1` for a single
measurement run (not suitable for published numbers).

The full harness is guarded by a process lock so that separate benchmark runs do
not overlap. Within the FPM runner, case/backend workloads are still measured
serially; only the curl requests for the currently measured backend are issued
with the configured worker concurrency. FPM backends are sampled in interleaved
batches within each case to reduce time-window bias without running different
backends concurrently.

## Useful Options

```sh
php-user_cache_benchmark_harness/benchmark.sh \
  --php /path/to/sapi/cli/php \
  --php-fpm /path/to/sapi/fpm/php-fpm \
  --nginx-bin /usr/sbin/nginx \
  --shm-size-mb 128 \
  --results-dir /tmp/user-cache-bench \
  --output /tmp/user-cache-bench/BENCH_RESULT.html
```

`--shm-size-mb` accepts an integer MiB value and is passed as
`user_cache.shm_size=${N}M`.

## Lower-Level Entrypoints

`./benchmark.sh` is the top-level entry point; everything below it lives in
`scripts/` and is normally invoked by `./benchmark.sh`, not run directly.

`scripts/benchmark_user_cache.sh`
: Runs the core CLI benchmark. Wrapper options must appear before benchmark
  options.

`scripts/benchmark_user_cache_fpm_read.sh`
: Starts local php-fpm and nginx, then runs the FPM read runner. APCu and
  serializer helper modules must already exist or be provided with `--apcu-so`
  and `--igbinary-so`.

`scripts/render_user_cache_performance_report.php`
: Combines existing JSON files into a single HTML report.

`scripts/benchmark_user_cache_bulk_read.php`
: Measures `fetchMultiple()` and looped fetch behavior.

`scripts/benchmark_user_cache_resident_probe.php`
: Measures the cost of probing already-resident payloads.

## Compared Backends

`user_cache`
: `UserCache\Cache::store()`, `fetch()`, and `fetchMultiple()`. The
  harness uses the current enum-shaped `UserCache\CacheStatus::getAvailability()` API.

`apcu`
: `apcu_store()`, `apcu_fetch()`, and `apcu_fetch()` loops.

`apcu_igbinary`
: APCu with `apc.serializer=igbinary`. Shown as `APCu@igbinary` in reports.

## Workload Coverage

The default workload set includes:

- scalar and array payloads.
- framework-shaped route/config arrays.
- large strings.
- userland metadata object graphs.
- fetch-and-mutate object read cases.
- `__serialize` and `__sleep` / `__wakeup` contract workloads.
- recursive reference graphs and mixed serialization payloads.
- product-listing view-model payloads with DTO objects, facets, and request
  context.
- multi-key configuration maps, assignment-style object graphs, cyclic graphs,
  and nested-array assignment payloads.
- direct-restore DateTime and SPL objects.
- Carbon datetime graphs.
- dummy model objects containing Carbon properties.

## Multi-Run Median Aggregation

Every table and winner marker in the combined report uses medians, and the
wrapper runs the suite 3 times by default, aggregating with
`scripts/aggregate_results_median.php` before rendering. The pieces can also
be driven manually, e.g. to re-aggregate existing run directories:

```sh
# 1. Take N full single runs into separate results dirs.
./benchmark.sh --runs 1 --results-dir results/run1
# ... repeat for run2..run3 ...

# 2. Merge them; every metric becomes the median across runs.
php scripts/aggregate_results_median.php --output results/median3 \
    results/run1 results/run2 results/run3

# 3. Render the combined report from the aggregated directory.
php scripts/render_user_cache_performance_report.php \
    --cli-read results/median3/cli-read/user-cache-benchmark-median.json \
    --cli-write results/median3/cli-write/user-cache-benchmark-median.json \
    --resident results/median3/resident-payload-probe.json \
    --bulk results/median3/bulk-read-32.json --bulk results/median3/bulk-read-128.json \
    --fpm-once results/median3/fpm-read-once-php.json --fpm-once results/median3/fpm-read-once-igbinary.json \
    --fpm-hot results/median3/fpm-read-hot-php.json --fpm-hot results/median3/fpm-read-hot-igbinary.json \
    --output BENCH_RESULT.html
```

## Generated Files

The following paths are intentionally generated and ignored:

- `BENCH_RESULT.html`
- `results/`
- `runtime/`
- `vendor/`

Run `composer install` again if `vendor/` is absent and Carbon workloads are
needed.
