#!/usr/bin/env python3
"""Opt-in isolated Apache smoke check; requires an installed PHP Docker image."""
import http.client
import json
from pathlib import Path
import subprocess
import time


def main():
    root = Path(__file__).resolve().parents[1]
    container = subprocess.check_output([
        'docker', 'run', '--detach', '--rm', '--network', 'bridge',
        '--publish', '127.0.0.1::80',
        '--mount', f'type=bind,src={root},dst=/workspace/Orku,readonly',
        '--mount', f'type=bind,src={root}/tests/fixtures/apache.conf,dst=/etc/apache2/sites-enabled/000-default.conf,readonly',
        'php:8.4.26-apache',
    ], text=True).strip()
    try:
        binding = subprocess.check_output(['docker', 'port', container, '80/tcp'], text=True).strip()
        port = int(binding.rsplit(':', 1)[1])

        def request(path, method='GET'):
            connection = http.client.HTTPConnection('127.0.0.1', port, timeout=5)
            try:
                connection.request(method, path)
                response = connection.getresponse()
                return response.status, dict(response.getheaders()), response.read()
            finally:
                connection.close()

        for _ in range(100):
            try:
                request('/')
                break
            except (OSError, http.client.HTTPException):
                time.sleep(.1)
        else:
            raise RuntimeError('Apache did not start')
        status, headers, body = request('/')
        assert (status, body) == (200, b'Hello, Orku!\n'), (status, body)
        assert headers.get('x-content-type-options') == 'nosniff', headers
        assert 'X-Powered-By' not in headers, headers
        assert request('/hello/Ada')[2] == b'Hello, Ada!\n'
        assert request('/', 'HEAD')[2] == b''
        assert request('/', 'HEAD')[1].get('Content-Length') == '13'
        assert request('/', 'DELETE')[0] == 405
        for path in ['/missing', '/.env', '/composer.json', '/vendor/autoload.php', '/app.php', '/src/Http/Response.php']:
            status, _, body = request(path)
            assert status == 404, (path, status)
            assert b'<?php' not in body and b'/workspace/' not in body, (path, body)
        print(json.dumps({'runtime': 'php:8.4.26-apache', 'checks': ['routing', 'HEAD', '405', 'security headers', 'source isolation'], 'status': 'passed'}))
    finally:
        subprocess.run(['docker', 'stop', '--timeout', '2', container], check=True, stdout=subprocess.DEVNULL)


if __name__ == '__main__':
    main()
