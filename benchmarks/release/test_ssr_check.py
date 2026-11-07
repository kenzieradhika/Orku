import unittest
import subprocess
from pathlib import Path
import os
import tempfile
import json

import ssr_check


class SsrCheckTest(unittest.TestCase):
    def test_runner_report_schema_without_new_measurement(self):
        outputs = {'static': '<p>Release validation</p>', 'utf8': '<p>café</p>',
                   'null': '<p></p>', 'empty': '<p></p>',
                   'nested': '<main><p>inner</p></main>',
                   'escaped': '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>'}
        report = ssr_check.build_report([[1, 2, 3, 4]], [2.5], [5], outputs)
        self.assertEqual(json.loads(json.dumps(report))['renderer_memory_bytes'], 'UNMEASURED')
        self.assertEqual(report['php_allocator_peak_bytes'], 'UNMEASURED')
        self.assertEqual(report['process_rss_bytes'], 'UNMEASURED')

    def test_fixture_cleans_temporary_files_for_cases_render_and_invalid_args(self):
        script = Path(__file__).with_name('ssr_check.php')
        with tempfile.TemporaryDirectory() as tmp:
            env = {**os.environ, 'TMPDIR': tmp}
            for args, expected_code in ((['--cases'], 0), (['--render', '3'], 0),
                                        (['--render', '0'], 2), (['--invalid'], 2)):
                result = subprocess.run(['php', str(script), *args], capture_output=True,
                                        text=True, timeout=10, env=env)
                self.assertEqual(result.returncode, expected_code, result.stderr)
                if args == ['--render', '3']:
                    sample = json.loads(result.stdout)
                    self.assertEqual(sample['renders'], 3)
                    self.assertEqual(len(sample['render_latencies_ns']), 3)
                    self.assertGreaterEqual(sample['timer_overhead_ns'], 0)
                    self.assertGreater(sample['total_elapsed_ns'], 0)
                self.assertEqual(list(Path(tmp).iterdir()), [])

    def test_real_orku_renderer_produces_safe_expected_cases(self):
        script = Path(__file__).with_name('ssr_check.php')
        result = subprocess.run(['php', str(script), '--cases'], capture_output=True,
                               text=True, timeout=10, check=True)
        outputs = __import__('json').loads(result.stdout)
        ssr_check.validate_outputs(outputs)
        self.assertNotIn('<script>', outputs['escaped'])

    def test_output_cases_cover_utf8_null_nested_layout_and_safe_escape(self):
        outputs = ssr_check.validate_outputs({
            'static': '<p>Release validation</p>', 'static_laravel': '<p>Release validation</p>',
            'utf8': '<p>café</p>', 'utf8_laravel': '<p>café</p>',
            'null': '<p></p>', 'null_laravel': '<p></p>',
            'empty': '<p></p>', 'empty_laravel': '<p></p>',
            'nested': '<main><p>inner</p></main>', 'nested_laravel': '<main><p>inner</p></main>',
            'escaped': '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>',
            'escaped_laravel': '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>',
        })
        self.assertEqual(outputs['escaped'], '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>')
        self.assertNotIn('<script>', outputs['escaped'])

    def test_raw_marker_is_only_accepted_as_explicit_unsafe_case(self):
        outputs = {'static': '<p>Release validation</p>', 'static_laravel': '<p>Release validation</p>',
                   'utf8': '<p>café</p>', 'utf8_laravel': '<p>café</p>',
                   'null': '<p></p>', 'null_laravel': '<p></p>',
                   'empty': '<p></p>', 'empty_laravel': '<p></p>',
                   'nested': '<main><p>inner</p></main>', 'nested_laravel': '<main><p>inner</p></main>',
                   'escaped': '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>',
                   'escaped_laravel': '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>',
                   'raw_unsafe': '<script>marker</script>',
                   'raw_unsafe_expected': '<script>marker</script>'}
        ssr_check.validate_outputs(outputs)
        with self.assertRaises(ValueError):
            ssr_check.validate_outputs({'escaped': '<script>marker</script>'})

    def test_rejects_framework_output_mismatch(self):
        with self.assertRaisesRegex(ValueError, 'output mismatch'):
            ssr_check.validate_outputs({'static': '<p>orku</p>', 'static_laravel': '<p>blade</p>'})

    def test_percentiles_use_nearest_rank_and_repeats_are_bounded(self):
        self.assertEqual(ssr_check.percentiles([1, 2, 3, 4, 5]),
                         {'p50': 3, 'p95': 5, 'p99': 5})
        self.assertEqual(ssr_check.percentiles([9]), {'p50': 9, 'p95': 9, 'p99': 9})
        self.assertEqual(ssr_check.percentiles([1, 2, 3, 4]),
                         {'p50': 2, 'p95': 4, 'p99': 4})
        samples = [list(range(start, start + 100)) for start in (0, 100, 200, 300, 400)]
        self.assertEqual(ssr_check.summarize_render_samples(samples),
                         {'sample_count': 500, 'p50': 249, 'p95': 474, 'p99': 494})
        with self.assertRaises(ValueError):
            ssr_check.summarize_render_samples([])
        outputs = {'static': '<p>Release validation</p>', 'utf8': '<p>café</p>',
                   'null': '<p></p>', 'empty': '<p></p>',
                   'nested': '<main><p>inner</p></main>',
                   'escaped': '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>'}
        report = ssr_check.build_report(samples, [10, 20, 30, 40, 50], [1, 2], outputs)
        self.assertEqual(report['per_render_latency_ns']['sample_count'], 500)
        self.assertEqual(report['batch_mean_latency_ns'], {'p50': 30, 'p95': 50, 'p99': 50})
        self.assertEqual(report['renderer_memory_bytes'], 'UNMEASURED')
        self.assertEqual(report['process_rss_bytes'], 'UNMEASURED')
        with self.assertRaises(ValueError):
            ssr_check.validate_repeats(0)
        with self.assertRaises(ValueError):
            ssr_check.validate_repeats(6)


if __name__ == '__main__':
    unittest.main()
