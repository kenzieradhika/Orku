#!/usr/bin/env python3
"""Bounded Orku/Laravel CRUD/API benchmark over disposable loopback fixtures.

The measured interval contains only HTTP requests. Schema reset and deterministic
pool creation happen first and are excluded from latency/throughput samples.
Run one framework, or run both sequentially with the same arguments and compare
the raw JSON evidence; never run both concurrently on the same host.
"""
import argparse
from concurrent.futures import ThreadPoolExecutor, as_completed
import http.client
import json
import math
import platform
import subprocess
import time
import urllib.parse
from pathlib import Path

MAX_REQUESTS_PER_PHASE = 2000
MAX_TOTAL_REQUESTS = 5000
MAX_CONCURRENCY = 64
MAX_SECONDS = 120
MAX_BODY_BYTES = 65536
REQUEST_TIMEOUT = 5

CONTRACT = json.loads((Path(__file__).with_name('contract.json')).read_text())
SEED_ROWS = int(CONTRACT['seed_rows'])


def validate_target(base_url):
    try:
        parsed = urllib.parse.urlsplit(base_url)
        port = parsed.port or 80
        valid = (parsed.scheme == 'http' and parsed.hostname in ('127.0.0.1', 'localhost')
                 and parsed.username is None
                 and parsed.password is None and parsed.path in ('', '/')
                 and not parsed.query and not parsed.fragment and 1 <= port <= 65535)
    except (TypeError, ValueError):
        valid = False
    if not valid:
        raise ValueError('Only credential-free loopback HTTP base URLs are accepted.')
    return base_url.rstrip('/')


def validate_config(requests, concurrency):
    if type(requests) is not int or not 1 <= requests <= MAX_REQUESTS_PER_PHASE:
        raise ValueError(f'requests per phase must be 1..{MAX_REQUESTS_PER_PHASE}')
    if type(concurrency) is not int or not 1 <= concurrency <= MAX_CONCURRENCY:
        raise ValueError(f'concurrency must be 1..{MAX_CONCURRENCY}')
    if requests * 5 > MAX_TOTAL_REQUESTS:
        raise ValueError(f'measured total must stay at or below {MAX_TOTAL_REQUESTS}')
    return requests, concurrency


def percentile(values, fraction):
    if not values:
        return None
    ordered = sorted(values)
    return ordered[max(0, math.ceil(fraction * len(ordered)) - 1)]


def request(base_url, method, path, payload=None, timeout=REQUEST_TIMEOUT):
    parsed = urllib.parse.urlsplit(base_url)
    started = time.perf_counter_ns()
    status = None
    error = None
    try:
        connection = http.client.HTTPConnection(parsed.hostname, parsed.port or 80, timeout=timeout)
        headers = {'Accept': 'application/json'}
        body = None
        if payload is not None:
            body = json.dumps(payload, separators=(',', ':')).encode()
            headers['Content-Type'] = 'application/json'
        connection.request(method, path, body=body, headers=headers)
        response = connection.getresponse()
        status = response.status
        content = response.read(MAX_BODY_BYTES + 1)
        if len(content) > MAX_BODY_BYTES:
            error = 'response_body_limit'
        connection.close()
    except (OSError, http.client.HTTPException):
        error = 'transport'
    return {'status': status, 'error': error,
            'latency_ms': (time.perf_counter_ns() - started) / 1e6}


def summarize(rows, expected_statuses):
    latencies = [row['latency_ms'] for row in rows]
    statuses = {}
    errors = 0
    for row in rows:
        key = 'transport' if row['status'] is None else str(row['status'])
        statuses[key] = statuses.get(key, 0) + 1
        if row['error'] is not None or row['status'] not in expected_statuses:
            errors += 1
    return {'requests': len(rows), 'statuses': statuses, 'errors': errors,
            'error_rate': errors / len(rows) if rows else 0,
            'p50_ms': percentile(latencies, .50), 'p95_ms': percentile(latencies, .95),
            'p99_ms': percentile(latencies, .99),
            'latency_samples_ms': latencies}


def build_phases(run_tag, update_ids, delete_ids):
    return [
        {'name': 'list', 'method': 'GET', 'path': '/api/items?limit=50', 'expected': {200}},
        {'name': 'get', 'method': 'GET',
         'path': lambda index: f'/api/items/{1 + index % 100}', 'expected': {200}},
        {'name': 'create', 'method': 'POST', 'path': '/api/items',
         'payload': lambda index: {'title': f'Bench create {index}',
                                   'body': f'Created by {run_tag}',
                                   'client_key': f'{run_tag}-c{index}'}, 'expected': {201}},
        {'name': 'replace', 'method': 'PUT',
         'path': lambda index: f'/api/items/{update_ids[index]}',
         'payload': lambda index: {'title': f'Bench update {index}', 'body': 'Updated body',
                                   'version': 1}, 'expected': {200}},
        {'name': 'delete', 'method': 'DELETE',
         'path': lambda index: f'/api/items/{delete_ids[index]}?version=1', 'expected': {204}},
    ]


def run_phase(base_url, phase, requests, concurrency, deadline):
    def attempt(index):
        path = phase['path'](index) if callable(phase['path']) else phase['path']
        payload = phase['payload'](index) if 'payload' in phase else None
        row = request(base_url, phase['method'], path, payload)
        row.update({'phase': phase['name'], 'attempt': index, 'path': path})
        return row

    started = time.monotonic()
    results = []
    with ThreadPoolExecutor(max_workers=concurrency) as pool:
        futures = {pool.submit(attempt, index): index for index in range(requests)}
        remaining = max(0.001, deadline - time.monotonic())
        try:
            for future in as_completed(futures, timeout=remaining):
                results.append(future.result())
        except TimeoutError:
            for future in futures:
                if not future.done():
                    future.cancel()
            done = sum(future.done() and not future.cancelled() for future in futures)
            results.extend({'phase': phase['name'], 'attempt': index, 'path': '',
                            'status': None, 'error': 'deadline', 'latency_ms': 0.0}
                           for index in range(done, requests))
    results.sort(key=lambda row: row['attempt'])
    elapsed = max(time.monotonic() - started, 1e-6)
    summary = summarize(results, phase['expected'])
    summary.update({'name': phase['name'], 'elapsed_seconds': elapsed,
                    'requests_per_second': requests / elapsed})
    return summary, results


def create_pool(base_url, prefix, count):
    ids = []
    for index in range(count):
        payload = {'title': f'Pool {prefix} {index}', 'body': 'Pool body',
                   'client_key': f'{prefix}-pool-{index}'}
        row = request_with_json(base_url, 'POST', '/api/items', payload)
        if row['status'] != 201 or not isinstance(row.get('json'), dict):
            raise RuntimeError(f'pool setup failed for {prefix}: status={row["status"]}')
        ids.append(int(row['json']['id']))
    return ids


def request_with_json(base_url, method, path, payload=None):
    parsed = urllib.parse.urlsplit(base_url)
    status = None
    data = None
    error = None
    try:
        connection = http.client.HTTPConnection(parsed.hostname, parsed.port or 80, timeout=REQUEST_TIMEOUT)
        headers = {'Accept': 'application/json'}
        body = None
        if payload is not None:
            body = json.dumps(payload, separators=(',', ':')).encode()
            headers['Content-Type'] = 'application/json'
        connection.request(method, path, body=body, headers=headers)
        response = connection.getresponse()
        status = response.status
        content = response.read(MAX_BODY_BYTES + 1)
        if len(content) <= MAX_BODY_BYTES:
            data = json.loads(content) if content else None
        else:
            error = 'response_body_limit'
        connection.close()
    except (OSError, http.client.HTTPException, ValueError):
        error = 'transport'
    return {'status': status, 'json': data, 'error': error}


def container_resources(name):
    if not name:
        return {'status': 'UNMEASURED', 'reason': 'no --container supplied'}
    command = ['docker', 'exec', name, 'sh', '-c',
               "awk '/usage_usec/{print \"cpu_usage_usec \" $2}' /sys/fs/cgroup/cpu.stat; "
               'printf "memory_current_bytes "; cat /sys/fs/cgroup/memory.current; '
               'printf "memory_peak_bytes "; cat /sys/fs/cgroup/memory.peak']
    try:
        raw = subprocess.run(command, capture_output=True, text=True, timeout=5, check=True).stdout
        values = {}
        for line in raw.splitlines():
            parts = line.split()
            if len(parts) == 2 and parts[1].isdigit():
                values[parts[0]] = int(parts[1])
        return {'status': 'AVAILABLE', 'method': 'cgroup-v2',
                'cpu_usage_usec': values.get('cpu_usage_usec'),
                'memory_current_bytes': values.get('memory_current_bytes'),
                'memory_peak_bytes': values.get('memory_peak_bytes')}
    except (OSError, subprocess.SubprocessError, ValueError):
        return {'status': 'UNAVAILABLE', 'reason': 'docker exec cgroup read failed'}


def run(base_url, requests, concurrency, run_tag, container=None):
    base_url = validate_target(base_url)
    requests, concurrency = validate_config(requests, concurrency)
    deadline = time.monotonic() + MAX_SECONDS
    reset = request_with_json(base_url, 'POST', CONTRACT['maintenance']['reset']['path'])
    if reset['status'] != 200 or reset.get('json', {}).get('rows') != SEED_ROWS:
        raise RuntimeError('fixture reset endpoint unavailable or returned unexpected row count')
    update_ids = create_pool(base_url, f'{run_tag}-update', requests)
    delete_ids = create_pool(base_url, f'{run_tag}-delete', requests)
    state_before = request_with_json(base_url, 'GET', CONTRACT['maintenance']['state']['path'])['json']
    before = container_resources(container)
    started = time.monotonic()
    started_cpu = before.get('cpu_usage_usec') if before['status'] == 'AVAILABLE' else None
    phases = {}
    raw = []
    for phase in build_phases(run_tag, update_ids, delete_ids):
        summary, rows = run_phase(base_url, phase, requests, concurrency, deadline)
        phases[phase['name']] = summary
        raw.extend(rows)
    elapsed = max(time.monotonic() - started, 1e-6)
    after = container_resources(container)
    state_after = request_with_json(base_url, 'GET', CONTRACT['maintenance']['state']['path'])
    create_ok = phases['create']['statuses'].get('201', 0)
    delete_ok = phases['delete']['statuses'].get('204', 0)
    expected_rows = SEED_ROWS + len(update_ids) + len(delete_ids) + create_ok - delete_ok
    actual_rows = (state_after.get('json') or {}).get('rows')
    integrity = {'expected_rows': expected_rows, 'actual_rows': actual_rows,
                 'ok': actual_rows == expected_rows,
                 'rows_before_measurement': (state_before or {}).get('rows'),
                 'max_version': (state_after.get('json') or {}).get('max_version')}
    total_errors = sum(phase['errors'] for phase in phases.values())
    resources = {'before': before, 'after': after}
    if (before['status'] == 'AVAILABLE' and after['status'] == 'AVAILABLE'
            and started_cpu is not None and after.get('cpu_usage_usec') is not None):
        resources['cpu_seconds_delta'] = (after['cpu_usage_usec'] - started_cpu) / 1e6
        resources['cpu_fraction_of_one_core'] = resources['cpu_seconds_delta'] / elapsed
        resources['note'] = 'FPM container cgroup only; nginx and SQLite page cache excluded.'
    return {
        'mode': 'http_crud_api',
        'base_url': base_url,
        'environment': {'python': platform.python_version(), 'platform': platform.platform(),
                        'source_revision': subprocess.check_output(
                            ['git', 'rev-parse', 'HEAD'], text=True).strip()},
        'config': {'requests_per_phase': requests, 'phases': ['list', 'get', 'create', 'replace', 'delete'],
                   'total_measured_requests': requests * 5, 'concurrency': concurrency,
                   'request_timeout_seconds': REQUEST_TIMEOUT, 'max_seconds': MAX_SECONDS,
                   'setup_requests_excluded': len(update_ids) + len(delete_ids) + 1},
        'elapsed_seconds': elapsed,
        'phases': phases,
        'totals': {'requests': requests * 5, 'errors': total_errors,
                   'error_rate': total_errors / (requests * 5),
                   'requests_per_second': requests * 5 / elapsed,
                   'p50_ms': percentile([row['latency_ms'] for row in raw], .50),
                   'p95_ms': percentile([row['latency_ms'] for row in raw], .95),
                   'p99_ms': percentile([row['latency_ms'] for row in raw], .99),
                   'http_status_distribution': _status_distribution(raw)},
        'database': {'integrity': integrity,
                     'note': 'HTTP transaction/latency only; direct DB microbenchmarks are separate.'},
        'resources': resources,
        'raw': raw,
    }


def _status_distribution(rows):
    distribution = {}
    for row in rows:
        key = 'transport' if row['status'] is None else str(row['status'])
        distribution[key] = distribution.get(key, 0) + 1
    return distribution


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--base-url', required=True)
    parser.add_argument('--requests', type=int, default=300,
                        help=f'requests per phase, 1..{MAX_REQUESTS_PER_PHASE} (five phases)')
    parser.add_argument('--concurrency', type=int, default=4)
    parser.add_argument('--run-tag', default='bench')
    parser.add_argument('--container', help='FPM container name for optional cgroup sampling')
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    report = run(args.base_url, args.requests, args.concurrency, args.run_tag, args.container)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(report, indent=2) + '\n')
    failed = report['totals']['errors'] > 0 or not report['database']['integrity']['ok']
    return int(failed)


if __name__ == '__main__':
    raise SystemExit(main())
