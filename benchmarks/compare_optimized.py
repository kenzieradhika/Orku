#!/usr/bin/env python3
"""Ronde 2: Orku vs Laravel production-optimized. Requires Docker + disposable Laravel deps."""
import argparse
from datetime import datetime, timezone
import hashlib
import http.client
import json
import os
from pathlib import Path
import platform
import subprocess
import sys
import tempfile
import time
import uuid

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'tests'))
from fpm_check import docker
from resource_check import sample, usage_delta

CONCURRENCY_LEVELS = [4, 16, 64, 128]
ROUNDS = 5
REQUESTS = 1000
WARMUP = 200
EXPECTED_BODY = b'{"framework":"benchmark","status":"running"}'

ORKU_INDEX = '''<?php
require '/orku/vendor/autoload.php';
$router = new Orku\\Routing\\Router();
$router->get('/api/status', fn () => Orku\\Http\\Response::json(['framework'=>'benchmark','status'=>'running']));
Orku\\Runtime\\SapiRuntime::run(new Orku\\Core\\Kernel($router));
'''

LARAVEL_INDEX = '''<?php
require '/laravel/vendor/autoload.php';
$app = require_once '/bench/bootstrap/app.php';
$app->handleRequest(Illuminate\\Http\\Request::capture());
'''

LARAVEL_APP_BOOTSTRAP = '''<?php
use Illuminate\\Foundation\\Application;
use Illuminate\\Support\\Facades\\Route;

return Application::configure(basePath: '/bench')
    ->withRouting(using: function () {
        Route::get('/api/status', fn () => response()->json(['framework'=>'benchmark','status'=>'running']));
    })
    ->withMiddleware(function ($middleware) {})
    ->withExceptions(function ($exceptions) {})
    ->create();
'''

POOL_CONF = '[www]\npm = static\npm.max_children = 4\nclear_env = no\n'

NGINX_CONF = '''server {
listen 80; server_name localhost; root /bench/public; index index.php;
location / { try_files $uri /index.php$is_args$args; }
location = /index.php {
include fastcgi_params;
fastcgi_param SCRIPT_FILENAME /bench/public/index.php;
fastcgi_pass php:9000;
}
location ~ \\.php { return 404; }
}
'''

def scaffold_laravel(app):
    for d in ['bootstrap/cache', 'storage/framework/views', 'storage/framework/cache',
              'storage/framework/sessions', 'storage/logs', 'public', 'config', 'routes']:
        (app / d).mkdir(parents=True, exist_ok=True)
    (app / 'public/index.php').write_text(LARAVEL_INDEX)
    (app / 'bootstrap/app.php').write_text(LARAVEL_APP_BOOTSTRAP)
    (app / 'config/app.php').write_text('''<?php
return [
    'name' => 'Benchmark',
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'key' => env('APP_KEY'),
    'timezone' => 'UTC',
    'locale' => 'en',
    'fallback_locale' => 'en',
    'cipher' => 'AES-256-CBC',
    'maintenance' => ['driver' => 'file'],
];
''')


def scaffold_orku(app):
    (app / 'public').mkdir(parents=True)
    (app / 'public/index.php').write_text(ORKU_INDEX)


def artisan_cache(container):
    """Build services manifest, route cache publicly, then config cache."""
    for cmd in ['route:cache', 'config:cache']:
        out = subprocess.check_output([
            'docker', 'exec', container, 'php', '-r',
            "require '/laravel/vendor/autoload.php';"
            "$app = require '/bench/bootstrap/app.php';"
            "$kernel = $app->make(Illuminate\\Contracts\\Console\\Kernel::class);"
            f"$status = $kernel->call('{cmd}');if ($status !== 0) exit($status);echo 'OK';"],
            text=True, stderr=subprocess.STDOUT, timeout=30)
        if 'OK' not in out:
            raise RuntimeError(f'{cmd} failed: {out}')
    cached = subprocess.check_output([
        'docker', 'exec', container, 'ls', '/bench/bootstrap/cache/'],
        text=True, timeout=10).strip()
    print(f'  Laravel cached files: {cached}', file=sys.stderr)


def optimize_autoloader(laravel_dir):
    """Run composer dump-autoload --classmap-authoritative in a Docker container."""
    subprocess.check_call(
        ['composer', 'dump-autoload', '--classmap-authoritative', '--no-dev',
         '--no-plugins', '--no-scripts', '--ignore-platform-req=php'],
        cwd=str(laravel_dir), stdout=subprocess.DEVNULL, timeout=60)


def wait_healthy(host, containers, path='/api/status', retries=100):
    last_status = None
    for _ in range(retries):
        try:
            c = http.client.HTTPConnection(host, timeout=2)
            c.request('GET', path)
            r = c.getresponse()
            body = r.read()
            c.close()
            last_status = r.status
            if r.status == 200:
                assert body == EXPECTED_BODY, body
                return
        except OSError:
            pass
        time.sleep(.1)
    for cid in containers:
        subprocess.run(['docker', 'logs', '--tail', '20', cid], stderr=subprocess.STDOUT)
    raise RuntimeError(f'App unavailable after retries (last status: {last_status})')


def warmup_opcache(host, count):
    """Sequential warmup to fill OPcache."""
    for _ in range(count):
        try:
            c = http.client.HTTPConnection(host, timeout=5)
            c.request('GET', '/api/status')
            c.getresponse().read()
            c.close()
        except OSError:
            pass


def run_framework(root, laravel, work, framework):
    app = work / framework
    if framework == 'laravel':
        scaffold_laravel(app)
    else:
        scaffold_orku(app)

    pool = work / f'{framework}-pool.conf'
    pool.write_text(POOL_CONF)
    nginx_conf = work / f'{framework}-nginx.conf'
    nginx_conf.write_text(NGINX_CONF)

    network = f'orku-opt-{framework}-' + uuid.uuid4().hex[:12]
    containers = []
    docker('network', 'create', network)
    try:
        common = ['--network', network, '--cpus', '1', '--memory', '256m',
                  '--memory-swap', '256m', '--pids-limit', '128']

        fpm_mounts = [
            '--mount', f'type=bind,src={app},dst=/bench',
            '--mount', f'type=bind,src={root},dst=/orku,readonly',
            '--mount', f'type=bind,src={laravel},dst=/laravel,readonly',
            '--mount', f'type=bind,src={pool},dst=/usr/local/etc/php-fpm.d/zz-benchmark.conf,readonly',
        ]

        opcache_args = [
            '-d', 'opcache.enable=1',
            '-d', 'opcache.validate_timestamps=0',
            '-d', 'opcache.memory_consumption=128',
            '-d', 'opcache.max_accelerated_files=20000',
            '-d', 'opcache.interned_strings_buffer=16',
            '-d', 'opcache.jit=disable',
            '-d', 'display_errors=0',
            '-d', 'expose_php=0',
        ]

        containers.append(docker(
            'run', '--detach', '--rm', *common, '--network-alias', 'php',
            *fpm_mounts,
            '--env', 'APP_ENV=production', '--env', 'APP_DEBUG=false',
            '--env', 'APP_KEY=base64:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa=',
            '--env', 'LOG_CHANNEL=stderr',
            'php:8.4.26-fpm', 'php-fpm', '-F', *opcache_args))

        containers.append(docker(
            'run', '--detach', '--rm', *common,
            '--mount', f'type=bind,src={app},dst=/bench,readonly',
            '--publish', '127.0.0.1::80',
            '--mount', f'type=bind,src={nginx_conf},dst=/etc/nginx/conf.d/default.conf,readonly',
            'nginx:1.28-alpine'))

        host = docker('port', containers[-1], '80/tcp')

        if framework == 'laravel':
            artisan_cache(containers[0])
            # Restart so every FPM worker starts with fully cached config/routes.
            docker('restart', '--time', '5', containers[0])
            docker('exec', containers[0], 'php-fpm', '-t')

        wait_healthy(host, containers)

        warmup_opcache(host, WARMUP)
        time.sleep(1)

        config = {
            'framework': framework,
            'php': '8.4.26-fpm',
            'nginx': '1.28-alpine',
            'fpm_workers': 4,
            'fpm_pm': 'static',
            'opcache': {'enabled': True, 'validate_timestamps': False,
                        'memory_mb': 128, 'max_files': 20000, 'jit': 'disabled'},
            'container_limits': {'cpus': 1, 'memory_mb': 256, 'pids': 128},
            'client': {'mode': 'keep-alive TCP per worker', 'requests_per_round': REQUESTS},
            'warmup_requests': WARMUP,
            'rounds_per_concurrency': ROUNDS,
            'requests_per_round': REQUESTS,
        }
        if framework == 'laravel':
            config['laravel'] = {
                'version': '12.71.0', 'production_dependencies_only': True,
                'route_cached': True, 'config_cached': True,
                'services_cached': True,
                'autoloader_optimized': True, 'opcache_warm': True,
            }
        else:
            config['orku'] = {'opcache_warm': True}

        results = []
        for concurrency in CONCURRENCY_LEVELS:
            for iteration in range(ROUNDS):
                before = [sample(c) for c in containers]
                started = time.monotonic()
                process = subprocess.run(
                    [sys.executable, str(root / 'benchmarks/http_latency.py'),
                     f'http://{host}/api/status',
                     '--requests', str(REQUESTS), '--warmup', '0',
                     '--concurrency', str(concurrency), '--keep-alive',
                     '--runtime-label', f'{framework}-optimized'],
                    capture_output=True, text=True, timeout=120)
                elapsed = time.monotonic() - started
                after = [sample(c) for c in containers]
                benchmark = json.loads(process.stdout)
                measurements = {
                    name: {**usage_delta(start, end, elapsed), **end}
                    for name, start, end in zip(['fpm', 'nginx'], before, after)}
                result = {
                    'framework': framework, 'concurrency': concurrency,
                    'round': iteration + 1, 'elapsed_seconds': round(elapsed, 4),
                    'benchmark': benchmark, 'containers': measurements}
                results.append(result)
                print(json.dumps(result), flush=True)
                if any(end['oom_kill'] != start['oom_kill']
                       for start, end in zip(before, after)):
                    raise RuntimeError('Container OOM')
                if benchmark['errors']:
                    for name, cid in zip(['fpm', 'nginx'], containers):
                        state = json.loads(subprocess.check_output(
                            ['docker', 'inspect', '--format', '{{json .State}}', cid], text=True))
                        print(f'  {name} state after failed round: {state}', file=sys.stderr)
                        subprocess.run(['docker', 'logs', '--tail', '10', cid], stderr=subprocess.STDOUT)
                    raise RuntimeError(f'{framework} round failed with {benchmark["errors"]} errors')
        return config, results
    finally:
        for c in reversed(containers):
            subprocess.run(['docker', 'stop', '--timeout', '2', c],
                           stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        subprocess.run(['docker', 'network', 'rm', network],
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)


def aggregate(all_results, session_counts=None):
    """Median-of-batches summary per framework and concurrency."""
    import statistics as st
    rows = []
    for fw in ['orku', 'laravel']:
        for c in CONCURRENCY_LEVELS:
            rs = [r for r in all_results if r['framework'] == fw and r['concurrency'] == c]
            if not rs:
                continue
            rows.append({
                'framework': fw, 'concurrency': c, 'batches': len(rs),
                'median_requests_per_second': st.median(
                    r['benchmark']['requests_per_second'] for r in rs),
                'min_requests_per_second': min(
                    r['benchmark']['requests_per_second'] for r in rs),
                'max_requests_per_second': max(
                    r['benchmark']['requests_per_second'] for r in rs),
                'median_p50_ms': st.median(r['benchmark']['p50_ms'] for r in rs),
                'median_p95_ms': st.median(r['benchmark']['p95_ms'] for r in rs),
                'median_p99_ms': st.median(r['benchmark']['p99_ms'] for r in rs),
                'max_p99_ms': max(r['benchmark']['p99_ms'] for r in rs),
                'errors': sum(r['benchmark']['errors'] for r in rs),
                'max_fpm_memory_bytes': max(
                    r['containers']['fpm']['memory_bytes'] for r in rs),
                'median_fpm_cpu_seconds': st.median(
                    r['containers']['fpm']['cpu_seconds'] for r in rs),
            })
    return rows


def summarize(all_results):
    """Print a summary table to stderr."""
    print('\n=== Orku vs Laravel Production-Optimized ===', file=sys.stderr)
    print(f'{"Framework":<12} {"Conc":>4} {"Median RPS":>10} {"P50 ms":>8} '
          f'{"P95 ms":>8} {"P99 ms":>8} {"Errors":>6} {"FPM RAM MiB":>11} '
          f'{"FPM CPU s":>9}', file=sys.stderr)
    print('-' * 90, file=sys.stderr)
    for row in aggregate(all_results):
        print(f'{row["framework"]:<12} {row["concurrency"]:>4} '
              f'{row["median_requests_per_second"]:>10.0f} '
              f'{row["median_p50_ms"]:>8.2f} {row["median_p95_ms"]:>8.2f} '
              f'{row["median_p99_ms"]:>8.2f} {row["errors"]:>6} '
              f'{row["max_fpm_memory_bytes"] / 1048576:>11.2f} '
              f'{row["median_fpm_cpu_seconds"]:>9.3f}', file=sys.stderr)


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--laravel', type=Path, required=True,
                        help='Disposable directory with Laravel Composer dependencies')
    parser.add_argument('--output', type=Path, default=None,
                        help='Write full JSON report to file')
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    laravel = args.laravel.resolve()

    optimize_autoloader(laravel)

    all_configs = []
    all_results = []
    failed = False
    with tempfile.TemporaryDirectory(dir='/tmp/opencode') as directory:
        work = Path(directory)
        for framework in ['orku', 'laravel']:
            config, results = run_framework(root, laravel, work, framework)
            all_configs.append(config)
            all_results.extend(results)
            failed |= any(r['benchmark']['errors'] or r['benchmark']['warmup_errors']
                          for r in results)

    report = {
        'title': 'Ronde 2: Orku vs Laravel 12 production-optimized',
        'generated_at_utc': datetime.now(timezone.utc).isoformat(),
        'orku_revision': subprocess.check_output(
            ['git', 'rev-parse', 'HEAD'], cwd=root, text=True).strip(),
        'host': {'platform': platform.platform(), 'python': platform.python_version(),
                 'cpu_count': os.cpu_count()},
        'laravel_composer_lock_sha256': hashlib.sha256(
            (laravel / 'composer.lock').read_bytes()).hexdigest(),
        'configs': all_configs,
        'results': all_results,
    }

    report['summary'] = aggregate(all_results)
    if args.output:
        args.output.parent.mkdir(parents=True, exist_ok=True)
        args.output.write_text(json.dumps(report, indent=2))
        print(f'Full report: {args.output}', file=sys.stderr)
    summarize(all_results)
    sys.exit(1 if failed else 0)
