#!/usr/bin/env python3
import argparse
import os
import shutil
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path

from safety import validate_target

ROOT = Path(__file__).resolve().parent


def main() -> int:
    parser = argparse.ArgumentParser(description='Run a bounded, loopback-only k6 experiment.')
    parser.add_argument('--target', default='http://nginx:8080')
    parser.add_argument('--scenario', choices=('baseline', 'ramp', 'spike', 'recovery'), default='baseline')
    parser.add_argument('--yes-really-local-load', action='store_true')
    args = parser.parse_args()
    try:
        target = validate_target(args.target)
        if target != 'http://nginx:8080':
            raise ValueError('Docker k6 runner requires the internal http://nginx:8080 target.')
    except ValueError as error:
        parser.error(str(error))
    if not args.yes_really_local_load:
        parser.error('Explicit opt-in required: pass --yes-really-local-load.')
    if not shutil.which('docker'):
        parser.error('Docker is required to run k6 on the isolated Compose network.')
    if not (ROOT / 'compose.yaml').is_file() or not (ROOT / 'k6/scenarios.js').is_file():
        parser.error('Lab Compose and scenario fixtures are required.')
    results = ROOT / 'results'
    results.mkdir(exist_ok=True)
    name = datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S.%fZ') + f'-{args.scenario}.json'
    env = os.environ | {
        'LAB_VARIANT': os.environ.get('LAB_VARIANT', 'baseline'),
        'LAB_UID': str(os.getuid()), 'LAB_GID': str(os.getgid()),
        'LAB_TARGET': 'http://nginx:8080', 'SCENARIO': args.scenario,
        'RESULT_FILE': f'/results/{name}',
    }
    command = ['docker', 'compose', '-f', str(ROOT / 'compose.yaml'), 'run', '--rm', '--no-deps', 'k6', 'run', '/scripts/scenarios.js']
    try:
        result = subprocess.run(command, env=env, cwd=ROOT, check=False, timeout=25)
        if result.returncode == 0 and not (results / name).is_file():
            print(f'k6 returned success but did not write {name}', file=sys.stderr)
            return 1
        return result.returncode
    except subprocess.TimeoutExpired:
        print('k6 exceeded hard runner timeout; stopping.', file=sys.stderr)
        return 124


if __name__ == '__main__':
    raise SystemExit(main())
