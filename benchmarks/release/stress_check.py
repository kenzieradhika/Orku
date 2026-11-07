"""Bounded concurrency-ramp smoke test for an explicitly disposable loopback URL."""
import argparse
from concurrent.futures import ThreadPoolExecutor
import http.client
import json
import math
from pathlib import Path
import platform
import subprocess
import time
import urllib.parse

DEFAULT_LEVELS = [4, 16, 32, 64, 128]
MAX_SECONDS = 60
MAX_REQUESTS = 10000


def validate_levels(levels):
    if (not isinstance(levels, list) or not levels
            or any(type(value) is not int or value not in DEFAULT_LEVELS for value in levels)
            or levels != sorted(set(levels))):
        raise ValueError('levels must be an ordered subset of [4, 16, 32, 64, 128]')
    return levels


def validate_window(duration, requests):
    if type(duration) is not int or not 1 <= duration <= MAX_SECONDS:
        raise ValueError('duration must be 1..60 seconds')
    if type(requests) is not int or not 1 <= requests <= MAX_REQUESTS:
        raise ValueError('requests must be 1..10000')
    return duration, requests


def find_breaking_point(windows):
    bad = [row['error_rate'] > .01 or row['resource_cap'] for row in windows]
    for index in range(len(bad) - 1):
        if bad[index] and bad[index + 1]:
            return {'last_stable': windows[index - 1]['concurrency'] if index else None,
                    'breaking': windows[index]['concurrency']}
    return {'last_stable': windows[-1]['concurrency'] if windows else None, 'breaking': None}


def one_request(host, port, path):
    start = time.perf_counter_ns()
    status = None
    error = None
    try:
        connection = http.client.HTTPConnection(host, port, timeout=3)
        connection.request('GET', path)
        response = connection.getresponse()
        response.read(65537)
        status = response.status
        connection.close()
    except (OSError, http.client.HTTPException) as exc:
        error = type(exc).__name__
    return {'status': status, 'error': error, 'latency_ms': (time.perf_counter_ns() - start) / 1e6}


def run(url, levels, duration, request_cap):
    parsed = urllib.parse.urlsplit(url)
    if (parsed.scheme != 'http' or parsed.hostname not in ('127.0.0.1', 'localhost', '::1')
            or parsed.username is not None or parsed.password is not None or parsed.query or parsed.fragment):
        raise ValueError('only plain HTTP loopback URLs without credentials/query/fragment are allowed')
    validate_levels(levels)
    validate_window(duration, request_cap)
    rows = []
    for concurrency in levels:
        start = time.monotonic()
        deadline = start + duration
        samples = []
        attempted = 0
        while attempted < request_cap and time.monotonic() < deadline:
            batch_size = min(concurrency, request_cap - attempted)
            with ThreadPoolExecutor(max_workers=concurrency) as pool:
                batch = list(pool.map(lambda _: one_request(parsed.hostname, parsed.port or 80,
                                                            parsed.path or '/'), range(batch_size)))
            samples.extend(batch)
            attempted += batch_size
        elapsed = time.monotonic() - start
        errors = sum(row['status'] != 200 for row in samples)
        latencies = sorted(row['latency_ms'] for row in samples)
        def pct(p):
            return latencies[math.ceil(p * len(latencies)) - 1] if latencies else None
        rows.append({'concurrency': concurrency, 'duration_seconds': elapsed,
                     'requests': len(samples), 'offered_requests': attempted,
                     'completed_requests': len(samples), 'errors': errors,
                     'error_rate': errors / len(samples) if samples else 0,
                     'requests_per_second': len(samples) / elapsed if elapsed else 0,
                     'p50_ms': pct(.5), 'p95_ms': pct(.95), 'p99_ms': pct(.99),
                     'resource_cap': False, 'resource_cap_status': 'not_sampled', 'raw': samples})
    return {'url': url, 'windows': rows, 'breaking_point': find_breaking_point(rows),
            'client_only_calibration': {'status': 'not_run',
                                        'reason': 'Harness does not establish generator capacity.'},
            'limitations': ['No server CPU, cgroup memory, OOM or queue metrics sampled.',
                            'Concurrent groups run in bounded batches and are closed-loop.',
                            'Breaking point requires repeated load windows; this sweep alone is insufficient.']}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--url', required=True)
    parser.add_argument('--duration', type=int, default=5)
    parser.add_argument('--requests', type=int, default=1000)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    try:
        report = run(args.url, DEFAULT_LEVELS, args.duration, args.requests)
    except ValueError as error:
        parser.error(str(error))
    report['source_revision'] = subprocess.check_output(['git', 'rev-parse', 'HEAD'], text=True).strip()
    report['client_platform'] = platform.platform()
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(report, indent=2) + '\n')
    return int(any(row['errors'] for row in report['windows']))


if __name__ == '__main__':
    raise SystemExit(main())
