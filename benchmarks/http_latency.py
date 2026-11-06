#!/usr/bin/env python3
"""Sequential loopback latency benchmark; not a production capacity estimate."""
import argparse
import http.client
import json
import math
import statistics
import time
import urllib.parse

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('url', help='Explicit authorized local HTTP endpoint')
parser.add_argument('--requests', type=int, default=500)
args = parser.parse_args()
url = urllib.parse.urlsplit(args.url)
if url.scheme != 'http' or url.hostname not in ('127.0.0.1', 'localhost', '::1') or not 1 <= args.requests <= 100000:
    parser.error('Use local HTTP URL and 1..100000 requests')
samples = []
errors = 0
for i in range(50 + args.requests):
    connection = http.client.HTTPConnection(url.hostname, url.port or 80, timeout=10)
    tick = time.perf_counter_ns()
    connection.request('GET', url.path or '/')
    response = connection.getresponse()
    response.read()
    elapsed = (time.perf_counter_ns() - tick) / 1e6
    connection.close()
    if i >= 50:
        samples.append(elapsed)
        errors += response.status != 200
ordered = sorted(samples)
print(json.dumps({'url': args.url, 'requests': args.requests, 'warmup': 50,
    'kind': 'sequential local HTTP, fresh TCP connection per request, PHP development server',
    'mean_ms': statistics.mean(samples), 'p95_ms': ordered[math.ceil(.95 * len(ordered)) - 1],
    'requests_per_second_excluding_client_loop_overhead': 1000 / statistics.mean(samples),
    'errors': errors}, indent=2))
