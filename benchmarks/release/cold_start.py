"""Measure cold process start and HTTP response using a disposable local PHP app."""
import argparse
from collections import Counter
import http.client
import json
import math
import platform
import os
from pathlib import Path
import subprocess
import tempfile
import time
import uuid

MAX_SAMPLES = 20
READY_TIMEOUT_SECONDS = 30
IMAGE = 'php:8.4.26-fpm'


def validate_config(samples, readiness_timeout):
    if type(samples) is not int or not 1 <= samples <= MAX_SAMPLES:
        raise ValueError('samples must be 1..20')
    if type(readiness_timeout) is not int or not 1 <= readiness_timeout <= READY_TIMEOUT_SECONDS:
        raise ValueError('readiness timeout must be 1..30 seconds')
    return samples, readiness_timeout


def summarize(samples):
    result = {'samples': len(samples), 'ready_samples': sum(sample['ready'] for sample in samples),
              'failed_samples': sum(not sample['ready'] for sample in samples),
              'errors': dict(Counter(sample['error'] for sample in samples if sample['error']))}
    for key in ('process_ready_ms', 'first_request_ms', 'warm_request_ms'):
        values = sorted(sample[key] for sample in samples if sample[key] is not None)
        result[key.removesuffix('_ms')] = {
            'count': len(values),
            'p50_ms': values[math.ceil(.5 * len(values)) - 1] if values else None,
            'p95_ms': values[math.ceil(.95 * len(values)) - 1] if values else None,
        }
    return result


def docker(*args):
    return subprocess.check_output(['docker', *args], text=True, stderr=subprocess.STDOUT,
                                   timeout=10).strip()


def probe(port):
    started = time.perf_counter()
    try:
        connection = http.client.HTTPConnection('127.0.0.1', port, timeout=2)
        connection.request('GET', '/')
        response = connection.getresponse()
        body = response.read(1024)
        status = response.status
        connection.close()
    except (OSError, http.client.HTTPException):
        status, body = None, b''
    return {'status': status, 'body': body,
            'latency_ms': (time.perf_counter() - started) * 1000}


def run_sample(readiness_timeout):
    name = 'orku-cold-' + uuid.uuid4().hex[:12]
    with tempfile.TemporaryDirectory(prefix='orku-cold-') as directory:
        Path(directory, 'index.php').write_text('<?php header("Content-Type: application/json"); echo \'{"ok":true}\';')
        started = time.perf_counter()
        container = docker('run', '--detach', '--rm', '--cpus', '1', '--memory', '256m',
                           '--memory-swap', '256m', '--pids-limit', '128', '--name', name,
                           '--publish', '127.0.0.1::8080', '--mount',
                           f'type=bind,src={directory},dst=/fixture,readonly', IMAGE,
                           'php', '-S', '0.0.0.0:8080', '-t', '/fixture')
        try:
            port = int(docker('port', container, '8080/tcp').rsplit(':', 1)[1])
            ready_at = None
            while time.perf_counter() - started < readiness_timeout:
                if docker('inspect', '--format', '{{.State.Running}}', container) != 'true':
                    return {'process_ready_ms': None, 'first_request_ms': None, 'warm_request_ms': None,
                            'ready': False, 'error': 'process_exited_before_readiness'}
                readiness = probe(port)
                if readiness['status'] == 200 and readiness['body'] == b'{"ok":true}':
                    ready_at = time.perf_counter()
                    break
                time.sleep(.05)
            if ready_at is None:
                return {'process_ready_ms': None, 'first_request_ms': None, 'warm_request_ms': None,
                        'ready': False, 'error': 'readiness_deadline'}
            first = probe(port)
            warm = probe(port)
            ok = first['status'] == 200 and first['body'] == b'{"ok":true}' and warm['status'] == 200
            return {'process_ready_ms': (ready_at - started) * 1000,
                    'first_request_ms': first['latency_ms'], 'warm_request_ms': warm['latency_ms'],
                    'ready': ok, 'error': None if ok else 'request_failed_or_body_mismatch'}
        finally:
            subprocess.run(['docker', 'rm', '--force', container], check=False, timeout=10,
                           stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--samples', type=int, default=20)
    parser.add_argument('--readiness-timeout', type=int, default=30)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    try:
        validate_config(args.samples, args.readiness_timeout)
    except ValueError as error:
        parser.error(str(error))
    image_id = docker('image', 'inspect', IMAGE, '--format', '{{.Id}}')
    rows = [run_sample(args.readiness_timeout) for _ in range(args.samples)]
    report = {'image': IMAGE, 'image_id': image_id, 'configured_samples': args.samples,
              'samples': rows, 'summary': summarize(rows),
              'host': {'platform': platform.platform(), 'python': platform.python_version(),
                       'cpu_count': os.cpu_count()},
              'cache_policy': {'composer_classmap': 'no framework/dependencies',
                               'opcache': 'off', 'route_config_cache': 'not applicable'},
              'limitations': ['Built-in PHP server micro-app, not PHP-FPM/Nginx or the Orku/Laravel app.',
                              'Readiness and requests share the same process; readiness probe warms once.',
                              'No version comparison, HTTP route stack, or DB bootstrap measurement.']}
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(report, indent=2) + '\n')
    return int(report['summary']['failed_samples'] > 0)


if __name__ == '__main__':
    raise SystemExit(main())
