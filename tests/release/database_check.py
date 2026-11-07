"""Disposable, bounded PDO SQLite correctness and microbenchmark check."""
from collections import defaultdict
import json
import math
from pathlib import Path
import subprocess
import tempfile
import time

MAX_ITERATIONS = 1000


def validate_iterations(iterations):
    if type(iterations) is not int or not 1 <= iterations <= MAX_ITERATIONS:
        raise ValueError(f'iterations must be an integer from 1 to {MAX_ITERATIONS}')
    return iterations


def summarize(rows):
    cases = defaultdict(list)
    errors = 0
    for row in rows:
        cases[row['case']].append(row['duration_ms'])
        errors += not row['ok']
    summary = {}
    for name, durations in cases.items():
        durations.sort()
        summary[name] = {
            'operations': len(durations),
            'p50_ms': durations[math.ceil(.50 * len(durations)) - 1],
            'p95_ms': durations[math.ceil(.95 * len(durations)) - 1],
            'p99_ms': durations[math.ceil(.99 * len(durations)) - 1],
        }
    return {'cases': summary, 'errors': errors}


def sqlite_available():
    result = subprocess.run(['php', '-r', 'exit(in_array("sqlite", PDO::getAvailableDrivers(), true) ? 0 : 1);'])
    return result.returncode == 0


def run_php(database_path, iterations, temp_root=None):
    validate_iterations(iterations)
    script = Path(__file__).with_name('database_fixture.php')
    with tempfile.TemporaryDirectory(prefix='orku-db-run-', dir=temp_root) as directory:
        db = Path(directory) / database_path.name
        result = subprocess.run(['php', str(script), str(db), str(iterations)],
                               capture_output=True, text=True, timeout=30)
        if result.returncode:
            raise RuntimeError(result.stderr or result.stdout)
        report = json.loads(result.stdout)
        if db.exists():
            raise AssertionError('Disposable database was not removed.')
        return report


def main():
    import argparse
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--iterations', type=int, default=100)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    validate_iterations(args.iterations)
    started = time.perf_counter()
    report = run_php(Path('orku.sqlite'), args.iterations,
                     temp_root='/tmp/opencode' if Path('/tmp/opencode').is_dir() else None)
    report['duration_seconds'] = time.perf_counter() - started
    report['source_revision'] = subprocess.check_output(['git', 'rev-parse', 'HEAD'], text=True).strip()
    report['runtime'] = subprocess.check_output(['php', '-v'], text=True).splitlines()[0]
    report['limitations'] = ['SQLite/PDO only; not evidence for MySQL/PostgreSQL.',
                             'Synthetic fixture data; no application migration is run.']
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(report, indent=2) + '\n')
    return int(report['errors'] > 0)


if __name__ == '__main__':
    raise SystemExit(main())
