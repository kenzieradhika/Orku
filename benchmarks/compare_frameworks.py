#!/usr/bin/env python3
"""Bounded local comparison. Supply a disposable Laravel 12 dependency directory."""
import argparse
import http.client
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import time
import uuid

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'tests'))
from fpm_check import docker
from resource_check import sample, usage_delta


def run(root, laravel, work, framework):
    app = work / framework
    (app / 'public').mkdir(parents=True)
    (app / 'bootstrap/cache').mkdir(parents=True)
    (app / 'storage').mkdir()
    if framework == 'laravel':
        for directory in ['bootstrap/cache', 'storage/framework/views', 'storage/framework/cache', 'storage/logs']:
            (app / directory).mkdir(parents=True, exist_ok=True)
        index = '''<?php
require '/laravel/vendor/autoload.php';
$app = Illuminate\\Foundation\\Application::configure(basePath: '/bench')
    ->withRouting(using: function () {
        Illuminate\\Support\\Facades\\Route::get('/api/status', fn () => response()->json(['framework'=>'benchmark','status'=>'running']));
    })->withMiddleware(function ($middleware) {})->withExceptions(function ($exceptions) {})->create();
$app->handleRequest(Illuminate\\Http\\Request::capture());
'''
    else:
        index = '''<?php
require '/orku/vendor/autoload.php';
$router = new Orku\\Routing\\Router();
$router->get('/api/status', fn () => Orku\\Http\\Response::json(['framework'=>'benchmark','status'=>'running']));
Orku\\Runtime\\SapiRuntime::run(new Orku\\Core\\Kernel($router));
'''
    (app / 'public/index.php').write_text(index)
    pool = work / 'pool.conf'
    pool.write_text('[www]\npm = static\npm.max_children = 4\nclear_env = no\n')
    config = work / 'nginx.conf'
    config.write_text('''server {
listen 80; server_name localhost; root /bench/public; index index.php;
location / { try_files $uri /index.php$is_args$args; }
location = /index.php {
include fastcgi_params;
fastcgi_param SCRIPT_FILENAME /bench/public/index.php;
fastcgi_pass php:9000;
}
location ~ \\.php { return 404; }
}
''')
    network = 'orku-compare-' + uuid.uuid4().hex
    containers = []
    docker('network', 'create', network)
    try:
        common = ['--network', network, '--cpus', '1', '--memory', '256m', '--memory-swap', '256m',
                  '--pids-limit', '128', '--mount', f'type=bind,src={app},dst=/bench,readonly']
        containers.append(docker('run', '--detach', '--rm', *common, '--network-alias', 'php',
            '--tmpfs', '/bench/bootstrap/cache:rw,size=8m,mode=1777',
            '--tmpfs', '/bench/storage:rw,size=8m,mode=1777',
            '--mount', f'type=bind,src={root},dst=/orku,readonly',
            '--mount', f'type=bind,src={laravel},dst=/laravel,readonly',
            '--mount', f'type=bind,src={pool},dst=/usr/local/etc/php-fpm.d/zz-benchmark.conf,readonly',
            '--env', 'APP_ENV=production', '--env', 'APP_DEBUG=false', '--env', 'LOG_CHANNEL=stderr',
            'php:8.4.26-fpm', 'php-fpm', '-F', '-d', 'opcache.enable=1', '-d', 'opcache.validate_timestamps=0',
            '-d', 'opcache.memory_consumption=64', '-d', 'display_errors=0', '-d', 'expose_php=0'))
        containers.append(docker('run', '--detach', '--rm', *common,
            '--publish', '127.0.0.1::80',
            '--mount', f'type=bind,src={config},dst=/etc/nginx/conf.d/default.conf,readonly', 'nginx:1.28-alpine'))
        host = docker('port', containers[-1], '80/tcp')
        for _ in range(100):
            try:
                connection = http.client.HTTPConnection(host, timeout=2)
                connection.request('GET', '/api/status')
                response = connection.getresponse()
                body = response.read()
                connection.close()
                if response.status == 200:
                    assert body == b'{"framework":"benchmark","status":"running"}', body
                    break
            except OSError:
                pass
            time.sleep(.1)
        else:
            for container in containers:
                subprocess.run(['docker', 'logs', container])
            raise RuntimeError('Benchmark app unavailable')
        results = []
        for concurrency in [4, 16, 64, 128]:
            for iteration in range(3):
                before = [sample(container) for container in containers]
                started = time.monotonic()
                process = subprocess.run([sys.executable, str(root / 'benchmarks/http_latency.py'),
                    f'http://{host}/api/status', '--requests', '1000', '--warmup', '50',
                    '--concurrency', str(concurrency), '--runtime-label', framework],
                    capture_output=True, text=True, timeout=120)
                elapsed = time.monotonic() - started
                after = [sample(container) for container in containers]
                result = {'framework': framework, 'round': iteration + 1,
                    'benchmark': json.loads(process.stdout), 'containers': {
                        name: {**usage_delta(start, end, elapsed), **end}
                        for name, start, end in zip(['fpm', 'nginx'], before, after)}}
                results.append(result)
                print(json.dumps(result), flush=True)
                if any(end['oom_kill'] != start['oom_kill'] for start, end in zip(before, after)):
                    raise RuntimeError('Container OOM')
        return results
    finally:
        for container in reversed(containers):
            subprocess.run(['docker', 'stop', '--timeout', '2', container], stdout=subprocess.DEVNULL)
        subprocess.run(['docker', 'network', 'rm', network], stdout=subprocess.DEVNULL)


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--laravel', type=Path, required=True)
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    failed = False
    with tempfile.TemporaryDirectory(dir='/tmp/opencode') as directory:
        work = Path(directory)
        for framework in ['orku', 'laravel']:
            results = run(root, args.laravel.resolve(), work, framework)
            failed |= any(result['benchmark']['errors'] or result['benchmark']['warmup_errors'] for result in results)
    sys.exit(1 if failed else 0)
