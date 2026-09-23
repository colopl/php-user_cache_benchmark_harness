# UserCache Benchmark Harness

**[View latest benchmark result](https://colopl.github.io/php-user_cache_benchmark_harness/)**

This directory contains the minimal benchmark harness used to evaluate
`UserCache\Cache` against APCu serializer variants and ext-yac.
It also contains the complete diagnostic microbenchmark suite and a separately
built FrankenPHP persistence comparison. All workers and build adapters live in
this repository; they do not load scripts from the PHP source tree's `docs/`.

The primary target is read-heavy shared-cache usage. Write workloads are also
reported for context, but they are not the main success criterion because APCu
style caches are normally used to amortize store cost across many reads.

## Diagnostic microbenchmarks

```sh
# Short correctness run of every migrated workload.
./benchmark.sh --micro --quick

# Full single-build measurement, or an alternating before/after comparison.
./benchmark.sh --micro --rounds 9 --target-ms 150
./benchmark.sh --micro --php /path/to/current/php --before /path/to/baseline/php
./benchmark.sh --micro --list
```

This is a development harness: it writes full samples, `micro.json` and a
standalone `micro.html` under `results/micro-*/` and is not published.
`--output FILE` additionally copies the HTML report to `FILE`. The suites cover scalar/string/array/object reads, bulk
operations, locks, store/delete, memory retention, pool status invalidation,
LRU key sets, 1/2/4/8-process writers, empty-pool first misses, snapshot retention,
and the original lock-churn review benchmark. See
[scripts/microbench/README.md](scripts/microbench/README.md) for the case catalogue
and migration coverage. Fault-injection and lifecycle correctness probes are
separate tests, not performance workloads.

`--suites` and `--cases` select workloads. Python 3.10 or newer and a CLI build with
`user_cache` are required; concurrent writer cases additionally need `pcntl`.
The report records skipped conditions instead of presenting them as timings.
A `--quick` report is marked as a smoke run and is not a performance conclusion.

## FrankenPHP persistence comparison

```sh
# Build PHP and the FrankenPHP benchmark hosts in runtime/frankenphp/.
./benchmark.sh --build-persistence --php-src /path/to/php-src --jobs 4

# Build when necessary, validate the workload matrix, and generate HTML.
./benchmark.sh --persistence --php-src /path/to/php-src --quick

# Formal measurement with paired micro samples and repeated HTTP workloads.
./benchmark.sh --persistence --php-src /path/to/php-src

# Reuse an already built host without rebuilding.
./benchmark.sh --persistence --no-build --build-dir runtime/frankenphp

# Optional classic-request variant of the paired value workloads.
./benchmark.sh --persistence --no-build --mode classic --stage micro
```

The output is **[BENCH_RESULT_PERSISTENCE.html](BENCH_RESULT_PERSISTENCE.html)**.
Raw samples, build metadata, checksums and a machine-readable summary are kept
under `results/persistence-*/`. Use `--help` after each mode for all options.
Each run also saves `source-inputs.tar.gz` before timing: the exact PHP input
tree, including uncommitted files, plus verified fixtures, Go hosts, adapter,
configuration and measurement scripts. This preserves the inputs when a later
build replaces `runtime/frankenphp/`. Executables, libraries and downloaded Go
dependencies are not bundled; their hashes or pinned module versions are
recorded. The HTML embeds its aggregate JSON for offline viewing; raw samples
and the source archive remain separate files in the result directory.
Both benchmark modes share the existing process lock with the standard suite;
FrankenPHP is built before any timed samples begin.
`BENCH_RESULT.html` summarizes this report and links to it; after a new
persistence run, refresh the summary with `./benchmark.sh --render-only
--results-dir results/read-workloads-<timestamp>`.
The default persistence mode is `worker`; `--mode classic` changes the micro and
A/A hosts only. HTTP scaling always uses persistent workers. Classic-mode pin
counts are sampled inside the request, so they can legitimately be nonzero;
that variant does not claim to verify pin release after request shutdown.

The PHP build is isolated from the source checkout and uses its current files,
including local changes. It configures ZTS and a shared embed library, disables
Zend signals and enables the Linux execution timers required by
[FrankenPHP's build instructions](https://frankenphp.dev/docs/compile/).
It does not reconfigure the CLI/FPM build in the source checkout. The
FrankenPHP revision is pinned to
`51e6246e71f335b96ac22d7782f5b20392555109`; its version and source/build hashes
are recorded with the results. These build targets currently support Linux.
They require a C toolchain, make, autoconf, bison, re2c, pkg-config, Git, Python 3.10+
and Go with automatic toolchain selection enabled. Network access is needed for
FrankenPHP and uncached Go dependencies; the pinned module selects Go 1.27.
Optional watcher, Brotli and Mercure features are disabled in these benchmark
hosts.
`--frankenphp-src /path/to/checkout` can supply that commit from an existing
local Git repository; its working files are not modified. Builds record all
input and artifact hashes, and are reused only while their configuration and
files still match. Changing the build directory or effective Go settings also
invalidates reuse.

The comparison uses FrankenPHP's unmodified
[`zval.h` persistent-value helpers](https://github.com/php/frankenphp/blob/51e6246e71f335b96ac22d7782f5b20392555109/zval.h).
The `reference` backend adds a benchmark-owned persistent HashTable, read/write
lock, TTL, generation checks and per-worker COW prototypes. It is explicitly
labelled as a helper-based reference cache, not a public FrankenPHP cache API.
A separate `raw` row measures conversion alone and is excluded from cache
rankings. Process-shared storage, capacity limits, eviction, general objects and
references are not equivalent features of this reference backend.

The persistence suite includes all 33 paired value cases (cold/warm, mutation,
TTL and 32,768-key sets), reference/reference noise measurements, and real HTTP
loads using 1/2/4 workers, 1/100 cache operations per request, arrays or scalars,
and reads or 99:1 read/write mixes. Backends are measured sequentially in paired
or alternating order. HTTP throughput is from a closed-loop client in the host
process. The report separates cache batch time, PHP thread CPU, HTTP latency,
PHP heap, configured SHM and reference allocation requests; they are not one
combined memory or latency score.

## Requirements

The following requirements apply to the standard CLI/FPM suite. The independent
micro and persistence modes have the requirements described above.

- A PHP source tree built from this checkout.
- `sapi/cli/php` for CLI benchmarks.
- `sapi/fpm/php-fpm` and `nginx` for FPM worker benchmarks.
- `composer install` in this directory for Carbon workloads.
  Composer itself needs a PHP binary with Phar support; the benchmarked PHP
  build does not have to be the one used to install dependencies.
- Network access when APCu, igbinary or Yac must be built by the wrapper.
- PHP built with `--enable-pcntl`; ZTS builds also need
  `--enable-embed=static`. Build extension modules separately for NTS and ZTS.

The wrapper builds APCu, igbinary and Yac into `runtime/extensions/` when their
modules are missing. APCu and Yac are built from upstream `master` by default.
Yac 2.4.0 or newer is required for a distinct cache-miss default value. Generated benchmark output is written under `results/`.

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
- `fetchMultiple()` bulk-read checks for 32 and 128 keys. APCu is measured with
  both the php and the igbinary serializer; the igbinary rows come from a second
  process and are merged into the same result file.
- FPM request benchmarks with multiple workers.

Per-run artifacts land in `results/<base>/run1..runN/` (each with its own
`report.html`), the aggregate in `results/<base>/median/`, and the combined
median report in `BENCH_RESULT.html`.

The combined report opens with a head-to-head against APCu/igbinary: per-section
UserCache wins, geometric-mean and extreme speedups, a per-workload speedup
table and the bulk-read comparison. When `BENCH_RESULT_PERSISTENCE.html` exists
it also summarizes the FrankenPHP comparison from the aggregate JSON embedded in
that file (paired ratios with their 95% intervals, A/A noise, HTTP scaling) and
links to it. Set `PERSISTENCE_REPORT=` to leave it out, or to another path to
use a different report.

`--render-only --results-dir DIR` re-renders `--output` from an existing results
directory (its `median/` aggregate when present) without measuring, for example
after the persistence report was regenerated.

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
  --cpus 0-3 \
  --shm-size-mb 128 \
  --results-dir /tmp/user-cache-bench \
  --output /tmp/user-cache-bench/BENCH_RESULT.html
```

`--shm-size-mb` accepts an integer MiB value and is passed as
`user_cache.shm_size=${N}M` and `apc.shm_size=${N}M`. Yac uses
`yac.values_memory_size=${N}M` plus a separate 8 MiB key table. These are
configured capacities; actual reserved sizes and startup usage are reported
separately in the memory table. Yac uses the PHP serializer with compression
disabled (`yac.compress_threshold=-1`) in CLI, bulk and FPM workloads.

Use `--yac-so /path/to/yac.so` (or `YAC_SO`) to provide an existing module.
The option is forwarded to isolated workers, both FPM serializer runs and
multi-run invocations. Use different module paths for different PHP builds.

## CPU Pinning

Every mode runs its measured processes pinned to one CPU set, `0-3` by default.
Change it with `--cpus LIST` (standard suite) or `UC_BENCH_CPUS=LIST` (all modes,
including `--micro` and `--persistence`); `all` disables pinning. The standard
suite re-executes itself under `taskset`, so the PHP CLI, php-fpm workers, nginx
and the request client all inherit the set, and it records the set in
`<results-dir>/cpus.txt`. The micro runner takes its CPUs from the mask, so
cases needing more CPUs than the set provides are reported as skipped. The
persistence runner forwards the set as its own `--cpus` unless one is given.

Compare builds only between runs that used the same set. On hosts that run
vCPUs on a mix of performance and efficiency cores (for example Linux
containers on Apple silicon), per-vCPU throughput depends on how many vCPUs are
busy: on the reference 8-vCPU machine it stayed within 4% up to four busy
vCPUs and fell by 13% at six and 19% at eight. `scripts/detect_benchmark_cpus.py`
loads every allowed CPU at once and prints the CPUs that keep up with the
fastest; run it under `taskset -c LIST` to compare candidate sets.

Scripts under `scripts/` that are run directly are not pinned; wrap them in
`taskset -c "$UC_BENCH_CPUS"` when comparing their numbers.

## Lower-Level Entrypoints

`./benchmark.sh` is the top-level entry point; everything below it lives in
`scripts/` and is normally invoked by `./benchmark.sh`, not run directly.

`scripts/benchmark_user_cache.sh`
: Runs the core CLI benchmark. Wrapper options must appear before benchmark
  options.

`scripts/benchmark_user_cache_fpm_read.sh`
: Starts local php-fpm and nginx, then runs the FPM read runner. APCu, Yac and
  serializer helper modules must already exist or be provided with `--apcu-so`,
  `--yac-so` and `--igbinary-so`.

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
: APCu with `apc.serializer=igbinary`. Shown as `APCu/igbinary` in reports.

`yac`
: [Yac](https://github.com/laruence/yac) with `Yac::set()`, `get()` and
  `get(array)`, plus per-key loops. Shown as `Yac/php`. Cache misses and failed
  writes fail the workload. CLI and FPM keys are shortened for every backend
  before timing to fit Yac's 48-byte key limit, including write indexes.

## Reading the Report

`Faster` lists all measured backends in the same cell, fastest first. Each
ratio is the backend's time divided by the fastest time: for example,
`Yac/php 1.00x`, `APCu/php 1.25x`, `UserCache 2.00x`. Lower ratios are better.
The fastest timing cells have a green background; ties are all highlighted.
Invalid or missing timing measurements do not enter the ranking.

The CLI and combined HTML reports include a memory comparison based on the
[colopl_xcache harness](https://github.com/colopl/php-colopl_xcache/tree/main/colopl_xcache_benchmark).
Measurements run outside the timed CLI read loops:

- **cache**: shared-memory increase from storing one value, including the
  entry, key and allocator rounding.
- **first fetch**: PHP heap retained by the first fetch after storing the value
  in the same request, including any request-local bookkeeping.
- **per fetch**: PHP heap retained while holding one fetched value in an
  already-warm request. This measures the fetch without mutation.
- **request hold**: heap left after releasing the values and collecting cycles,
  including state retained by the store in the same request.

The smallest nonnegative cache and per-fetch figures are highlighted
independently, including ties and zero-byte values. Shared-memory cost and
request-heap cost have different winners; they should not be added into a
single score. Reserved capacity and the baseline used before storing are
shown separately. The baseline is startup overhead with default case isolation;
`--no-isolate` can include state left by previous workloads.

Yac does not expose used allocator bytes, so its cache delta and used baseline
are `n/a`. Its reserved capacity and PHP heap measurements are still shown.
Older JSON without memory fields remains readable. All recorded memory fields
are aggregated by median across runs alongside timings.

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

## Harness Tests

From this directory, using the PHP build under test:

```sh
python3 -m unittest discover -s scripts/microbench/tests -v
python3 tests/persistence_report.py
python3 tests/persistence_artifacts.py
python3 tests/build_frankenphp.py
../sapi/cli/php tests/render_report_fpm_serializer_split.php
../sapi/cli/php tests/render_report_summaries.php
../sapi/cli/php tests/benchmark_comparison.php
../sapi/cli/php tests/aggregate_memory.php
../sapi/cli/php -n -d extension="$PWD/runtime/extensions/yac/yac.so" \
  -d yac.enable_cli=1 -d yac.compress_threshold=-1 tests/yac_backend.php
```

The Yac test skips with a reason if the required extension or settings are
unavailable. `./benchmark.sh --quick --runs 2` additionally exercises CLI,
bulk, FPM and median aggregation together. These short runs validate the
harness; use the full suite for published performance numbers.

## Generated Files

The HTML reports `BENCH_RESULT.html` and `BENCH_RESULT_PERSISTENCE.html` are
generated snapshots that can be published through the Pages workflow. Existing
reports are only replaced by their corresponding suite. Pages serves
`BENCH_RESULT.html` as the index next to the persistence report, and the
deployment fails if the index links a report that is not committed.

The following generated paths are ignored:

- `results/`
- `runtime/`
- `vendor/`

Run `composer install` again if `vendor/` is absent and Carbon workloads are
needed.
