# Standalone user_cache microbenchmarks

This directory contains the current microbenchmark workloads migrated from the PHP implementation repository. The PHP workers, case catalog, comparison runner and HTML renderer are self-contained. No file under `php-src/docs` is loaded at runtime. A PHP CLI binary with `user_cache` built in is required; Python 3.10 or later runs the comparisons and renders the report.

From the harness root:

```sh
./benchmark.sh --micro --quick
./benchmark.sh --micro --php /path/to/php --output-dir results/micro-current
./benchmark.sh --micro --php /path/to/after/php --before /path/to/before/php \
  --rounds 9 --target-ms 150 --output-dir results/micro-comparison
```

The same options work directly with `python3 scripts/microbench/run.py`. The default PHP binary is `PHP_CLI_BIN`, or `../sapi/cli/php` relative to the harness. Each run writes `micro.json` and a standalone `micro.html` in its output directory; `--output FILE` additionally copies the HTML report to `FILE`. The suite is a development harness and is not published; `BENCH_RESULT.html` is unaffected. The default output directory is `results/micro-<UTC timestamp>-<PID>`.

`--php` alone produces a single-build report with no speedup claim. With `--before`, each round alternates before/after execution order and both binaries receive the same iteration count. Every sample starts a new PHP process. A smoke run uses fewer rounds and shorter timed loops; it still selects the complete case matrix and preserves working-set sizes. Do not use `--quick` results as performance conclusions.

## Selecting workloads

```sh
./benchmark.sh --micro --list
./benchmark.sh --micro --list --suites writers snapshot
./benchmark.sh --micro --quick --cases core/fetch_scalar_1 review/pipeline \
  'status/*_128' writers/increment_same_4 snapshot/keys_4096
```

`--suites` and `--cases` accept space-separated or comma-separated values. Case IDs use `suite/name`; case patterns accept shell-style wildcards, which should be quoted. Unknown suites or unmatched patterns are errors. `--list` does not require an available PHP binary and does not acquire the benchmark lock.

| Suite | Cases | Coverage |
| --- | ---: | --- |
| `core` | 38 | Scalar/string working sets; arrays and object varieties; missing and long keys; store/delete; bulk operations; lock/unlock; remember; status; fixed memory workloads. |
| `review` | 1 pipeline | Three store measurements before and after 20,000 distinct-key lock cycles; 10,000 string store/delete cycles with retained memory; one 2,000-entry bulk store. All phase metrics and the three raw store samples are retained. |
| `status` | 21 | 0/128/4,096 pool keys, 16,384 other-pool keys, first/warm status, same/other pool scalar and string updates, delete/store churn. |
| `lru` | 12 | Scalar/string/array reads over 1/64/128/2,048 keys. |
| `writers` | 44 | 1/2/4/8 processes; same/disjoint keys; scalar overwrite, increment, string, array and 99:1 mixed reads/writes; disjoint new-key insertion. |
| `pools` | 8 | Original 2,000-pool workload, cold/warm controls, creation-only warmup, clear-inclusive total, creation only, and existing-pool first/repeated misses. |
| `snapshot` | 3 | Retained status snapshots with 0/128/4,096 keys. |

The 127-case catalog and origin-to-case mapping are in [coverage.json](coverage.json). `pools/original` intentionally repeats `core/memory_pools` as a control. The review case preserves its original sequence in one PHP process because lock-table churn can affect subsequent store timings. In quick mode its store, lock-churn and store/delete loop counts are shortened and explicitly reported; its bulk store still has 2,000 entries.

Correctness-only PHPTs, lifecycle assertions, GDB clock probes and fault injection are excluded explicitly in the manifest. The FrankenPHP HTTP benchmarks are a separate component; they are not silently replaced by these CLI workloads. Historical source copies and historical results can remain outside this directory, but do not serve as runtime dependencies.

## Measurement details

The runner takes the same `runtime/benchmark.lock` directory lock as the shell harness. It honors `UC_BENCH_LOCK_HELD=1` and `UC_BENCH_LOCK_DIR` when called by a wrapper that already owns the lock. A direct invocation records its PID and releases only its own lock. Run performance workloads sequentially, without concurrent builds or tests.

On systems supporting CPU affinity, single-process cases use the first available CPU and writer cases use the first 1/2/4/8 CPUs. `./benchmark.sh --micro` runs inside the harness CPU set (`UC_BENCH_CPUS`, default `0-3`), so the 8-process writer cases are skipped unless a larger set such as `UC_BENCH_CPUS=0-7` is chosen; see the top-level README. `--cpu N` chooses the first CPU while retaining the remaining allowed CPUs for parallel cases. Without affinity support the report records that limitation. Cases requiring more CPUs than the allowed mask, or writers without `pcntl_fork`/`stream_socket_pair`, receive explicit skipped records rather than timings. Every case remains visible in `--list` and in the coverage manifest.

All runs use `-n`, `user_cache.enable_cli=1`, and `memory_limit=512M`. The core, pools and snapshot suites use SHM 128 MiB and `entries_hint=0`. Status/LRU/writers use SHM 128 MiB and `entries_hint=131072`. The original review pipeline uses SHM 64 MiB. Exact command lines and CPU masks are saved per sample. CLI configuration is isolated from the machine's php.ini.

Defaults are 9 rounds and a 150 ms target; quick defaults are 1 round and 5 ms. Cases with variable iterations calibrate against the baseline, or the current binary in single-build mode. Fixed memory workloads, first status reads, pool controls, snapshots and the review pipeline retain their own workload sizes. Writer insertion is capped at 8,192 keys per process. Long setup and short first-read timing are recorded separately where the worker provides those fields.

The report includes all samples and calibration results, medians and min/max, PHP version/ZTS mode, platform/CPU information, binary SHA-256 values, worker/runner/renderer/manifest SHA-256 values and exact commands. It verifies that binaries did not change during measurement. Failed workers retain their output and produce a nonzero runner exit; timeouts terminate the PHP process group, including forked workers. The HTML contains its JSON data and a download button, so it can be moved or opened offline without other files.

Core, LRU and status workers validate final values and counts after recording elapsed time and PHP memory metrics. LRU also checks the checksum accumulated by its original timed loop. These assertions detect failed final results and changed contents without adding branches to the timed loops; they do not prove that every intermediate read returned the right value. Their JSON records include `validation_checks` and `errors`. The writer workload additionally checks operation results and final shared increments for lost updates.

Interpretation limits:

- Writer `ns_per_operation` divides wall time by operations across all processes. Its p50/p95/p99 values summarize averages over batches of at most 256 operations, not individual-operation latency tails.
- Snapshot bytes include the PHP snapshot management table and retained strings/arrays. They are not RSS or SHM usage. An invalidated snapshot can remain after `clear()` until another status collection or pool release.
- Pool warmup also warms PHP allocations and preserves registry capacity. A cold/warm difference alone does not establish that SHM cache locality caused it. `end-to-end` includes clear and seed costs.
- A low median from a short measurement or a large min/max spread should not be treated as a stable performance guarantee. Quick reports label themselves as smoke measurements.

The tests exercise orchestration with a fake PHP executable and, when `PHP_CLI_BIN` or the default CLI binary is available, worker assertions with a fake in-memory cache. They do not run performance measurements or use shared cache storage:

```sh
python3 -m unittest discover -s scripts/microbench/tests -v
```
