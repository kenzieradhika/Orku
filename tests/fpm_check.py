#!/usr/bin/env python3
"""Isolated Nginx/FPM deployment smoke check. Requires Docker."""
import http.client
import json
import re
from pathlib import Path
import subprocess
import tempfile
import time
import urllib.parse
import uuid


def docker(*args):
    return subprocess.check_output(['docker', *args], text=True).strip()


def check_session(request):
    status, headers, body = request('/contact')
    assert status == 200, (status, body)
    assert headers['cache-control'] == 'no-store', headers
    cookie = headers['set-cookie']
    for attribute in ['; Secure', '; HttpOnly', '; SameSite=Lax', '; Path=/']:
        assert attribute in cookie, cookie
    cookie = cookie.split(';', 1)[0]
    token = re.search(rb'name="_csrf" value="([^"]+)"', body).group(1).decode()
    _, other_headers, other_body = request('/contact')
    other_token = re.search(rb'name="_csrf" value="([^"]+)"', other_body).group(1).decode()
    assert other_headers['set-cookie'].split(';', 1)[0] != cookie
    assert other_token != token
    fields = {'_csrf': token, 'name': 'Ada', 'email': 'ada@example.test', 'message': 'Deployment test feedback.'}

    def submit(values, session_cookie=cookie):
        return request('/contact', 'POST', urllib.parse.urlencode(values),
                       {'Cookie': session_cookie, 'Content-Type': 'application/x-www-form-urlencoded'})

    assert submit({**fields, '_csrf': other_token})[0] == 403
    assert submit({**fields, '_csrf': ''})[0] == 403
    assert submit(fields, other_headers['set-cookie'].split(';', 1)[0])[0] == 403
    assert submit({**fields, 'name': ''})[0] == 422
    status, headers, body = submit(fields)
    assert status == 303, (status, body)
    assert headers['location'] == '/contact/success', headers
    assert headers['cache-control'] == 'no-store', headers
    print(json.dumps({'runtime': 'Nginx/FPM fullstack', 'status': 'passed',
                      'checks': ['session persistence', 'cookie policy', 'CSRF isolation', 'validation', '303']}))


def main(example='hello-world', resource_check=None):
    root = Path(__file__).resolve().parents[1]
    network = 'orku-check-' + uuid.uuid4().hex
    containers = []
    config = tempfile.NamedTemporaryFile(mode='w', dir='/tmp/opencode' if Path('/tmp/opencode').is_dir() else None)
    config.write((root / 'tests/fixtures/nginx.conf').read_text().replace('/hello-world/', f'/{example}/'))
    config.flush()
    docker('network', 'create', network)
    try:
        mount = f'type=bind,src={root},dst=/workspace/Orku,readonly'
        limits = ['--cpus', '1', '--memory', '256m', '--memory-swap', '256m', '--pids-limit', '128'] if resource_check else []
        containers.append(docker('run', '--detach', '--rm', *limits, '--network', network,
                                 '--network-alias', 'php', '--mount', mount,
                                 'php:8.4.26-fpm', 'php-fpm', '-F', '-d', 'expose_php=0', '-d', 'display_errors=0'))
        containers.append(docker('run', '--detach', '--rm', *limits, '--network', network,
                                 '--publish', '127.0.0.1::80', '--mount', mount,
                                 '--mount', f'type=bind,src={config.name},dst=/etc/nginx/conf.d/default.conf,readonly',
                                 'nginx:1.28-alpine'))
        port = int(docker('port', containers[-1], '80/tcp').rsplit(':', 1)[1])

        def request(path, method='GET', body=None, headers=None):
            connection = http.client.HTTPConnection('127.0.0.1', port, timeout=5)
            try:
                connection.request(method, path, body, headers or {})
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
        if example == 'fullstack-app':
            check_session(request)
            return
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
        if resource_check:
            resource_check(containers, port)
        print(json.dumps({'runtime': 'nginx:1.28-alpine + php:8.4.26-fpm',
                          'status': 'passed', 'concurrent_requests': 100, 'errors': result['errors']}))
    finally:
        for container in reversed(containers):
            subprocess.run(['docker', 'stop', '--timeout', '2', container], check=False, stdout=subprocess.DEVNULL)
        subprocess.run(['docker', 'network', 'rm', network], check=False, stdout=subprocess.DEVNULL)
        config.close()


if __name__ == '__main__':
    main()
    main('fullstack-app')
