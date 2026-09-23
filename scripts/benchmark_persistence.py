#!/usr/bin/env python3
"""Sequential FrankenPHP persistence measurements; only the standard library is needed."""
from __future__ import annotations

import argparse
from collections import defaultdict
from contextlib import contextmanager
from datetime import datetime, timezone
import gzip
import hashlib
import json
import math
import os
from pathlib import Path
import platform
import random
import shutil
import signal
import statistics
import subprocess
import sys
import time

ROOT = Path(__file__).resolve().parent.parent
UPSTREAM = "https://github.com/php/frankenphp/blob/51e6246e71f335b96ac22d7782f5b20392555109/zval.h"
PAYLOADS = ("null", "bool", "int", "double", "string32", "string300", "string4k", "string64k", "packed8", "packed512", "hash32", "nested")
BACKENDS = ("user_cache", "reference")


class InvalidSamples(ValueError):
    """A process completed, but its output is not a complete valid measurement."""


def require(condition, message):
    if not condition:
        raise InvalidSamples(message)


def number(row, key, *, positive=False, integer=False):
    value = row.get(key)
    require(not isinstance(value, bool) and isinstance(value, (int, float)), f"missing/non-numeric {key}")
    require(math.isfinite(value) and (value > 0 if positive else value >= 0), f"invalid {key}: {value}")
    require(not integer or isinstance(value, int), f"non-integer {key}")
    return value


def cases(payloads=PAYLOADS):
    result = []
    for payload in PAYLOADS:
        result.append(dict(payload=payload, keys=1, loops=1000000, temperature="warm", mutate=False, ttl=0))
        if payload not in ("null", "bool", "int", "double"):
            result.append(dict(payload=payload, keys=1, loops=1000000, temperature="warm", mutate=True, ttl=0))
    for payload in ("int", "string300", "packed8", "hash32"):
        for temperature, mutate, loops in (("cold", False, 32768), ("warm", False, 1048576), ("warm", True, 1048576)):
            result.append(dict(payload=payload, keys=32768, loops=loops, temperature=temperature, mutate=mutate, ttl=0))
    result.append(dict(payload="packed8", keys=1, loops=1000000, temperature="warm", mutate=False, ttl=3600))
    return {i: case for i, case in enumerate(result) if case["payload"] in payloads}


def case_key(row):
    return tuple(row[key] for key in ("payload", "keys", "temperature", "mutate", "ttl"))


def observation(payload, mutate):
    if payload in ("null", "bool", "int", "double"):
        return {"null": 0, "bool": 1, "int": 12345, "double": 1}[payload]
    if payload.startswith("string"):
        return {"string32": 32, "string300": 300, "string4k": 4096, "string64k": 65536}[payload] + (122 if mutate else 120)
    if payload == "nested":
        return 39
    return {"packed8": 8, "packed512": 512, "hash32": 32}[payload] + (42 if mutate else 0)


def read_jsonl(path):
    opener = gzip.open if str(path).endswith(".gz") else open
    with opener(path, "rt", encoding="utf-8") as stream:
        for line_number, line in enumerate(stream, 1):
            require(bool(line.strip()), f"{path}:{line_number}: empty JSONL row")
            try:
                row = json.loads(line)
            except (ValueError, UnicodeError) as exc:
                raise InvalidSamples(f"{path}:{line_number}: invalid JSON") from exc
            require(isinstance(row, dict), f"{path}:{line_number}: row is not an object")
            yield row


def validate_micro(rows, *, expected_cases, pairs, session, aa, quick, mode="worker"):
    rows = list(rows)
    require(bool(rows) and rows[0].get("type") == "metadata", "micro metadata must be first")
    meta = rows[0]
    for key, expected in (("pairs", pairs), ("session", session), ("aa", aa), ("quick", quick), ("mode", mode)):
        require(meta.get(key) == expected, f"micro metadata mismatch: {key}")
    seen = set()
    grouped = defaultdict(list)
    raw = defaultdict(list)
    memory_fields = ("php_heap_after_reset", "php_heap_after_seed", "php_heap_after_warm", "php_heap_before", "php_heap_after", "php_peak_heap", "reference_persistent_after_reset", "reference_persistent_after_seed", "shared_used")
    for row in rows[1:]:
        require(row.get("type") == "sample", "unexpected micro row type")
        case_id = number(row, "case_id", integer=True)
        require(case_id in expected_cases, f"unexpected case id {case_id}")
        case = expected_cases[case_id]
        require(case_key(row) == case_key(case), f"case fields mismatch: {case_id}")
        require(isinstance(row.get("mutate"), bool), "mutate must be boolean")
        require(row.get("session") == session, "sample session mismatch")
        pair = number(row, "pair", integer=True)
        order = number(row, "order", integer=True)
        backend = row.get("backend")
        require(pair < pairs, "sample pair outside requested range")
        require(backend in (*BACKENDS, "raw"), "unknown backend")
        if backend == "raw":
            require(not aa and pair == 0 and order == 2, "raw helper in wrong position")
        else:
            require(order in (0, 1), "paired sample order must be 0 or 1")
            require(not aa or backend == "reference", "A/A contains candidate sample")
        identity = (case_id, pair, order)
        require(identity not in seen, f"duplicate sample {identity}")
        seen.add(identity)
        operations = number(row, "operations", positive=True, integer=True)
        expected_operations = case["keys"] if case["temperature"] == "cold" else (100 if quick else case["loops"])
        require(operations == expected_operations, "operation count mismatch")
        elapsed = number(row, "elapsed_ns", positive=True)
        ns = number(row, "ns_per_operation", positive=True)
        require(math.isclose(ns, elapsed / operations, rel_tol=1e-8), "micro rate inconsistent with elapsed/operations")
        number(row, "thread_cpu_ns")
        number(row, "http_elapsed_ns", positive=True)
        require(row.get("http_status") == 200, "micro HTTP error/missing status")
        expected_checksum = observation(case["payload"], case["mutate"]) * operations
        require(row.get("checksum") == expected_checksum == row.get("expected_checksum"), "micro checksum mismatch")
        require(row.get("mutation_isolated") is True, "mutation isolation was not verified")
        pins = number(row, "graph_pins", integer=True)
        require(mode == "classic" or pins == 0, "micro leaked graph pins")
        for field in memory_fields:
            number(row, field)
        (raw if backend == "raw" else grouped)[case_id].append(row)
    for case_id in expected_cases:
        require(len(grouped[case_id]) == pairs * 2, f"missing paired samples for case {case_id}")
        require(len(raw[case_id]) == (0 if aa else 1), f"missing/duplicate raw sample for case {case_id}")
        for pair in range(pairs):
            pair_rows = [row for row in grouped[case_id] if row["pair"] == pair]
            require(len(pair_rows) == 2, "incomplete pair")
            require(sorted(row["backend"] for row in pair_rows) == (["reference", "reference"] if aa else sorted(BACKENDS)), "wrong paired backends")
    return meta, [row for row in rows[1:] if row["backend"] != "raw"], [row for row in rows[1:] if row["backend"] == "raw"]


def validate_http(rows, *, kind, backend, workers, operations, mixed):
    iterator = iter(rows)
    meta = next(iterator, None)
    require(isinstance(meta, dict) and meta.get("type") == "metadata", "HTTP metadata must be first")
    for key, expected in (("kind", kind), ("backend", backend), ("workers", workers), ("operations_per_http", operations), ("mixed", mixed), ("ttl", 3600 if kind == "array" else 0), ("key_count", 32), ("closed_loop", True), ("clients", 2 * workers)):
        require(meta.get(key) == expected, f"HTTP metadata mismatch: {key}")
    summary = next(iterator, None)
    require(isinstance(summary, dict) and summary.get("type") == "summary", "HTTP summary must be second")
    require(summary.get("failures") == 0 and summary.get("validation_errors") == 0, "HTTP failures")
    require(summary.get("http_errors") == 0, "HTTP transport/status errors not verified")
    status = summary.get("status", {})
    require(status.get("pins") == 0, "HTTP leaked graph pins")
    require(status.get("http_status") == 200, "HTTP status request failed")
    for field in ("shared_used", "reference_persistent_bytes", "php_heap"):
        number(status, field)
    elapsed = number(summary, "elapsed_ns", positive=True)
    number(summary, "offered_window_ns", positive=True)
    totals = dict(requests=0, reads=0, writes=0, batch_ns=0, thread_cpu_ns=0)
    sequences = set()
    latencies = []
    for row in iterator:
        require(row.get("type") == "sample", "unexpected HTTP row type")
        require(row.get("http_status") == 200, "HTTP sample status error")
        seq = number(row, "sequence", integer=True)
        require(seq not in sequences, "duplicate HTTP sequence")
        sequences.add(seq)
        reads = number(row, "reads", integer=True)
        writes = number(row, "writes", integer=True)
        require(reads + writes == operations, "HTTP operation count mismatch")
        start = seq * operations
        expected_writes = sum(1 for i in range(operations) if mixed and (start + i) % 100 == 0)
        require(writes == expected_writes, "HTTP write schedule mismatch")
        require(row.get("checksum") == reads * 307 and row.get("failures") == 0, "HTTP sample checksum/failure")
        totals["requests"] += 1
        for field in ("reads", "writes", "batch_ns", "thread_cpu_ns"):
            totals[field] += number(row, field)
        latencies.append(number(row, "http_latency_ns", positive=True))
    require(bool(sequences) and sequences == set(range(len(sequences))), "missing HTTP sequence")
    for field, expected in totals.items():
        require(summary.get(field) == expected, f"HTTP summary total mismatch: {field}")
    for field, count in (("requests_per_second", totals["requests"]), ("operations_per_second", totals["reads"] + totals["writes"])):
        require(math.isclose(number(summary, field, positive=True), count * 1e9 / elapsed, rel_tol=1e-8), f"HTTP summary rate mismatch: {field}")
    latencies.sort()
    for p in (50, 95, 99):
        require(summary.get(f"http_p{p}_ns") == latencies[(len(latencies) - 1) * p // 100], "HTTP latency percentile mismatch")
    return meta, summary


def percentile(values, fraction):
    ordered = sorted(values)
    point = (len(ordered) - 1) * fraction
    low = int(point)
    return ordered[low] + (ordered[min(low + 1, len(ordered) - 1)] - ordered[low]) * (point - low)


def distribution(values):
    return dict(median=statistics.median(values), p25=percentile(values, .25), p75=percentile(values, .75), min=min(values), max=max(values), n=len(values))


def ratio_interval(values):
    if len(values) < 2:
        return None
    rng = random.Random(1701)
    samples = [statistics.median(rng.choices(values, k=len(values))) for _ in range(2000)]
    return [percentile(samples, .025), percentile(samples, .975)]


def summarize_micro(rows, aa=False):
    groups = defaultdict(list)
    for row in rows:
        groups[case_key(row)].append(row)
    result = []
    metrics = ("ns_per_operation", "cpu_ns_per_operation", "http_elapsed_ns", "php_heap_after_reset", "php_heap_after_seed", "php_heap_after_warm", "php_heap_after", "php_peak_heap", "reference_persistent_after_seed", "shared_used", "graph_pins")
    for key, samples in sorted(groups.items()):
        pairs = defaultdict(list)
        for row in samples:
            pairs[(row["session"], row["pair"])].append(row)
        ratios = []
        backend_rows = defaultdict(list)
        session_ratios = defaultdict(list)
        for (session, _), pair in sorted(pairs.items()):
            require(len(pair) == 2, "summary received incomplete pair")
            pair.sort(key=lambda row: row["order"])
            a, b = pair if aa else (next(row for row in pair if row["backend"] == "reference"), next(row for row in pair if row["backend"] == "user_cache"))
            ratio = b["ns_per_operation"] / a["ns_per_operation"]
            ratios.append(ratio)
            session_ratios[session].append(ratio)
            for label, row in (("reference_first" if aa else "reference", a), ("reference_second" if aa else "user_cache", b)):
                backend_rows[label].append(dict(row, cpu_ns_per_operation=row["thread_cpu_ns"] / row["operations"]))
        result.append(dict(zip(("payload", "keys", "temperature", "mutate", "ttl"), key), pairs=len(pairs), paired_ratio=distribution(ratios), median_ratio_ci95=ratio_interval(ratios), session_ratios={str(k): distribution(v) for k, v in session_ratios.items()}, backends={label: {metric: distribution([row[metric] for row in samples]) for metric in metrics} for label, samples in backend_rows.items()}))
    return result


def summarize_raw(rows):
    groups = defaultdict(list)
    for row in rows:
        groups[case_key(row)].append(row)
    return [dict(zip(("payload", "keys", "temperature", "mutate", "ttl"), key), ns_per_operation=distribution([r["ns_per_operation"] for r in samples]), cpu_ns_per_operation=distribution([r["thread_cpu_ns"] / r["operations"] for r in samples])) for key, samples in sorted(groups.items())]


def summarize_http(executions):
    groups = defaultdict(list)
    for execution in executions:
        if execution.get("stage") == "http" and execution.get("validated"):
            meta = execution["metadata"]
            groups[(meta["kind"], meta["workers"], meta["operations_per_http"], meta["mixed"], meta["ttl"])].append(execution)
    output = []
    for key, samples in sorted(groups.items()):
        by_backend = defaultdict(list)
        paired = defaultdict(dict)
        for sample in samples:
            summary = dict(sample["summary"])
            operations = summary["reads"] + summary["writes"]
            summary["cpu_ns_per_operation"] = summary["thread_cpu_ns"] / operations
            summary["batch_ns_per_operation"] = summary["batch_ns"] / operations
            summary.update({"status_" + k: v for k, v in summary["status"].items() if k in ("shared_used", "reference_persistent_bytes", "php_heap")})
            backend = sample["metadata"]["backend"]
            by_backend[backend].append(summary)
            paired[sample["round"]][backend] = summary
        require(all(set(pair) == set(BACKENDS) for pair in paired.values()), "incomplete HTTP backend pair")
        metrics = ("requests_per_second", "operations_per_second", "http_p50_ns", "http_p95_ns", "http_p99_ns", "cpu_ns_per_operation", "batch_ns_per_operation", "status_shared_used", "status_reference_persistent_bytes", "status_php_heap")
        ratios = [pair["user_cache"]["operations_per_second"] / pair["reference"]["operations_per_second"] for pair in paired.values()]
        output.append(dict(zip(("kind", "workers", "operations", "mixed", "ttl"), key), rounds=len(paired), paired_throughput_ratio=distribution(ratios), median_ratio_ci95=ratio_interval(ratios), backends={backend: {metric: distribution([row[metric] for row in values]) for metric in metrics} for backend, values in by_backend.items()}))
    return output


def sha256(path):
    digest = hashlib.sha256()
    with open(path, "rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def verify_build(build_dir, manifest):
    """Verify the snapshot actually executed, including --no-build invocations."""
    require(manifest.get("schema") == 1, "unsupported build manifest")
    artifacts = manifest.get("artifacts")
    require(isinstance(artifacts, dict) and bool(artifacts), "build manifest has no artifacts")
    required = {"bin/frankenphp-bench", "bin/frankenphp-scale", "install/lib/libphp.so"}
    fixtures = build_dir / "fixtures"
    require(fixtures.is_dir(), "missing built fixtures")
    required.update(str(path.relative_to(build_dir)) for path in fixtures.rglob("*") if path.is_file())
    required.update("fixtures/" + name for name in ("bench.php", "worker.php", "scale.php", "scale-scalar.php"))
    require(required <= set(artifacts), "build manifest omits required binaries/library/fixtures")
    for relative, expected in artifacts.items():
        path = (build_dir / relative).resolve()
        require(path.is_relative_to(build_dir) and path.is_file(), f"missing/outside-build artifact: {relative}")
        require(sha256(path) == expected, f"build artifact changed: {relative}; rebuild before measuring")
    return fixtures


def save_json(path, data):
    temporary = path.with_suffix(path.suffix + ".tmp")
    temporary.write_text(json.dumps(data, indent=2, allow_nan=False) + "\n", encoding="utf-8")
    temporary.replace(path)


@contextmanager
def benchmark_lock():
    if os.environ.get("UC_BENCH_LOCK_HELD") == "1":
        yield
        return
    lock = Path(os.environ.get("UC_BENCH_LOCK_DIR", ROOT / "runtime" / "benchmark.lock"))
    lock.parent.mkdir(parents=True, exist_ok=True)
    try:
        lock.mkdir()
    except FileExistsError as exc:
        raise RuntimeError(f"benchmark lock is already held: {lock}") from exc
    (lock / "pid").write_text(str(os.getpid()) + "\n")
    os.environ.update(UC_BENCH_LOCK_HELD="1", UC_BENCH_LOCK_DIR=str(lock))
    try:
        yield
    finally:
        (lock / "pid").unlink(missing_ok=True)
        lock.rmdir()
        os.environ.pop("UC_BENCH_LOCK_HELD", None)
        os.environ.pop("UC_BENCH_LOCK_DIR", None)


def csv_choices(value, allowed):
    items = value.split(",")
    if not items or len(set(items)) != len(items) or any(item not in allowed for item in items):
        raise argparse.ArgumentTypeError("choose distinct comma-separated values from " + ",".join(allowed))
    return items


def parse_args(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--build-dir", type=Path, default=ROOT / "runtime" / "frankenphp")
    parser.add_argument("--output-dir", type=Path)
    parser.add_argument("--output", type=Path, default=ROOT / "BENCH_RESULT_PERSISTENCE.html")
    build = parser.add_mutually_exclusive_group()
    build.add_argument("--build", dest="build", action="store_true", default=True)
    build.add_argument("--no-build", dest="build", action="store_false")
    parser.add_argument("--php-src", type=Path, default=ROOT.parent)
    parser.add_argument("--frankenphp-src", type=Path)
    parser.add_argument("--jobs", type=int, default=2)
    parser.add_argument("--quick", action="store_true", help="correctness smoke only, never performance evidence")
    parser.add_argument("--pairs", type=int, help="micro pairs per case (default 15; quick 1)")
    parser.add_argument("--rounds", type=int, help="HTTP backend pairs per case (default 5; quick 1)")
    parser.add_argument("--sessions", type=int, help="independent micro A/B hosts (default 2; quick 1)")
    parser.add_argument("--seconds", type=float, help="HTTP offered window per backend (default 5; quick .15)")
    parser.add_argument("--cpus", help="taskset CPU list; applies to measured hosts")
    parser.add_argument("--seed", type=int, default=1701)
    parser.add_argument("--stage", choices=("all", "micro", "aa", "http"), default="all")
    parser.add_argument("--mode", choices=("worker", "classic"), default="worker", help="micro/A/A PHP mode; HTTP scaling always uses workers")
    parser.add_argument("--payloads", type=lambda x: csv_choices(x, PAYLOADS), default=list(PAYLOADS))
    parser.add_argument("--workers", type=lambda x: csv_choices(x, ("1", "2", "4")), default=["1", "2", "4"])
    parser.add_argument("--operations", type=lambda x: csv_choices(x, ("1", "100")), default=["1", "100"])
    parser.add_argument("--kinds", type=lambda x: csv_choices(x, ("array", "scalar")), default=["array", "scalar"])
    parser.add_argument("--modes", type=lambda x: csv_choices(x, ("read", "mixed")), default=["read", "mixed"])
    args = parser.parse_args(argv)
    for key, default, quick_default in (("pairs", 15, 1), ("rounds", 5, 1), ("sessions", 2, 1), ("seconds", 5, .15)):
        if getattr(args, key) is None:
            setattr(args, key, quick_default if args.quick else default)
    if min(args.pairs, args.rounds, args.sessions, args.jobs, args.seconds) <= 0 or not math.isfinite(args.seconds):
        parser.error("pairs, rounds, sessions, jobs and seconds must be positive")
    args.build_dir = args.build_dir.resolve()
    args.output = args.output.resolve()
    args.output_dir = (args.output_dir or ROOT / "results" / ("persistence-" + datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%S.%fZ"))).resolve()
    return args


def run(args):
    output = args.output_dir
    output.mkdir(parents=True, exist_ok=True)
    require(not (output / "result.json").exists() and not list(output.glob("*.jsonl*")), f"refusing to overwrite previous samples: {output}")
    result_path = output / "result.json"
    report = dict(schema_version=1, status="running", created_at=datetime.now(timezone.utc).isoformat(), smoke_only=args.quick, upstream_helper=UPSTREAM,
                  configuration={key: str(value) if isinstance(value, Path) else value for key, value in vars(args).items()},
                  environment=dict(platform=platform.platform(), machine=platform.machine(), python=sys.version, cpu_count=os.cpu_count(), affinity=sorted(os.sched_getaffinity(0)) if hasattr(os, "sched_getaffinity") else None),
                  executions=[], micro=[], aa=[], raw_helper=[], http=[], errors=[])
    micro_samples, aa_samples, raw_samples = [], [], []
    fixtures = args.build_dir / "fixtures"
    from persistence_artifacts import archive_source_inputs, capture_producer_inventory
    producer_files = {name: ROOT / name for name in (
        "scripts/benchmark_persistence.py", "scripts/render_persistence_report.py",
        "scripts/persistence_artifacts.py", "scripts/build_frankenphp.sh", "benchmark.sh")}
    # Capture before a potentially long build, then require these same bytes
    # when publishing the archive. A later producer edit must not be relabeled.
    producer_inventory = capture_producer_inventory(producer_files)
    save_json(result_path, report)

    def execute(label, stage, binary, extra):
        command = (["taskset", "-c", args.cpus] if args.cpus else []) + [str(binary), "-root", str(fixtures)] + extra
        entry = dict(label=label, stage=stage, command=command, validated=False, raw_file=label + ".jsonl.gz", stderr_file=label + ".stderr.log")
        report["executions"].append(entry)
        save_json(result_path, report)
        print(json.dumps({"starting": label, "command": command}), flush=True)
        raw_path = output / (label + ".jsonl")
        env = dict(os.environ, UC_LIFECYCLE_OPT_IN="1")
        library_path = args.build_dir / "install" / "lib"
        env["LD_LIBRARY_PATH"] = str(library_path) + (":" + env["LD_LIBRARY_PATH"] if env.get("LD_LIBRARY_PATH") else "")
        start = time.monotonic()
        try:
            with raw_path.open("wb") as stdout, (output / entry["stderr_file"]).open("wb") as stderr:
                process = subprocess.run(command, env=env, stdout=stdout, stderr=stderr, timeout=max(1800, args.seconds + 120))
            entry["returncode"] = process.returncode
        finally:
            entry["wall_seconds"] = time.monotonic() - start
            if raw_path.exists():
                with raw_path.open("rb") as source, gzip.open(output / entry["raw_file"], "wb") as target:
                    shutil.copyfileobj(source, target)
                raw_path.unlink()
        require(process.returncode == 0, f"{label}: host exited {process.returncode}; see {entry['stderr_file']}")
        return entry

    try:
        if args.build:
            command = [str(ROOT / "scripts" / "build_frankenphp.sh"), "--php-src", str(args.php_src.resolve()), "--work-dir", str(args.build_dir), "--jobs", str(args.jobs)]
            if args.frankenphp_src:
                command.extend(["--frankenphp-src", str(args.frankenphp_src.resolve())])
            report["build_command"] = command
            print(json.dumps({"building": command}), flush=True)
            with (output / "build.log").open("wb") as log:
                subprocess.run(command, stdout=log, stderr=subprocess.STDOUT, check=True)
        manifest = args.build_dir / "manifest.json"
        require(manifest.is_file(), f"missing build manifest: {manifest}")
        report["build_manifest"] = json.loads(manifest.read_text())
        fixtures = verify_build(args.build_dir, report["build_manifest"])
        report["build_manifest_sha256"] = sha256(manifest)
        shutil.copyfile(manifest, output / "build-manifest.json")
        binaries = {name: args.build_dir / "bin" / ("frankenphp-" + name) for name in ("bench", "scale")}
        report["binaries"] = {name: dict(path=str(path), sha256=sha256(path)) for name, path in binaries.items()}
        libraries = sorted((args.build_dir / "install" / "lib").glob("libphp*"))
        report["php_libraries"] = {str(path): sha256(path) for path in libraries if path.is_file()}
        report["fixture_sha256"] = {str(path.relative_to(fixtures)): sha256(path) for path in sorted(fixtures.rglob("*")) if path.is_file()}
        report["source_archive"] = archive_source_inputs(args.build_dir, output,
            assets_dir=ROOT / "scripts" / "frankenphp", producer_files=producer_files,
            expected_producers=producer_inventory,
            expected_build_manifest_sha256=report["build_manifest_sha256"])
        save_json(result_path, report)
        if args.stage in ("all", "aa", "micro"):
            sessions = ([("aa", 1)] if args.stage in ("all", "aa") else []) + ([("micro", session) for session in range(1, args.sessions + 1)] if args.stage in ("all", "micro") else [])
            filters = [None] if args.payloads == list(PAYLOADS) else args.payloads
            for stage, session in sessions:
                for payload in filters:
                    aa = stage == "aa"
                    label = f"{stage}-s{session}" + ("-" + payload if payload else "")
                    extra = ["-mode", args.mode, "-session", str(session), "-pairs", str(args.pairs), "-seed", str(args.seed + session * 7919)]
                    if aa:
                        extra.append("-aa")
                    if args.quick:
                        extra.append("-quick")
                    if payload:
                        extra.extend(["-case", payload])
                    entry = execute(label, stage, binaries["bench"], extra)
                    meta, paired, raw = validate_micro(read_jsonl(output / entry["raw_file"]), expected_cases=cases([payload] if payload else PAYLOADS), pairs=args.pairs, session=session, aa=aa, quick=args.quick, mode=args.mode)
                    entry.update(metadata=meta, validated=True, sample_count=len(paired) + len(raw))
                    (aa_samples if aa else micro_samples).extend(paired)
                    raw_samples.extend(raw)
                    save_json(result_path, report)
        if args.stage in ("all", "http"):
            rng = random.Random(args.seed)
            for kind in args.kinds:
                for workers in map(int, args.workers):
                    for operations in map(int, args.operations):
                        for mode in args.modes:
                            # Flip within each case so a backend never gets every first run.
                            first = list(BACKENDS)
                            rng.shuffle(first)
                            for round_id in range(args.rounds):
                                order = first if round_id % 2 == 0 else first[::-1]
                                for backend in order:
                                    label = f"http-{kind}-w{workers}-o{operations}-{mode}-r{round_id}-{backend}"
                                    extra = ["-kind", kind, "-backend", backend, "-workers", str(workers), "-operations", str(operations), "-duration", f"{args.seconds}s"]
                                    if mode == "mixed":
                                        extra.append("-mixed")
                                    entry = execute(label, "http", binaries["scale"], extra)
                                    entry["round"] = round_id
                                    meta, summary = validate_http(read_jsonl(output / entry["raw_file"]), kind=kind, backend=backend, workers=workers, operations=operations, mixed=mode == "mixed")
                                    entry.update(metadata=meta, summary=summary, validated=True)
                                    save_json(result_path, report)
        report.update(micro=summarize_micro(micro_samples), aa=summarize_micro(aa_samples, aa=True), raw_helper=summarize_raw(raw_samples), http=summarize_http(report["executions"]), status="complete")
    except (Exception, KeyboardInterrupt) as exc:
        report["status"] = "failed"
        report["errors"].append(f"{type(exc).__name__}: {exc}")
        report.update(micro=summarize_micro(micro_samples), aa=summarize_micro(aa_samples, aa=True), raw_helper=summarize_raw(raw_samples))
        print(report["errors"][-1], file=sys.stderr)
    report["finished_at"] = datetime.now(timezone.utc).isoformat()
    save_json(result_path, report)
    from render_persistence_report import render_file
    render_file(result_path, args.output)
    print(json.dumps({"status": report["status"], "result": str(result_path), "html": str(args.output)}), flush=True)
    return 0 if report["status"] == "complete" else 1


def main(argv=None):
    args = parse_args(argv)

    def terminate(signum, frame):
        raise KeyboardInterrupt("terminated by SIGTERM")

    previous_handler = signal.signal(signal.SIGTERM, terminate)
    try:
        with benchmark_lock():
            return run(args)
    except (Exception, KeyboardInterrupt) as exc:
        print(f"persistence benchmark: {exc}", file=sys.stderr)
        return 1
    finally:
        signal.signal(signal.SIGTERM, previous_handler)


if __name__ == "__main__":
    raise SystemExit(main())
