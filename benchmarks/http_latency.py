#!/usr/bin/env python3
"""Bounded loopback HTTP baseline, not a production capacity estimate."""
import argparse
from collections import Counter
from concurrent.futures import ThreadPoolExecutor
import http.client
import json
import math
import os
from pathlib import Path
import platform
import statistics
import time
import urllib.parse


def process_sample(pid):
    fields = Path(f'/proc/{pid}/stat').read_text().rsplit(')', 1)[1].split()
    if fields[0] in ('Z', 'X', 'x'):
        raise ValueError('Process exited')
    return {
        'identity': fields[19],
        'cpu': (int(fields[11]) + int(fields[12])) / os.sysconf('SC_CLK_TCK'),
        'rss': int(fields[21]) * os.sysconf('SC_PAGE_SIZE'),
    }


class Session:
    """Per-worker HTTP session; optionally reuses one TCP connection."""

    def __init__(self, host, port, keep_alive):
        self.host = host
        self.port = port
        self.keep_alive = keep_alive
        self.connection = http.client.HTTPConnection(host, port, timeout=10) if keep_alive else None

    def get(self, target):
        tick = time.perf_counter_ns()
        status = None
        connection = self.connection or http.client.HTTPConnection(self.host, self.port, timeout=10)
        try:
            connection.request('GET', target)
            response = connection.getresponse()
            while response.read(65536):
                pass
            status = response.status
            if self.keep_alive and response.will_close:
                connection.close()
                self.connection = http.client.HTTPConnection(self.host, self.port, timeout=10)
        except (OSError, http.client.HTTPException):
            connection.close()
            if self.keep_alive:
                self.connection = http.client.HTTPConnection(self.host, self.port, timeout=10)
        finally:
            if not self.keep_alive:
                connection.close()
        return status, (time.perf_counter_ns() - tick) / 1e6


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('url', help='Explicit authorized loopback HTTP endpoint')
    parser.add_argument('--requests', type=int, default=500)
    parser.add_argument('--warmup', type=int, default=50)
    parser.add_argument('--concurrency', type=int, default=1)
    parser.add_argument('--runtime-label', required=True,
                        help='Operator-supplied server/runtime configuration label')
    parser.add_argument('--server-pid', type=int)
    parser.add_argument('--keep-alive', action='store_true',
                        help='Reuse one TCP connection per worker instead of one connection per request')
    args = parser.parse_args()
    try:
        url = urllib.parse.urlsplit(args.url)
        port = 80 if url.port is None else url.port
        valid = (url.scheme == 'http'
                 and url.hostname in ('127.0.0.1', 'localhost', '::1')
                 and url.username is None and url.password is None
                 and not url.fragment and args.url.isascii() and 1 <= port <= 65535)
    except ValueError:
        valid = False
    if not valid or not 1 <= args.requests <= 100000 or not 0 <= args.warmup <= 100000:
        parser.error('Use loopback HTTP without credentials/fragments, valid port, 1..100000 requests and 0..100000 warmup')
    if args.server_pid is not None and args.server_pid <= 0:
        parser.error('--server-pid must be positive')
    if not 1 <= args.concurrency <= 128:
        parser.error('--concurrency must be 1..128')

    resources = {'pid': args.server_pid, 'rss_start_bytes': None,
                 'rss_end_bytes': None, 'cpu_seconds': None,
                 'unavailable_reason': 'No --server-pid supplied'}
    samples = []
    statuses = Counter()
    transport_errors = 0
    warmup_errors = 0
    before = None
    target = urllib.parse.urlunsplit(('', '', url.path or '/', url.query, ''))
    def worker(offset):
        session = Session(url.hostname, port, args.keep_alive)
        try:
            return [session.get(target)
                    for _ in range(offset, args.requests, args.concurrency)]
        finally:
            if session.connection is not None:
                session.connection.close()

    warmup_session = Session(url.hostname, port, args.keep_alive)
    for _ in range(args.warmup):
        warmup_errors += warmup_session.get(target)[0] != 200
    if warmup_session.connection is not None:
        warmup_session.connection.close()
    if args.server_pid is not None:
        try:
            before = process_sample(args.server_pid)
            resources['rss_start_bytes'] = before['rss']
            resources['unavailable_reason'] = None
        except (OSError, ValueError, IndexError):
            resources['unavailable_reason'] = 'Process unavailable or Linux /proc unsupported'
    started = time.perf_counter()
    with ThreadPoolExecutor(max_workers=args.concurrency) as pool:
        results = list(pool.map(worker, range(min(args.concurrency, args.requests))))
    wall_seconds = time.perf_counter() - started
    for batch in results:
        for status, latency in batch:
            samples.append(latency)
            if status is None:
                transport_errors += 1
            else:
                statuses[str(status)] += 1
    if before is not None:
        try:
            after = process_sample(args.server_pid)
            if after['identity'] != before['identity']:
                raise ValueError('PID reused')
            resources['rss_end_bytes'] = after['rss']
            resources['cpu_seconds'] = after['cpu'] - before['cpu']
        except (OSError, ValueError, IndexError):
            resources['unavailable_reason'] = 'Process exited, changed identity or could not be sampled'
    ordered = sorted(samples)
    errors = transport_errors + sum(count for status, count in statuses.items() if status != '200')
    print(json.dumps({
        'url': args.url, 'runtime_label': args.runtime_label,
        'client_python': platform.python_version(), 'client_platform': platform.platform(),
        'kind': ('closed-loop loopback HTTP; keep-alive TCP per worker; no redirects; 10s timeout'
                 if args.keep_alive else
                 'closed-loop loopback HTTP; fresh TCP per request; no redirects; 10s timeout'),
        'concurrency': args.concurrency,
        'requests': args.requests, 'warmup': args.warmup, 'warmup_errors': warmup_errors,
        'wall_seconds': wall_seconds, 'requests_per_second': args.requests / wall_seconds,
        'mean_ms': statistics.mean(samples),
        **{f'p{p}_ms': ordered[math.ceil(p / 100 * len(ordered)) - 1] for p in (50, 95, 99)},
        'statuses': dict(statuses), 'transport_errors': transport_errors,
        'errors': errors, 'error_rate': errors / args.requests,
        'server_process': resources,
    }, indent=2))
    return int(errors > 0)


if __name__ == '__main__':
    raise SystemExit(main())
