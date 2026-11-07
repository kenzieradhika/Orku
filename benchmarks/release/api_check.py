#!/usr/bin/env python3
"""Bounded API comparison for disposable loopback applications."""
import argparse
from collections import Counter
from concurrent.futures import ThreadPoolExecutor, wait, FIRST_COMPLETED
import http.client
import json
import math
from pathlib import Path
import platform
import subprocess
import time
import urllib.parse

MAX_REQUESTS = 10000
MAX_CONCURRENCY = 128
MAX_SECONDS = 120
MAX_BODY_BYTES = 65536


def validate_scenario(scenario):
    if not isinstance(scenario, dict):
        raise ValueError('Scenario must be an object.')
    base_url = scenario.get('base_url')
    try:
        url = urllib.parse.urlsplit(base_url)
        port = url.port or 80
        loopback = (url.scheme == 'http' and url.hostname in ('127.0.0.1', 'localhost', '::1')
                    and not url.hostname.startswith('['))
        valid_url = (loopback and url.username is None and url.password is None
                     and not url.query and not url.fragment and url.path in ('', '/')
                     and 1 <= port <= 65535)
    except (TypeError, ValueError):
        valid_url = False
    requests = scenario.get('requests')
    concurrency = scenario.get('concurrency')
    duration = scenario.get('duration_seconds')
    endpoints = scenario.get('endpoints')
    if (not valid_url or type(requests) is not int or not 1 <= requests <= MAX_REQUESTS
            or not isinstance(scenario.get('mode', 'benchmark'), str)
            or scenario.get('mode', 'benchmark') not in ('benchmark', 'verify')
            or type(concurrency) is not int or not 1 <= concurrency <= MAX_CONCURRENCY
            or type(duration) not in (int, float) or not 1 <= duration <= MAX_SECONDS
            or not isinstance(endpoints, list) or not endpoints
            or requests * len(endpoints) > MAX_REQUESTS):
        raise ValueError('Invalid loopback URL, request/concurrency/time cap, or endpoints.')
    for endpoint in endpoints:
        if not isinstance(endpoint, dict):
            raise ValueError('Each endpoint must be an object.')
        path, method, expected, body = (endpoint.get(k) for k in ('path', 'method', 'expected_status', 'expected_body'))
        if (not isinstance(endpoint.get('name'), str) or not endpoint['name']
                or not isinstance(path, str) or not path.startswith('/') or path.startswith('//')
                or len(path) > 2048 or any(c in path for c in '\r\n') or not isinstance(method, str)
                or method not in ('GET', 'POST', 'PUT', 'PATCH', 'DELETE')
                or type(expected) is not int or not 100 <= expected <= 599
                or not isinstance(body, dict)):
            raise ValueError('Invalid endpoint definition.')
        if len(json.dumps(body).encode()) > MAX_BODY_BYTES:
            raise ValueError('Expected response body too large.')
    return True


def summarize(rows, expected_status):
    latencies = sorted(row['latency_ms'] for row in rows)
    statuses = Counter(str(row['status']) for row in rows if row['status'] is not None)
    transport_errors = sum(row['status'] is None for row in rows)
    errors = transport_errors + sum(n for status, n in statuses.items() if int(status) != expected_status)
    def percentile(p):
        return latencies[max(0, math.ceil(p * len(latencies)) - 1)] if latencies else None
    return {'requests': len(rows), 'statuses': dict(statuses), 'transport_errors': transport_errors,
            'errors': errors, 'p50_ms': percentile(.50), 'p95_ms': percentile(.95),
            'p99_ms': percentile(.99)}


def response_matches(status, body, expected_status, expected_body):
    return status == expected_status and body == expected_body


def request_once(host, port, endpoint):
    started = time.perf_counter_ns()
    status = None
    body = b''
    error = None
    try:
        conn = http.client.HTTPConnection(host, port, timeout=5)
        headers = {'Accept': 'application/json'}
        payload = endpoint.get('body')
        encoded = None if payload is None else json.dumps(payload, separators=(',', ':')).encode()
        if encoded is not None:
            headers['Content-Type'] = 'application/json'
        conn.request(endpoint['method'], endpoint['path'], body=encoded, headers=headers)
        response = conn.getresponse()
        status = response.status
        body = response.read(MAX_BODY_BYTES + 1)
        if len(body) > MAX_BODY_BYTES:
            error = 'response_body_limit'
        conn.close()
    except (OSError, http.client.HTTPException) as exc:
        error = type(exc).__name__
    return {'status': status, 'body': body, 'error': error,
            'latency_ms': (time.perf_counter_ns() - started) / 1e6}


def run(scenario):
    validate_scenario(scenario)
    parsed = urllib.parse.urlsplit(scenario['base_url'])
    host, port = parsed.hostname, parsed.port or 80
    started = time.monotonic()
    deadline = started + min(scenario['duration_seconds'], MAX_SECONDS)
    client_cpu_before = time.process_time()
    results = []
    concurrency = scenario['concurrency']
    endpoint_elapsed = []
    requested_count = 1 if scenario.get('mode') == 'verify' else scenario['requests']
    for endpoint_index, endpoint in enumerate(scenario['endpoints']):
        remaining = requested_count
        endpoint_started = time.monotonic()
        with ThreadPoolExecutor(max_workers=concurrency) as pool:
            futures = set()
            while remaining or futures:
                if time.monotonic() >= deadline:
                    for future in futures:
                        future.cancel()
                    results.extend({'status': None, 'body': b'', 'error': 'deadline_in_flight', 'latency_ms': 0.0}
                                   for _ in range(len(futures)))
                    remaining = max(0, remaining - len(futures))
                    futures.clear()
                    break
                while remaining and len(futures) < concurrency and time.monotonic() < deadline:
                    futures.add(pool.submit(request_once, host, port, endpoint))
                    remaining -= 1
                completed, _ = wait(futures, timeout=max(.001, deadline - time.monotonic()),
                                     return_when=FIRST_COMPLETED)
                if not completed:
                    done = None
                    unfinished = len(futures)
                    for future in futures:
                        future.cancel()
                    futures.clear()
                    results.extend({'endpoint_index': endpoint_index, 'status': None, 'body': b'',
                                    'error': 'deadline_in_flight', 'latency_ms': 0.0}
                                   for _ in range(unfinished))
                    remaining = max(0, remaining - unfinished)
                else:
                    done = completed.pop()
                if done is None:
                    break
                futures.remove(done)
                row = done.result()
                row['endpoint_index'] = endpoint_index
                results.append(row)
        if remaining:
            results.extend({'endpoint_index': endpoint_index, 'status': None, 'body': b'',
                            'error': 'deadline_not_started', 'latency_ms': 0.0}
                           for _ in range(remaining))
        endpoint_elapsed.append(max(time.monotonic() - endpoint_started, .000001))
    elapsed = time.monotonic() - started
    client_cpu = time.process_time() - client_cpu_before
    checks = []
    rows_per_endpoint = requested_count
    for endpoint_index, endpoint in enumerate(scenario['endpoints']):
        rows = [row for row in results if row['endpoint_index'] == endpoint_index]
        expected_body = json.dumps(endpoint['expected_body'], separators=(',', ':')).encode()
        mismatches = sum(not response_matches(row['status'], row['body'],
                                               endpoint['expected_status'], expected_body)
                         for row in rows if row['status'] is not None)
        mismatches += sum(bool(row['error']) for row in rows)
        summary = summarize(rows, endpoint['expected_status'])
        summary.update({'name': endpoint['name'], 'method': endpoint['method'],
                        'path': endpoint['path'], 'contract_mismatches': mismatches,
                        'requests_per_second': len(rows) / endpoint_elapsed[len(checks)]})
        checks.append(summary)
    return {'base_url': scenario['base_url'], 'duration_seconds': elapsed,
            'client_cpu_seconds': client_cpu, 'client_cpu_fraction': client_cpu / elapsed if elapsed else None,
            'client_platform': platform.platform(), 'python': platform.python_version(),
            'mode': scenario.get('mode', 'benchmark'),
            'configured_requests_per_endpoint': requested_count, 'concurrency': concurrency,
            'endpoints': checks,
            'raw': [{**row, 'body': row['body'].decode('utf-8', errors='replace')} for row in results],
            'server_resources': {'status': 'not_sampled', 'reason': 'Runner does not inspect container cgroups.'}}


def verify_contract(scenario, baseline):
    """Issue one request per endpoint to baseline and candidate before timing."""
    validate_scenario(scenario)
    if not isinstance(baseline, dict) or set(baseline) != {'base_url'}:
        raise ValueError('Baseline must contain only base_url.')
    candidate_url = urllib.parse.urlsplit(scenario['base_url'])
    baseline_url = urllib.parse.urlsplit(baseline['base_url'])
    for parsed in (candidate_url, baseline_url):
        if (parsed.scheme != 'http' or parsed.hostname not in ('127.0.0.1', 'localhost', '::1')
                or parsed.hostname.startswith('[')):
            raise ValueError('Only loopback HTTP URLs are allowed.')
    compared = []
    for endpoint in scenario['endpoints']:
        candidate = request_once(candidate_url.hostname, candidate_url.port or 80, endpoint)
        reference = request_once(baseline_url.hostname, baseline_url.port or 80, endpoint)
        same = response_matches(candidate['status'], candidate['body'],
                                reference['status'], reference['body'])
        expected = response_matches(candidate['status'], candidate['body'],
                                    endpoint['expected_status'],
                                    json.dumps(endpoint['expected_body'], separators=(',', ':')).encode())
        compared.append({'name': endpoint['name'], 'candidate': candidate['status'],
                         'baseline': reference['status'], 'equivalent': same and expected})
    return {'checks': compared, 'passed': all(row['equivalent'] for row in compared)}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('scenario', type=Path)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--verify-against', help='Baseline loopback base URL; skip measurement and verify contracts')
    args = parser.parse_args()
    scenario = json.loads(args.scenario.read_text())
    if args.verify_against:
        report = verify_contract(scenario, {'base_url': args.verify_against})
        report['source_revision'] = subprocess.check_output(['git', 'rev-parse', 'HEAD'], text=True).strip()
        report['scenario'] = scenario
        args.output.parent.mkdir(parents=True, exist_ok=True)
        args.output.write_text(json.dumps(report, indent=2) + '\n')
        return int(not report['passed'])
    report = run(scenario)
    report['source_revision'] = subprocess.check_output(['git', 'rev-parse', 'HEAD'], text=True).strip()
    report['scenario'] = scenario
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(report, indent=2) + '\n')
    return int(any(endpoint['errors'] or endpoint['contract_mismatches'] for endpoint in report['endpoints']))


if __name__ == '__main__':
    raise SystemExit(main())
