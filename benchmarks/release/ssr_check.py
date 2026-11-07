"""Bounded rendering-only SSR benchmark; HTTP timing is deliberately separate."""
import argparse
import json
import math
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
RENDERS = 1000
REPEATS = 5


def percentiles(values):
    if not values:
        raise ValueError('at least one measurement is required')
    ordered = sorted(values)
    def rank(p):
        return ordered[max(0, math.ceil(p * len(ordered)) - 1)]
    return {'p50': rank(.50), 'p95': rank(.95), 'p99': rank(.99)}


def validate_repeats(repeats):
    if not isinstance(repeats, int) or not 1 <= repeats <= REPEATS:
        raise ValueError(f'repeats must be between 1 and {REPEATS}')


def validate_outputs(outputs):
    expected = {'static': '<p>Release validation</p>', 'utf8': '<p>café</p>',
                'null': '<p></p>', 'empty': '<p></p>',
                'nested': '<main><p>inner</p></main>',
                'escaped': '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>'}
    for case, expected_html in expected.items():
        if outputs.get(case) != expected_html:
            raise ValueError(f'output mismatch for {case}')
        laravel = outputs.get(case + '_laravel')
        if laravel is not None and laravel != expected_html:
            raise ValueError(f'output mismatch for Laravel {case}')
    if '<script>' in outputs['escaped'].lower():
        raise ValueError('escaped marker rendered as executable markup')
    raw, expected = outputs.get('raw_unsafe'), outputs.get('raw_unsafe_expected')
    if raw is not None and (expected is None or raw != expected or '<script>' not in raw.lower()):
        raise ValueError('raw output requires an explicit matching unsafe expectation')
    return outputs


def _run(command, timeout=15):
    result = subprocess.run(command, cwd=ROOT, capture_output=True, text=True,
                            timeout=timeout, check=True)
    return json.loads(result.stdout)


def run(output, repeats=REPEATS, include_laravel=False):
    validate_repeats(repeats)
    orku_script = Path(__file__).with_name('ssr_check.php')
    cases = _run(['php', str(orku_script), '--cases'])
    outputs = {'static': cases['static'], 'utf8': cases['utf8'], 'null': cases['null'],
               'empty': cases['empty'], 'nested': cases['nested'], 'escaped': cases['escaped'],
               'raw_unsafe': cases['raw_unsafe'], 'raw_unsafe_expected': cases['raw_unsafe_expected']}
    if include_laravel:
        raise ValueError('Laravel baseline unavailable; run requires a separately pinned fixture')
    latencies = []
    peaks = []
    for _ in range(repeats):
        sample = _run(['php', str(orku_script), '--render', str(RENDERS)], timeout=30)
        latencies.append(sample['elapsed_ns'] / RENDERS)
        peaks.append(sample['peak_memory_bytes'])
    report = {'mode': 'rendering_only', 'http_timing': 'not_measured', 'framework': 'Orku',
              'iterations_per_repeat': RENDERS, 'repeats': repeats,
              'warmup_renders': 100, 'cache_policy': 'ViewRenderer path cache; PHP OPcache unchanged',
              'latency_ns_per_render': percentiles(latencies),
              'php_peak_memory_delta_bytes': max(peaks),
              'outputs': validate_outputs(outputs), 'laravel': 'not_available'}
    output.write_text(json.dumps(report, indent=2, ensure_ascii=False) + '\n')
    return report


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--repeats', type=int, default=REPEATS)
    parser.add_argument('--include-laravel', action='store_true')
    args = parser.parse_args()
    print(json.dumps(run(args.output, args.repeats, args.include_laravel), indent=2, ensure_ascii=False))


if __name__ == '__main__':
    main()
