#!/usr/bin/env python3
"""Sequential loopback HTTP baseline, not a production capacity estimate."""
import argparse
from collections import Counter
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


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('url', help='Explicit authorized loopback HTTP endpoint')
    parser.add_argument('--requests', type=int, default=500)
    parser.add_argument('--warmup', type=int, default=50)
    parser.add_argument('--runtime-label', required=True,
                        help='Operator-supplied server/runtime configuration label')
    parser.add_argument('--server-pid', type=int)
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

    resources = {'pid': args.server_pid, 'rss_start_bytes': None,
                 'rss_end_bytes': None, 'cpu_seconds': None,
                 'unavailable_reason': 'No --server-pid supplied'}
    samples = []
    statuses = Counter()
    transport_errors = 0
    warmup_errors = 0
    before = None
    target = urllib.parse.urlunsplit(('', '', url.path or '/', url.query, ''))
    for i in range(args.warmup + args.requests):
        if i == args.warmup:
            if args.server_pid is not None:
                try:
                    before = process_sample(args.server_pid)
                    resources['rss_start_bytes'] = before['rss']
                    resources['unavailable_reason'] = None
                except (OSError, ValueError, IndexError):
                    resources['unavailable_reason'] = 'Process unavailable or Linux /proc unsupported'
            started = time.perf_counter()
        connection = http.client.HTTPConnection(url.hostname, port, timeout=10)
        tick = time.perf_counter_ns()
        status = None
        try:
            connection.request('GET', target)
            response = connection.getresponse()
            while response.read(65536):
                pass
            status = response.status
        except (OSError, http.client.HTTPException):
            pass
        finally:
            connection.close()
        if i >= args.warmup:
            samples.append((time.perf_counter_ns() - tick) / 1e6)
            if status is None:
                transport_errors += 1
            else:
                statuses[str(status)] += 1
        else:
            warmup_errors += status != 200
    wall_seconds = time.perf_counter() - started
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
        'kind': 'sequential loopback HTTP; fresh TCP per request; no redirects; 10s timeout',
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
