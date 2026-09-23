#!/usr/bin/env python3
"""Run the standalone user_cache microbenchmarks, optionally comparing two builds."""
import argparse
from contextlib import contextmanager
from dataclasses import asdict, dataclass
import fnmatch
import hashlib
import json
import math
import os
from pathlib import Path
import platform
import shutil
import signal
import statistics
import subprocess
import sys
import time

from render import render_report


DIRECTORY = Path(__file__).resolve().parent
HARNESS_ROOT = DIRECTORY.parent.parent
CORE_CASES = [
    'fetch_scalar_1', 'fetch_scalar_8', 'fetch_scalar_32', 'fetch_scalar_128', 'fetch_scalar_256',
    'fetch_string_300', 'fetch_string_300_128', 'fetch_string_300_2048', 'fetch_string_300_32768',
    'fetch_string_8192', 'fetch_packed_array', 'fetch_hash_array', 'fetch_object',
    'fetch_dynamic_object', 'fetch_destructor_object', 'fetch_magic', 'fetch_spl',
    'fetch_miss', 'fetch_long_key', 'has_scalar', 'store_scalar', 'store_string_300', 'store_delete',
    'fetch_multiple_32', 'fetch_multiple_128', 'store_multiple_32', 'lock_unlock',
    'remember_hit', 'remember_miss', 'status_empty', 'status_fragmented', 'pool_status',
    'memory_pools', 'memory_strings', 'memory_objects', 'memory_large_objects',
    'memory_fixed_arrays', 'memory_object_arrays',
]
POOL_MODES = ['cold', 'warm', 'warm-create', 'end-to-end', 'create-only',
              'existing-miss', 'warm-existing']
SUITES = ['core', 'review', 'status', 'lru', 'writers', 'pools', 'snapshot']


@dataclass(frozen=True)
class Case:
    suite: str
    name: str
    worker: str
    arguments: tuple = ()
    metric: str = 'ns_per_operation'
    unit: str = 'ns/op'
    cpus: int = 1
    operations_per_iteration: int = 1
    entries_hint: int = 0
    shm_mb: int = 128
    fixed_iterations: int | None = None
    calibration_iterations: int = 1000
    minimum_iterations: int = 100
    maximum_iterations: int = 2000000
    note: str = ''

    @property
    def id(self):
        return f'{self.suite}/{self.name}'


def catalog():
    cases = []
    for name in CORE_CASES:
        cases.append(Case('core', name, 'core.php', (name, '{iterations}'),
                          fixed_iterations=1 if name.startswith('memory_') else None,
                          calibration_iterations=10000, minimum_iterations=1000,
                          maximum_iterations=10000000,
                          note='Fixed workload size; no timed-loop warmup.' if name.startswith('memory_') else ''))
    cases.append(Case('review', 'pipeline', 'review.php', metric='store_after_ns_per_operation',
                      shm_mb=64, fixed_iterations=1,
                      note='Store timings before/after 20,000 lock cycles; 10,000 store/delete cycles; one 2,000-entry bulk store.'))
    for keys in [0, 128, 4096]:
        for mode in ['first', 'warm', 'same-write', 'other-write', 'same-replace', 'other-replace',
                     'same-churn', 'other-churn']:
            if keys == 0 and mode.startswith('same-'):
                continue
            cases.append(Case('status', f'{mode}_{keys}', 'status.php',
                              (mode, '{iterations}', str(keys), '16384'),
                              metric='ns_per_iteration', unit='ns/iteration', entries_hint=131072,
                              fixed_iterations=1 if mode == 'first' else None,
                              note='16,384 keys in another pool; writes are included where specified.'))
    for kind in ['scalar', 'string', 'array']:
        for keys in [1, 64, 128, 2048]:
            cases.append(Case('lru', f'{kind}_{keys}', 'lru.php',
                              (kind, str(keys), '{iterations}'), entries_hint=131072))
    for workers in [1, 2, 4, 8]:
        for mode in ['scalar', 'increment', 'string', 'array', 'mixed']:
            for layout in ['disjoint', 'same']:
                cases.append(Case('writers', f'{mode}_{layout}_{workers}', 'writers.php',
                                  (mode, str(workers), '{iterations}', layout), cpus=workers,
                                  operations_per_iteration=workers, entries_hint=131072,
                                  calibration_iterations=20000,
                                  note='Fork/setup excluded; percentile samples are averages over batches of up to 256 operations.'))
        cases.append(Case('writers', f'insert_disjoint_{workers}', 'writers.php',
                          ('insert', str(workers), '{iterations}', 'disjoint'), cpus=workers,
                          operations_per_iteration=workers, entries_hint=131072,
                          maximum_iterations=8192,
                          note='At most 8,192 new keys per worker; batch-average latency samples.'))
    cases.append(Case('pools', 'original', 'core.php', ('memory_pools', '1'), fixed_iterations=1,
                      note='Original fixed 2,000-pool workload, also listed under core/memory_pools.'))
    for mode in POOL_MODES:
        cases.append(Case('pools', mode, 'pools.php', (mode, '2000'), fixed_iterations=1,
                          note='2,000 pools; warm controls also warm PHP allocations and registry capacity.'))
    for count in [0, 128, 4096]:
        cases.append(Case('snapshot', f'keys_{count}', 'snapshot-memory.php', (str(count),),
                          metric='snapshot_retained_bytes', unit='bytes', fixed_iterations=1,
                          note='PHP heap retained after releasing status; includes snapshot-table overhead, not RSS.'))
    return cases


def split_options(values):
    return [part for value in (values or []) for part in value.split(',') if part]


def select_cases(cases, suites=None, patterns=None):
    suites = split_options(suites)
    patterns = split_options(patterns)
    unknown = set(suites) - set(SUITES)
    if unknown:
        raise ValueError('Unknown suites: ' + ', '.join(sorted(unknown)))
    selected = [case for case in cases if not suites or case.suite in suites]
    if patterns:
        for pattern in patterns:
            matches = [case for case in selected if fnmatch.fnmatchcase(case.id, pattern)
                       or ('/' not in pattern and fnmatch.fnmatchcase(case.name, pattern))]
            if not matches:
                raise ValueError('No selected case matches: ' + pattern)
        selected = [case for case in selected if any(
            fnmatch.fnmatchcase(case.id, pattern)
            or ('/' not in pattern and fnmatch.fnmatchcase(case.name, pattern)) for pattern in patterns)]
    return selected


def digest(path):
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()


def resolve_binary(value):
    resolved = shutil.which(value) if '/' not in value else value
    if not resolved or not Path(resolved).is_file() or not os.access(resolved, os.X_OK):
        raise ValueError('PHP binary is not executable: ' + value)
    return str(Path(resolved).resolve())


@contextmanager
def benchmark_lock():
    """Use the same mkdir/PID contract as the harness shell entry points."""
    lock = Path(os.environ.get('UC_BENCH_LOCK_DIR', str(HARNESS_ROOT / 'runtime/benchmark.lock')))
    if os.environ.get('UC_BENCH_LOCK_HELD') == '1':
        yield {'path': str(lock), 'inherited': True}
        return
    lock.parent.mkdir(parents=True, exist_ok=True)
    try:
        lock.mkdir()
    except FileExistsError:
        pid = (lock / 'pid').read_text().strip() if (lock / 'pid').is_file() else 'unknown'
        raise RuntimeError(f'Benchmark lock is already held by PID {pid}: {lock}') from None
    try:
        (lock / 'pid').write_text(str(os.getpid()) + '\n')
        yield {'path': str(lock), 'inherited': False}
    finally:
        (lock / 'pid').unlink(missing_ok=True)
        lock.rmdir()


class WorkerFailure(RuntimeError):
    pass


def execute(command, cpus, timeout):
    previous = os.sched_getaffinity(0) if hasattr(os, 'sched_getaffinity') else None
    process = None
    started = time.monotonic_ns()
    try:
        if previous is not None:
            os.sched_setaffinity(0, set(cpus))
        process = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                   start_new_session=True)
    finally:
        if previous is not None:
            os.sched_setaffinity(0, previous)
    try:
        stdout, stderr = process.communicate(timeout=timeout)
    except (subprocess.TimeoutExpired, KeyboardInterrupt, SystemExit):
        # Forked benchmark workers share the session: stop them as well as PHP.
        if os.name == 'posix':
            os.killpg(process.pid, signal.SIGKILL)
        else:
            process.kill()
        process.communicate()
        raise
    return {
        'command': command, 'cpus': cpus, 'returncode': process.returncode,
        'stdout': stdout.decode('utf-8', errors='replace'),
        'stderr': stderr.decode('utf-8', errors='replace'),
        'process_elapsed_ns': time.monotonic_ns() - started,
    }


def read_result(record):
    if record['returncode'] != 0:
        raise WorkerFailure(f'PHP exited {record["returncode"]}: {record["stderr"] or record["stdout"]}')
    if record['stderr'].strip():
        raise WorkerFailure('PHP wrote to stderr: ' + record['stderr'])
    try:
        row = json.loads(record['stdout'])
    except (ValueError, TypeError) as error:
        raise WorkerFailure('Invalid JSON from PHP: ' + record['stdout'][:1000]) from error
    if not isinstance(row, dict) or row.get('errors', 0):
        raise WorkerFailure('Worker reported invalid results: ' + repr(row))
    return row


def numeric_summary(rows):
    def flatten(row, prefix=''):
        values = {}
        for key, value in row.items():
            name = prefix + key
            if isinstance(value, dict):
                values.update(flatten(value, name + '.'))
            else:
                values[name] = value
        return values

    rows = [flatten(row) for row in rows]
    keys = {key for row in rows for key, value in row.items()
            if isinstance(value, (int, float)) and not isinstance(value, bool)}
    result = {}
    for key in sorted(keys):
        values = [row[key] for row in rows if isinstance(row.get(key), (int, float))
                  and not isinstance(row[key], bool)]
        if len(values) == len(rows) and all(math.isfinite(value) for value in values):
            result[key] = {'median': statistics.median(values), 'min': min(values), 'max': max(values)}
    return result


def save_report(report, output):
    temporary = output / 'micro.json.tmp'
    temporary.write_text(json.dumps(report, indent=2, ensure_ascii=False, allow_nan=False) + '\n')
    temporary.replace(output / 'micro.json')
    (output / 'micro.html').write_text(render_report(report), encoding='utf-8')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default=os.environ.get('PHP_CLI_BIN', str(HARNESS_ROOT.parent / 'sapi/cli/php')),
                        help='Current PHP CLI binary; default PHP_CLI_BIN or ../sapi/cli/php')
    parser.add_argument('--before', help='Optional baseline PHP CLI binary')
    parser.add_argument('--output-dir', default=str(HARNESS_ROOT / 'results' /
                        (time.strftime('micro-%Y%m%dT%H%M%SZ', time.gmtime()) + '-' + str(os.getpid()))))
    parser.add_argument('--output', help='Also copy the standalone HTML report to this path')
    parser.add_argument('--quick', action='store_true', help='Smoke profile: short loops, default one round')
    parser.add_argument('--rounds', type=int, help='Default: 9, or 1 with --quick')
    parser.add_argument('--target-ms', type=float, help='Target calibrated duration; default 150, or 5 with --quick')
    parser.add_argument('--suites', nargs='+', help='Suite names; space or comma separated')
    parser.add_argument('--cases', nargs='+', help='Case IDs or glob patterns; space or comma separated')
    parser.add_argument('--list', action='store_true', help='List selected case IDs without running PHP')
    parser.add_argument('--cpu', type=int, help='First CPU to use (default: first allowed CPU)')
    parser.add_argument('--timeout', type=float, default=120, help='Timeout per PHP process in seconds')
    args = parser.parse_args()
    try:
        cases = select_cases(catalog(), args.suites, args.cases)
    except ValueError as error:
        parser.error(str(error))
    if args.list:
        for case in cases:
            print(f'{case.id}\t{case.unit}\t{case.cpus} CPU\t{case.note}')
        return 0
    rounds = args.rounds if args.rounds is not None else (1 if args.quick else 9)
    target_ms = args.target_ms if args.target_ms is not None else (5 if args.quick else 150)
    if rounds < 1 or not math.isfinite(target_ms) or target_ms <= 0 or not math.isfinite(args.timeout) or args.timeout <= 0:
        parser.error('rounds, target-ms and timeout must be positive')
    available = sorted(os.sched_getaffinity(0)) if hasattr(os, 'sched_getaffinity') else list(range(os.cpu_count() or 1))
    if args.cpu is not None:
        if args.cpu not in available:
            parser.error('--cpu is outside the available affinity mask')
        available = [args.cpu] + [cpu for cpu in available if cpu != args.cpu]
    try:
        binaries = {'current': resolve_binary(args.php)} if not args.before else {
            'before': resolve_binary(args.before), 'after': resolve_binary(args.php)}
    except ValueError as error:
        parser.error(str(error))
    output = Path(args.output_dir).resolve()
    published_html = Path(args.output).resolve() if args.output else None
    output.mkdir(parents=True, exist_ok=True)
    report = {
        'schema_version': 1, 'kind': 'user_cache_microbench', 'status': 'running',
        'quick': args.quick, 'rounds': rounds, 'target_ms': target_ms,
        'started_at': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()),
        'platform': platform.platform(), 'machine': platform.machine(), 'python': sys.version,
        'available_cpus': available, 'affinity_supported': hasattr(os, 'sched_setaffinity'),
        'published_html': str(published_html) if published_html else None,
        'binaries': binaries, 'binary_sha256': {label: digest(path) for label, path in binaries.items()},
        'runner_sha256': digest(__file__), 'renderer_sha256': digest(DIRECTORY / 'render.py'),
        'manifest_sha256': digest(DIRECTORY / 'coverage.json'),
        'worker_sha256': {name: digest(DIRECTORY / 'workers' / name) for name in sorted({c.worker for c in cases})},
        'selected_cases': [case.id for case in cases], 'cases': {}, 'php': {},
        'notes': [
            'Fresh PHP process for every sample. Paired builds alternate order each round.',
            'Calibration uses the baseline when paired; both builds use the same iteration count.',
            'Core/pools/snapshot use entries_hint=0; status/LRU/writers use 131072.',
            'Quick results are smoke checks, not performance conclusions. Fixed memory workloads retain their original sizes.',
            'Writer percentiles summarize means within batches of up to 256 operations, not individual-operation tails.',
            'Snapshot bytes are PHP retained memory, including management tables, not RSS.',
        ],
    }
    acquired = False

    def interrupted(_signal, _frame):
        raise KeyboardInterrupt

    signal.signal(signal.SIGTERM, interrupted)
    try:
        with benchmark_lock() as lock:
            acquired = True
            report['lock'] = lock
            save_report(report, output)
            for label, binary in binaries.items():
                record = execute([binary, '-n', '-r',
                    'echo json_encode(["version"=>PHP_VERSION,"zts"=>PHP_ZTS,"int_size"=>PHP_INT_SIZE,'
                    '"user_cache"=>extension_loaded("user_cache"),"pcntl"=>function_exists("pcntl_fork"),'
                    '"socket_pair"=>function_exists("stream_socket_pair")], JSON_THROW_ON_ERROR);'],
                    available[:1], args.timeout)
                report['php'][label] = read_result(record)
                if not report['php'][label]['user_cache']:
                    raise WorkerFailure(f'{label} PHP does not have user_cache built in')
            for case in cases:
                entry = {'definition': asdict(case), 'status': 'running', 'samples': {label: [] for label in binaries},
                         'calibration': None, 'summary': {}}
                report['cases'][case.id] = entry
                reason = None
                if case.cpus > len(available):
                    reason = f'Requires {case.cpus} CPUs; affinity mask provides {len(available)}'
                elif case.suite == 'writers' and any(not p['pcntl'] or not p['socket_pair'] for p in report['php'].values()):
                    reason = 'Writers require pcntl_fork and stream_socket_pair in both PHP builds'
                if reason:
                    entry.update(status='skipped', reason=reason)
                    print(f'SKIP {case.id}: {reason}', flush=True)
                    save_report(report, output)
                    continue
                php_options = ['-n', '-d', 'user_cache.enable_cli=1', '-d', f'user_cache.shm_size={case.shm_mb}M',
                               '-d', f'user_cache.entries_hint={case.entries_hint}', '-d', 'memory_limit=512M']

                def sample(label, iterations):
                    parameters = [arg.format(iterations=iterations) for arg in case.arguments]
                    if case.suite == 'review' and args.quick:
                        parameters.append('--quick')
                    command = [binaries[label]] + php_options + [str(DIRECTORY / 'workers' / case.worker)] + parameters
                    record = execute(command, available[:case.cpus], args.timeout)
                    entry['last_process'] = record
                    row = read_result(record)
                    value = row.get(case.metric)
                    if not isinstance(value, (int, float)) or isinstance(value, bool) or not math.isfinite(value):
                        raise WorkerFailure(f'{case.id}: missing or invalid {case.metric}')
                    if case.unit != 'bytes' and value <= 0:
                        raise WorkerFailure(f'{case.id}: non-positive duration')
                    if case.suite == 'pools' and row.get('entries') != 1:
                        raise WorkerFailure(f'{case.id}: unexpected cache entries')
                    return {'result': row, 'command': command, 'cpus': record['cpus'],
                            'process_elapsed_ns': record['process_elapsed_ns']}

                try:
                    iterations = case.fixed_iterations
                    if iterations is None:
                        warm_count = min(case.calibration_iterations, 1000) if args.quick else case.calibration_iterations
                        reference = next(iter(binaries))
                        calibration = sample(reference, warm_count)
                        entry['calibration'] = calibration
                        duration = calibration['result'][case.metric] * case.operations_per_iteration
                        upper = min(case.maximum_iterations, 200000) if args.quick else case.maximum_iterations
                        iterations = max(case.minimum_iterations, min(upper, int(target_ms * 1e6 / duration)))
                    entry['iterations_argument'] = iterations
                    for round_id in range(rounds):
                        labels = list(binaries)
                        if round_id % 2:
                            labels.reverse()
                        for label in labels:
                            row = sample(label, iterations)
                            row['round'] = round_id + 1
                            entry['samples'][label].append(row)
                    entry['summary'] = {label: numeric_summary([row['result'] for row in rows])
                                        for label, rows in entry['samples'].items()}
                    if args.before:
                        before = entry['summary']['before'][case.metric]['median']
                        after = entry['summary']['after'][case.metric]['median']
                        entry['difference_after_before'] = after - before
                        entry['ratio_after_before'] = after / before if before != 0 else None
                    entry['status'] = 'passed'
                    entry.pop('last_process', None)
                    values = ', '.join(f'{label}={summary[case.metric]["median"]:.2f} {case.unit}'
                                       for label, summary in entry['summary'].items())
                    print(f'PASS {case.id}: {values}', flush=True)
                except (WorkerFailure, subprocess.TimeoutExpired) as error:
                    entry.update(status='failed', error=str(error))
                    print(f'FAIL {case.id}: {error}', file=sys.stderr, flush=True)
                save_report(report, output)
            report['binary_sha256_after'] = {label: digest(path) for label, path in binaries.items()}
            if report['binary_sha256_after'] != report['binary_sha256']:
                raise WorkerFailure('A PHP binary changed during measurement')
            report['status'] = 'failed' if any(c['status'] == 'failed' for c in report['cases'].values()) else 'complete'
    except (RuntimeError, OSError, subprocess.TimeoutExpired) as error:
        report.update(status='failed', error=str(error))
        print(str(error), file=sys.stderr)
    except KeyboardInterrupt:
        report.update(status='interrupted', error='Interrupted by user')
    finally:
        report['finished_at'] = time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime())
        save_report(report, output)
        if acquired and published_html and published_html != output / 'micro.html':
            published_html.parent.mkdir(parents=True, exist_ok=True)
            published_html.write_text(render_report(report), encoding='utf-8')
    print('JSON:', output / 'micro.json')
    print('HTML:', output / 'micro.html')
    if acquired and published_html:
        print('Published HTML:', published_html)
    return 0 if report['status'] == 'complete' else 1


if __name__ == '__main__':
    raise SystemExit(main())
