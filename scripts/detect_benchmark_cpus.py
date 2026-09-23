#!/usr/bin/env python3
"""Pick the CPUs benchmarks should be pinned to.

Hosts such as Apple silicon Macs run guest vCPUs on performance and
efficiency cores, and the guest cannot see which is which. This measures a
fixed CPU-bound workload on every allowed CPU, all CPUs at once (the load a
benchmark creates), repeated over several rounds, and prints the CPUs whose
median throughput is within --tolerance of the fastest, as a taskset list.
"""
import argparse
import hashlib
import json
import multiprocessing
import os
import statistics
import sys
import time

WORK = b'\0' * (1 << 16)


def spin(cpu, seconds, start, queue):
    os.sched_setaffinity(0, {cpu})
    while time.monotonic() < start:
        pass
    deadline = time.monotonic() + seconds
    rounds = 0
    while time.monotonic() < deadline:
        hashlib.sha256(WORK).digest()
        rounds += 1
    queue.put((cpu, rounds / seconds))


def measure(cpus, seconds):
    queue = multiprocessing.Queue()
    start = time.monotonic() + 0.2
    workers = [multiprocessing.Process(target=spin, args=(cpu, seconds, start, queue)) for cpu in cpus]
    for worker in workers:
        worker.start()
    results = dict(queue.get() for _ in workers)
    for worker in workers:
        worker.join()
    return results


def taskset_list(cpus):
    parts, run = [], []
    for cpu in sorted(cpus):
        if run and cpu != run[-1] + 1:
            parts.append(run)
            run = []
        run.append(cpu)
    if run:
        parts.append(run)
    return ','.join(str(r[0]) if len(r) == 1 else f'{r[0]}-{r[-1]}' for r in parts)


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--rounds', type=int, default=5)
    parser.add_argument('--seconds', type=float, default=0.3, help='measurement window per round')
    parser.add_argument('--tolerance', type=float, default=0.1, help='allowed slowdown versus the fastest CPU')
    parser.add_argument('--json', action='store_true', help='print per-CPU medians as JSON')
    args = parser.parse_args()
    if not hasattr(os, 'sched_setaffinity'):
        parser.error('CPU affinity is not supported on this platform')

    cpus = sorted(os.sched_getaffinity(0))
    samples = {cpu: [] for cpu in cpus}
    for _ in range(args.rounds):
        for cpu, rate in measure(cpus, args.seconds).items():
            samples[cpu].append(rate)
    medians = {cpu: statistics.median(rates) for cpu, rates in samples.items()}
    fastest = max(medians.values())
    selected = [cpu for cpu, rate in medians.items() if rate >= fastest * (1 - args.tolerance)]

    if args.json:
        print(json.dumps({'medians': medians, 'selected': taskset_list(selected)}, indent=1))
    else:
        for cpu in cpus:
            print(f'cpu{cpu}: {medians[cpu]:.0f} rounds/s ({medians[cpu] / fastest:.2f})', file=sys.stderr)
        print(taskset_list(selected))


if __name__ == '__main__':
    main()
