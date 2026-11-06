#!/usr/bin/env python3
"""Isolated Nginx/FPM deployment smoke check. Requires Docker."""
import http.client
import json
from pathlib import Path
import subprocess
import time
import uuid


def docker(*args):
    return subprocess.check_output(['docker', *args], text=True).strip()


def main():
    root = Path(__file__).resolve().parents[1]
    network = 'orku-check-' + uuid.uuid4().hex
    containers = []
    docker('network', 'create', network)
    try:
        mount = f'type=bind,src={root},dst=/workspace/Orku,readonly'
        containers.append(docker('run', '--detach', '--rm', '--network', network,
                                 '--network-alias', 'php', '--mount', mount,
                                 'php:8.4.26-fpm', 'php-fpm', '-F', '-d', 'expose_php=0', '-d', 'display_errors=0'))
        containers.append(docker('run', '--detach', '--rm', '--network', network,
                                 '--publish', '127.0.0.1::80', '--mount', mount,
                                 '--mount', f'type=bind,src={root}/tests/fixtures/nginx.conf,dst=/etc/nginx/conf.d/default.conf,readonly',
                                 'nginx:1.28-alpine'))
        port = int(docker('port', containers[-1], '80/tcp').rsplit(':', 1)[1])

        def request(path, method='GET'):
            connection = http.client.HTTPConnection('127.0.0.1', port, timeout=5)
            try:
                connection.request(method, path)
                response = connection.getresponse()
                return response.status, {k.lower(): v for k, v in response.getheaders()}, response.read()
            finally:
                connection.close()

        for _ in range(100):
            try:
                if request('/')[0] == 200:
                    break
            except (OSError, http.client.HTTPException):
                pass
            time.sleep(.1)
        else:
            for container in containers:
                subprocess.run(['docker', 'logs', container], check=False)
            raise RuntimeError('Nginx/FPM did not become ready')
        status, headers, body = request('/')
        assert (status, body) == (200, b'Hello, Orku!\n'), (status, body)
        assert headers.get('x-content-type-options') == 'nosniff', headers
        assert 'x-powered-by' not in headers, headers
        assert request('/hello/Ada')[2] == b'Hello, Ada!\n'
        assert request('/', 'HEAD')[2] == b''
        assert request('/', 'HEAD')[1].get('content-length') == '13'
        assert request('/', 'DELETE')[0] == 405
        for path in ['/missing', '/.env', '/composer.json', '/vendor/autoload.php', '/app.php', '/src/Http/Response.php', '/index.php/extra']:
            status, _, body = request(path)
            assert status == 404, (path, status)
            assert b'<?php' not in body and b'/workspace/' not in body, (path, body)
        benchmark = subprocess.run(['python3', str(root / 'benchmarks/http_latency.py'),
                                    f'http://127.0.0.1:{port}/api/status', '--requests', '100',
                                    '--warmup', '10', '--concurrency', '4', '--runtime-label',
                                    'isolated nginx:1.28-alpine + php:8.4.26-fpm smoke only'],
                                   check=True, capture_output=True, text=True)
        result = json.loads(benchmark.stdout)
        assert result['statuses'] == {'200': 100}, result
        print(json.dumps({'runtime': 'nginx:1.28-alpine + php:8.4.26-fpm',
                          'status': 'passed', 'concurrent_requests': 100, 'errors': result['errors']}))
    finally:
        for container in reversed(containers):
            subprocess.run(['docker', 'stop', '--timeout', '2', container], check=False, stdout=subprocess.DEVNULL)
        subprocess.run(['docker', 'network', 'rm', network], check=False, stdout=subprocess.DEVNULL)


if __name__ == '__main__':
    main()
