"""Bounded loopback spike/recovery schedule for disposable services."""
import argparse
from concurrent.futures import ThreadPoolExecutor
import http.client
import json
import math
from pathlib import Path
import time
import urllib.parse


def validate_phases(baseline_rps, spike_seconds, peak_concurrency, recovery_seconds):
    if (type(baseline_rps) is not int or not 1 <= baseline_rps <= 2
            or type(spike_seconds) is not int or not 1 <= spike_seconds <= 10
            or type(peak_concurrency) is not int or not 1 <= peak_concurrency <= 128
            or type(recovery_seconds) is not int or not 1 <= recovery_seconds <= 600
            or spike_seconds + recovery_seconds > 900):
        raise ValueError('bounded phases: baseline 1..2 rps, spike 1..10s, concurrency 1..128, recovery <=600s')
    return {'baseline_rps': baseline_rps, 'spike_seconds': spike_seconds,
            'peak_concurrency': peak_concurrency, 'recovery_seconds': recovery_seconds}


def recovery_result(windows):
    baselines = [row['p95_ms'] for row in windows if row['phase'] == 'baseline' and row['p95_ms'] is not None]
    if not baselines:
        return {'recovered': False, 'time_to_baseline_seconds': None}
    threshold = 2 * sorted(baselines)[len(baselines) // 2]
    bad = [row for row in windows if row['phase'] == 'recovery'
           and (row['errors'] > 0 or row['p95_ms'] is None or row['p95_ms'] > threshold)]
    if not bad:
        first = next((row for row in windows if row['phase'] == 'recovery'), None)
        return {'recovered': first is not None,
                'time_to_baseline_seconds': first['elapsed'] if first else None}
    first_bad_elapsed = bad[0]['elapsed']
    persistent = [row for row in bad if row['elapsed'] - first_bad_elapsed >= 120]
    errors_persist = any(row['errors'] > 0 and row['elapsed'] - first_bad_elapsed >= 60 for row in bad)
    return {'recovered': not persistent and not errors_persist, 'time_to_baseline_seconds': None}


def probe(host, port, path):
    start = time.perf_counter_ns()
    status = None
    try:
        conn = http.client.HTTPConnection(host, port, timeout=3)
        conn.request('GET', path)
        response = conn.getresponse()
        response.read(65537)
        status = response.status
        conn.close()
    except (OSError, http.client.HTTPException):
        pass
    return {'status': status, 'latency_ms': (time.perf_counter_ns() - start) / 1e6}


def run(url, config):
    config = validate_phases(**config)
    parsed = urllib.parse.urlsplit(url)
    if (parsed.scheme != 'http' or parsed.hostname not in ('127.0.0.1', 'localhost', '::1')
            or parsed.username is not None or parsed.password is not None or parsed.query or parsed.fragment):
        raise ValueError('only plain loopback HTTP is allowed')
    started = time.monotonic()
    windows = []
    def sample(phase, concurrency, duration, rate):
        end = time.monotonic() + duration
        samples = []
        next_at = time.monotonic()
        while time.monotonic() < end:
            now = time.monotonic()
            if now < next_at:
                time.sleep(min(next_at - now, .25))
                continue
            if concurrency == 1:
                batch = [probe(parsed.hostname, parsed.port or 80, parsed.path or '/')]
            else:
                with ThreadPoolExecutor(max_workers=concurrency) as pool:
                    batch = list(pool.map(lambda _: probe(parsed.hostname, parsed.port or 80,
                                                          parsed.path or '/'), range(concurrency)))
            samples.extend(batch)
            next_at += 1 / rate if rate else duration
        latencies = sorted(row['latency_ms'] for row in samples)
        p95 = latencies[math.ceil(.95 * len(latencies)) - 1] if latencies else None
        window = {'phase': phase, 'elapsed': time.monotonic() - started,
                  'requests': len(samples), 'errors': sum(row['status'] != 200 for row in samples),
                  'p95_ms': p95, 'raw': samples}
        windows.append(window)
    sample('baseline', 1, 30, config['baseline_rps'])
    sample('spike', config['peak_concurrency'], config['spike_seconds'], 0)
    sample('recovery', 1, config['recovery_seconds'], config['baseline_rps'])
    return {'url': url, 'config': config, 'windows': windows,
            'recovery': recovery_result(windows),
            'limitations': ['No server CPU/RAM, FPM worker, OOM, or session state metrics.',
                            'Closed-loop client; recovery evidence is response-only.']}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--url', required=True)
    parser.add_argument('--peak-concurrency', type=int, default=64)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    try:
        report = run(args.url, {'baseline_rps': 2, 'spike_seconds': 10,
                                'peak_concurrency': args.peak_concurrency, 'recovery_seconds': 600})
    except ValueError as error:
        parser.error(str(error))
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(report, indent=2) + '\n')
    return int(not report['recovery']['recovered'])


if __name__ == '__main__':
    raise SystemExit(main())
