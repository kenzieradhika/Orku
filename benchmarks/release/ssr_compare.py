"""SSR fixture contract and bounded comparison helpers."""
import argparse
import hashlib
import json
import math
import platform
import subprocess
import statistics
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PINNED_LARAVEL = '12.71.0'
MAX_RENDERS = 1000


def percentiles(values):
    if not values:
        raise ValueError('at least one latency sample is required')
    ordered = sorted(values)
    return {name: ordered[max(0, math.ceil(p * len(ordered)) - 1)]
            for name, p in (('p50', .50), ('p95', .95), ('p99', .99))}


def require_equal(orku, laravel):
    if set(orku) != set(laravel):
        raise ValueError('case names differ')
    for name in orku:
        if orku[name] != laravel[name]:
            raise ValueError(f'output mismatch for {name}')
    return {name: hashlib.sha256(html.encode('utf-8')).hexdigest()
            for name, html in orku.items()}


def validate_laravel_version(version):
    if version != PINNED_LARAVEL:
        raise ValueError(f'Laravel version must be {PINNED_LARAVEL}')
    return version


def build_report(samples, case_hashes, timer_overheads, runs, environment, output_hashes):
    if set(samples) != {'orku', 'laravel'} or set(output_hashes) != {'orku', 'laravel'}:
        raise ValueError('both framework samples and output hashes are required')
    if output_hashes['orku'] != output_hashes['laravel']:
        raise ValueError('output hash mismatch')
    frameworks = {}
    for name, values in samples.items():
        if not values:
            raise ValueError(f'{name} latency samples are empty')
        frameworks[name] = {
            'output_sha256': output_hashes[name], 'raw_render_latencies_ns': values,
            'render_latency_ns': percentiles(values), 'mean_latency_ns': statistics.mean(values),
            'timer_overhead_ns': timer_overheads[name],
            'cpu_user_us': runs[name].get('cpu_user_us', []),
            'cpu_system_us': runs[name].get('cpu_system_us', []),
            'batch_throughput_renders_per_second': runs[name].get('batch_throughput_renders_per_second', []),
            'memory_bytes': 'UNMEASURED',
        }
    return {'mode': 'rendering_only', 'http_timing': 'NOT_MEASURED',
            'verified_case_hashes': case_hashes, 'environment': environment,
            'cache_modes': {'warm': 'NOT_IMPLEMENTED', 'cold-template-cache': 'NOT_IMPLEMENTED'},
            'frameworks': frameworks}


def run_fixture(framework, mode, renders=1, dependency_root=None, docker=False, blade_mode='file'):
    if not 1 <= renders <= MAX_RENDERS:
        raise ValueError(f'renders must be between 1 and {MAX_RENDERS}')
    script = Path(__file__).with_name('ssr_compare.php')
    if docker:
        command = ['docker', 'run', '--rm', '-v', f'{ROOT}:/orku:ro',
                   'orku-laravel-ssr:12.71.0', '--framework', framework,
                   '--mode', mode, '--renders', str(renders), '--blade-mode', blade_mode]
        if framework == 'laravel':
            command.extend(['--dependency-root', '/fixture'])
    else:
        command = ['php', str(script), '--framework', framework, '--mode', mode,
                   '--renders', str(renders), '--blade-mode', blade_mode]
        if dependency_root:
            command.extend(['--dependency-root', str(dependency_root)])
    result = subprocess.run(command, cwd=ROOT, capture_output=True, text=True,
                            timeout=30, check=True)
    return json.loads(result.stdout)


def compare(laravel_root, renders=1000, repeats=5, output=None, docker=False, blade_mode='file'):
    if not 1 <= renders <= MAX_RENDERS:
        raise ValueError(f'renders must be between 1 and {MAX_RENDERS}')
    if not 1 <= repeats <= 5:
        raise ValueError('repeats must be between 1 and 5')
    if not docker and not (Path(laravel_root) / 'composer.lock').is_file():
        raise ValueError('Laravel dependency root must contain composer.lock')
    if blade_mode not in {'file', 'string'}:
        raise ValueError('blade_mode must be file or string')
    orku = run_fixture('orku', 'cases')
    laravel = run_fixture('laravel', 'cases', dependency_root=laravel_root, docker=docker,
                          blade_mode=blade_mode)
    validate_laravel_version(laravel['version'])
    hashes = require_equal(orku['outputs'], laravel['outputs'])
    measured = {}
    for framework, root in (('orku', None), ('laravel', laravel_root)):
        use_docker = docker
        warmup = run_fixture(framework, 'render', min(100, renders), root, use_docker, blade_mode)
        batches = [run_fixture(framework, 'render', renders, root, use_docker, blade_mode)
                   for _ in range(repeats)]
        if len({batch['output_sha256'] for batch in batches}) != 1:
            raise ValueError(f'{framework} render output hash changed between repeats')
        raw = [value for batch in batches for value in batch['render_latencies_ns']]
        elapsed = [batch['total_elapsed_ns'] for batch in batches]
        measured[framework] = {
            'output_sha256': batches[0]['output_sha256'], 'repeats': repeats,
            'warmup_renders': batches[0]['warmup_renders'], 'renders_per_repeat': renders,
            'cold_first_render_ns': [batch['cold_first_render_ns'] for batch in batches],
            'template_cache_state': [batch['cache_state'] for batch in batches],
            'raw_render_latencies_ns': raw,
            'render_latency_ns': percentiles(raw),
            'mean_latency_ns': statistics.mean(raw),
            'batch_throughput_renders_per_second': [renders * 1_000_000_000 / ns for ns in elapsed],
            'timer_overhead_ns': [batch['timer_overhead_ns'] for batch in batches],
            'cpu_user_us': [batch['cpu_user_us'] for batch in batches],
            'cpu_system_us': [batch['cpu_system_us'] for batch in batches],
            'memory_bytes': 'UNMEASURED',
        }
    if measured['orku']['output_sha256'] != measured['laravel']['output_sha256']:
        raise ValueError('render output hash mismatch')
    if docker:
        php_cli = subprocess.run(['docker', 'run', '--rm', '--entrypoint', 'php',
                                  'orku-laravel-ssr:12.71.0', '-v'],
                                 capture_output=True, text=True, check=True).stdout.splitlines()[0]
        composer = subprocess.run(['docker', 'run', '--rm', '--entrypoint', 'composer',
                                   'orku-laravel-ssr:12.71.0', '--version'],
                                  capture_output=True, text=True, check=True).stdout.splitlines()[0]
        image_id = subprocess.run(['docker', 'image', 'inspect', '--format={{.Id}}',
                                   'orku-laravel-ssr:12.71.0'], capture_output=True,
                                  text=True, check=True).stdout.strip()
        lock_hash = hashlib.sha256((Path(__file__).with_name('laravel-ssr') / 'composer.lock').read_bytes()).hexdigest()
    else:
        php_cli = subprocess.run(['php', '-v'], capture_output=True, text=True,
                                 check=True).stdout.splitlines()[0]
        composer = 'UNRECORDED'
        image_id = 'NOT_APPLICABLE'
        lock_hash = hashlib.sha256((Path(laravel_root) / 'composer.lock').read_bytes()).hexdigest()
    report = {'mode': 'rendering_only', 'blade_mode': blade_mode,
              'http_timing': 'NOT_MEASURED',
              'verified_case_hashes': hashes,
              'environment': {'python': platform.python_version(), 'platform': platform.platform(),
                              'machine': platform.machine(), 'php_cli': php_cli,
                              'composer': composer, 'docker_image_id': image_id,
                              'composer_lock_sha256': lock_hash,
                              'orku_revision': subprocess.run(['git', 'rev-parse', 'HEAD'], cwd=ROOT,
                                  capture_output=True, text=True, check=True).stdout.strip(),
                              'laravel_framework': PINNED_LARAVEL},
              'cache_modes': {'warm': 'measured', 'first-render-per-process': 'recorded_separately',
                              'cold-template-cache': 'NOT_IMPLEMENTED'},
              'frameworks': measured}
    if output:
        Path(output).write_text(json.dumps(report, indent=2, ensure_ascii=False) + '\n')
    return report


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--laravel-root', type=Path, required=True)
    parser.add_argument('--renders', type=int, default=MAX_RENDERS)
    parser.add_argument('--repeats', type=int, default=5)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--docker-laravel', action='store_true')
    parser.add_argument('--blade-mode', choices=('file', 'string'), default='file')
    args = parser.parse_args()
    report = compare(args.laravel_root, args.renders, args.repeats, args.output,
                     args.docker_laravel, args.blade_mode)
    print(json.dumps(report, indent=2, ensure_ascii=False))


if __name__ == '__main__':
    main()
