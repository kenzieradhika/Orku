"""Opt-in bounded soak runner for a disposable loopback endpoint."""
import argparse
from collections import Counter
import http.client
import json
import math
from pathlib import Path
import urllib.parse
import time

MAX_HOURS = 24
MAX_RATE = 2
MAX_CONCURRENCY = 4
INTERVAL_SECONDS = 60


def validate_config(config):
    hours, rate, concurrency = (config.get(key) for key in
                                ('duration_hours', 'rate', 'concurrency'))
    if (type(hours) is not int or not 1 <= hours <= MAX_HOURS
            or type(rate) is not int or not 1 <= rate <= MAX_RATE
            or type(concurrency) is not int or not 1 <= concurrency <= MAX_CONCURRENCY):
        raise ValueError('Soak limits: duration 1..24 hours, rate 1..2 req/s, concurrency 1..4.')
    return {'duration_hours': hours, 'rate': rate, 'concurrency': concurrency}


def summarize_window(rows):
    statuses = Counter(str(row['status']) for row in rows if row['status'] is not None)
    errors = sum(row['status'] != 200 for row in rows)
    latencies = sorted(row['latency_ms'] for row in rows)
    def pct(p):
        return latencies[math.ceil(p * len(latencies)) - 1] if latencies else None
    return {'requests': len(rows), 'errors': errors, 'statuses': dict(statuses),
            'error_rate': errors / len(rows) if rows else 0,
            'p50_ms': pct(.5), 'p95_ms': pct(.95), 'p99_ms': pct(.99),
            'memory_bytes': max((row['memory_bytes'] for row in rows
                                 if row['memory_bytes'] is not None), default=None)}


def memory_slope_mib_per_hour(samples):
    if len(samples) < 2:
        return None
    first, last = samples[0], samples[-1]
    hours = (last['elapsed_seconds'] - first['elapsed_seconds']) / 3600
    if hours <= 0 or first['memory_bytes'] is None or last['memory_bytes'] is None:
        return None
    return (last['memory_bytes'] - first['memory_bytes']) / 1024 / 1024 / hours


def should_stop(stop_exists, now, deadline):
    return stop_exists or now >= deadline


def is_safe_url(parsed):
    try:
        port = parsed.port or 80
    except ValueError:
        return False
    return (parsed.scheme == 'http' and parsed.hostname in ('127.0.0.1', 'localhost', '::1')
            and parsed.username is None and parsed.password is None
            and not parsed.fragment and not parsed.query and 1 <= port <= 65535)


def request(url):
    parsed = urllib.parse.urlsplit(url)
    started = time.perf_counter_ns()
    status = None
    try:
        conn = http.client.HTTPConnection(parsed.hostname, parsed.port or 80, timeout=5)
        conn.request('GET', parsed.path or '/')
        response = conn.getresponse()
        body = response.read(65537)
        status = response.status
        if len(body) > 65536:
            status = None
        conn.close()
    except (OSError, http.client.HTTPException):
        pass
    return {'status': status, 'latency_ms': (time.perf_counter_ns() - started) / 1e6,
            'memory_bytes': None}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--url', required=True, help='Disposable loopback HTTP URL')
    parser.add_argument('--duration-hours', type=int, required=True)
    parser.add_argument('--rate', type=int, default=2)
    parser.add_argument('--concurrency', type=int, default=4)
    parser.add_argument('--stop-file', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    parsed = urllib.parse.urlsplit(args.url)
    if not is_safe_url(parsed) or not parsed.path.startswith('/') or len(args.url) > 2048:
        parser.error('--url must be loopback HTTP')
    config = validate_config({'duration_hours': args.duration_hours, 'rate': args.rate,
                              'concurrency': args.concurrency})
    start = time.monotonic()
    deadline = start + config['duration_hours'] * 3600
    next_request = start
    rows = []
    intervals = []
    interval_start = start
    if not args.stop_file.parent.is_dir():
        parser.error('--stop-file parent directory must already exist')
    if args.stop_file.exists():
        parser.error('--stop-file already exists; remove/rename it before starting')
    while not should_stop(args.stop_file.exists(), time.monotonic(), deadline):
        now = time.monotonic()
        if now < next_request:
            time.sleep(min(next_request - now, 1))
            continue
        rows.append(request(args.url))
        next_request += 1 / config['rate']
        if now - interval_start >= INTERVAL_SECONDS:
            intervals.append({'elapsed_seconds': now - start, 'timestamp': time.time(), **summarize_window(rows)})
            if intervals[-1]['error_rate'] > .001:
                break
            rows = []
            interval_start = now
    if rows:
        intervals.append({'elapsed_seconds': time.monotonic() - start, 'timestamp': time.time(),
                          **summarize_window(rows)})
    report = {'url': args.url, 'config': config, 'elapsed_seconds': time.monotonic() - start,
              'stopped_by_file': args.stop_file.exists(), 'intervals': intervals,
              'stopped_by_error_threshold': bool(intervals and intervals[-1]['error_rate'] > .001),
              'memory_slope_mib_per_hour': memory_slope_mib_per_hour([
                  row for row in intervals if row['memory_bytes'] is not None]),
              'limitations': ['Sequential client, no concurrency load.',
                              'Server cgroup memory and process restarts are not sampled.',
                              'No session/CSRF endpoint supplied; state isolation remains untested.']}
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(report, indent=2) + '\n')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
