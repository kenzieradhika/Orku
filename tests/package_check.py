#!/usr/bin/env python3
"""Verify a Git distribution in a disposable Composer consumer (Docker/PHP 8.4)."""
import json
import argparse
from pathlib import Path
import subprocess
import tarfile
import tempfile


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--ref', default='HEAD', help='Git commit/tree to package (default: HEAD)')
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    with tempfile.TemporaryDirectory(dir='/tmp/opencode' if Path('/tmp/opencode').is_dir() else None) as directory:
        work = Path(directory)
        archive = work / 'orku.tar'
        subprocess.run(['git', 'archive', '--worktree-attributes', '--format=tar',
                        f'--output={archive}', args.ref], cwd=root, check=True)
        package = work / 'package'
        package.mkdir()
        with tarfile.open(archive) as source:
            source.extractall(package, filter='data')
        for excluded in ['tests', 'benchmarks', '.github', 'vendor', 'composer.lock']:
            assert not (package / excluded).exists(), f'Dev artifact shipped: {excluded}'
        consumer = work / 'consumer'
        consumer.mkdir()
        (consumer / 'composer.json').write_text(json.dumps({
            'require': {'orku/framework': '@dev'},
            'repositories': [{'type': 'path', 'url': '../package', 'options': {'symlink': False}}],
            'config': {'allow-plugins': False},
        }))
        subprocess.run(['composer', 'install', '--no-dev', '--no-plugins', '--no-scripts',
                        '--no-interaction', '--ignore-platform-req=php'], cwd=consumer, check=True)
        (consumer / 'check.php').write_text('''<?php
require __DIR__.'/vendor/autoload.php';
$router = new Orku\\Routing\\Router();
$router->get('/hello/{name}', fn (Orku\\Http\\Request $request) => new Orku\\Http\\Response('Hello, '.$request->routeParameters['name']));
$response = (new Orku\\Core\\Kernel($router))->handle(new Orku\\Http\\Request('GET', '/hello/Ada'));
if ($response->status !== 200 || $response->body !== 'Hello, Ada') { throw new RuntimeException('Consumer dispatch failed.'); }
if (class_exists('PHPUnit\\Framework\\TestCase')) { throw new RuntimeException('Dev dependency installed.'); }
echo "Consumer dispatch passed.\\n";
''')
        image = 'php:8.4.26-apache'
        command = ['docker', 'run', '--rm', '--network', 'none',
                   '--mount', f'type=bind,src={work},dst=/check,readonly',
                   '-w', '/check/consumer', image, 'php']
        subprocess.run([*command, 'check.php'], check=True)
        subprocess.run([*command, 'vendor/bin/orku', 'help'], check=True)
        print(json.dumps({'status': 'passed', 'runtime': image,
                          'checks': ['Git archive', 'Composer consumer', 'no dev dependencies', 'dispatch', 'CLI']}))


if __name__ == '__main__':
    main()
