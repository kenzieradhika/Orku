#!/usr/bin/env python3
import argparse
import os
import shutil
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path

from safety import validate_config, validate_target

ROOT = Path(__file__).resolve().parent


def main() -> int:
    parser = argparse.ArgumentParser(description='Run a bounded, loopback-only k6 experiment.')
    parser.add_argument('--target', default='http://127.0.0.1:18080')
    parser.add_argument('--scenario', choices=('baseline', 'ramp', 'spike', 'recovery'), default='baseline')
    parser.add_argument('--duration', type=int, default=12)
    parser.add_argument('--yes-really-local-load', action='store_true')
    args = parser.parse_args()
    try:
        target = validate_target(args.target)
        validate_config({'duration_seconds': args.duration})
    except ValueError as error:
        parser.error(str(error))
    if not args.yes_really_local_load:
        parser.error('Explicit opt-in required: pass --yes-really-local-load.')
    k6 = shutil.which('k6')
    if not k6:
        parser.error('k6 not found; install Grafana k6 first.')
    results = ROOT / 'results'
    results.mkdir(exist_ok=True)
    name = datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S.%fZ') + f'-{args.scenario}.json'
    env = os.environ | {'LAB_TARGET': target, 'SCENARIO': args.scenario, 'MAX_DURATION': f'{args.duration}s'}
    command = [k6, 'run', '--summary-export', str(results / name), str(ROOT / 'k6/scenarios.js')]
    try:
        return subprocess.run(command, env=env, cwd=ROOT, check=False, timeout=args.duration + 10).returncode
    except subprocess.TimeoutExpired:
        print('k6 exceeded hard runner timeout; stopping.', file=sys.stderr)
        return 124


if __name__ == '__main__':
    raise SystemExit(main())
