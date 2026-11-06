#!/usr/bin/env python3
"""Bounded local Nginx/FPM resource experiment; not brute-force protection."""
import json
from pathlib import Path
import subprocess
import time

from fpm_check import docker, main


def usage_delta(before, after, elapsed):
    cpu = after['cpu_usec'] - before['cpu_usec']
    if elapsed <= 0 or cpu < 0:
        raise ValueError('Invalid elapsed time or CPU counter reset')
    return {'cpu_seconds': cpu / 1e6, 'cpu_one_core_percent': cpu / 1e6 / elapsed * 100,
            'memory_growth_bytes': after['memory_bytes'] - before['memory_bytes']}


def sample(container):
    values = docker('exec', container, 'sh', '-c',
                    'cat /sys/fs/cgroup/cpu.stat /sys/fs/cgroup/memory.current /sys/fs/cgroup/memory.peak /sys/fs/cgroup/memory.events').splitlines()
    cpu = int(next(line.split()[1] for line in values if line.startswith('usage_usec ')))
    memory, peak = [int(line) for line in values if line.isdigit()]
    oom = int(next(line.split()[1] for line in values if line.startswith('oom_kill ')))
    return {'cpu_usec': cpu, 'memory_bytes': memory, 'memory_peak_bytes': peak, 'oom_kill': oom}


def experiment(containers, port):
    rounds = []
    for iteration in range(3):
        before = [sample(container) for container in containers]
        started = time.monotonic()
        result = subprocess.run(['python3', str(Path(__file__).resolve().parents[1] / 'benchmarks/http_latency.py'),
                                 f'http://127.0.0.1:{port}/api/status', '--requests', '1000',
                                 '--warmup', '0', '--concurrency', '4', '--runtime-label',
                                 'bounded local Nginx/FPM resource experiment'],
                                capture_output=True, text=True, timeout=120)
        elapsed = time.monotonic() - started
        after = [sample(container) for container in containers]
        benchmark = json.loads(result.stdout)
        measurements = {name: {**usage_delta(start, end, elapsed), **end}
                        for name, start, end in zip(['fpm', 'nginx'], before, after)}
        rounds.append({'round': iteration + 1, 'elapsed_seconds': elapsed,
                       'benchmark': benchmark, 'containers': measurements})
        if result.returncode or any(end['oom_kill'] != start['oom_kill'] for start, end in zip(before, after)):
            print(json.dumps({'status': 'failed', 'rounds': rounds}), flush=True)
            raise RuntimeError('Request failure or container OOM')
    print(json.dumps({'status': 'passed', 'requests': 3000, 'concurrency': 4,
                      'per_container_limits': {'cpus': 1, 'memory_bytes': 268435456, 'pids': 128},
                      'rounds': rounds}), flush=True)


if __name__ == '__main__':
    main(resource_check=experiment)
