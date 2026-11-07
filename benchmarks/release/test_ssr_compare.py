import unittest
import subprocess
import json
import tempfile
import os
from pathlib import Path

import ssr_compare


class SsrCompareTest(unittest.TestCase):
    def test_disposable_laravel_fixture_pins_framework_and_php(self):
        fixture = Path(__file__).with_name('laravel-ssr')
        manifest = json.loads((fixture / 'composer.json').read_text())
        self.assertEqual(manifest['require']['laravel/framework'], '12.71.0')
        self.assertEqual(manifest['config']['platform']['php'], '8.4.0')
        lock = json.loads((fixture / 'composer.lock').read_text())
        package = next(p for p in lock['packages'] if p['name'] == 'laravel/framework')
        self.assertEqual(package['version'], 'v12.71.0')
        self.assertTrue((fixture / 'Dockerfile').is_file())
        validate = subprocess.run(['composer', 'validate', '--no-check-publish'],
                                  cwd=fixture, capture_output=True, text=True, timeout=30)
        self.assertEqual(validate.returncode, 0, validate.stdout + validate.stderr)

    def test_orku_fixture_cases_and_bounded_render(self):
        script = Path(__file__).with_name('ssr_compare.php')
        with tempfile.TemporaryDirectory() as tmp:
            env = {**os.environ, 'TMPDIR': tmp}
            cases = subprocess.run(['php', str(script), '--framework', 'orku', '--mode', 'cases'],
                                   capture_output=True, text=True, timeout=10, env=env)
            self.assertEqual(cases.returncode, 0, cases.stderr)
            output = json.loads(cases.stdout)
            self.assertEqual(output['framework'], 'orku')
            self.assertEqual(output['blade_mode'], 'n/a')
            self.assertEqual(output['outputs']['utf8'], '<p>café 東京</p>')
            self.assertEqual(output['outputs']['escaped'], '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>')
            self.assertEqual(output['outputs']['collection_large'].count('<li>'), 500)
            self.assertEqual(output['outputs']['layout_partial'], '<main><header><h1>Latest</h1></header><article>News<b>new</b></article></main>')
            rendered = subprocess.run(['php', str(script), '--framework', 'orku', '--mode', 'render',
                                        '--renders', '3'], capture_output=True, text=True,
                                       timeout=10, env=env)
            self.assertEqual(rendered.returncode, 0, rendered.stderr)
            render_report = json.loads(rendered.stdout)
            self.assertEqual(len(render_report['render_latencies_ns']), 3)
            self.assertGreaterEqual(render_report['cold_first_render_ns'], 0)
            self.assertEqual(render_report['warmup_renders'], 100)
            self.assertEqual(render_report['cache_state'], 'ViewRenderer_path_cache_per_process')
            self.assertEqual(render_report['blade_mode'], 'n/a')
            self.assertIn('cpu_user_us', render_report)
            self.assertIn('cpu_system_us', render_report)
            self.assertEqual(list(Path(tmp).iterdir()), [])

    def test_blade_modes_are_explicitly_separate(self):
        script = Path(__file__).with_name('ssr_compare.php')
        for mode, label in (('file', 'file'), ('string', 'string')):
            result = subprocess.run(['php', str(script), '--framework', 'laravel', '--mode', 'cases',
                                     '--blade-mode', mode, '--dependency-root', '/missing'],
                                    capture_output=True, text=True, timeout=10)
            self.assertNotEqual(result.returncode, 0)
            self.assertIn('Laravel 12.71.0', result.stderr)
        invalid = subprocess.run(['php', str(script), '--framework', 'orku', '--mode', 'cases',
                                  '--blade-mode', 'other'], capture_output=True, text=True, timeout=10)
        self.assertEqual(invalid.returncode, 2)

    def test_rejects_missing_or_wrong_laravel_version(self):
        script = Path(__file__).with_name('ssr_compare.php')
        result = subprocess.run(['php', str(script), '--framework', 'laravel', '--mode', 'cases',
                                 '--dependency-root', '/missing'], capture_output=True, text=True,
                               timeout=10)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('Laravel 12.71.0', result.stderr)

    def test_fixture_rejects_unpinned_lockfile(self):
        script = Path(__file__).with_name('ssr_compare.php')
        with tempfile.TemporaryDirectory() as tmp:
            Path(tmp, 'composer.lock').write_text(json.dumps({'packages': [
                {'name': 'laravel/framework', 'version': 'v12.70.0'}]}))
            Path(tmp, 'vendor').mkdir()
            result = subprocess.run(['php', str(script), '--framework', 'laravel', '--mode', 'cases',
                                     '--dependency-root', tmp], capture_output=True, text=True, timeout=10)
            self.assertEqual(result.returncode, 3)
            self.assertIn('version must be 12.71.0', result.stderr)

    def test_rejects_unpinned_root_before_fixture_execution(self):
        with tempfile.TemporaryDirectory() as tmp:
            with self.assertRaisesRegex(ValueError, 'composer.lock'):
                ssr_compare.compare(tmp, 1)

    def test_render_cap(self):
        with self.assertRaisesRegex(ValueError, 'between 1 and 1000'):
            ssr_compare.run_fixture('orku', 'render', 1001)

    def test_repeats_are_bounded(self):
        with self.assertRaisesRegex(ValueError, 'between 1 and 5'):
            ssr_compare.compare('/missing', repeats=6)

    def test_report_schema_includes_samples_percentiles_modes_environment_resources(self):
        report = ssr_compare.build_report(
            {'orku': [1, 2, 3], 'laravel': [2, 3, 4]},
            {'static': 'a' * 64}, {'orku': [10], 'laravel': [10]},
            {'orku': {'cpu_user_us': [1], 'cpu_system_us': [1],
                      'batch_throughput_renders_per_second': [100]},
             'laravel': {'cpu_user_us': [2], 'cpu_system_us': [1],
                         'batch_throughput_renders_per_second': [90]}},
            {'python': '3', 'php_cli': '8.4'},
            {'orku': 'b' * 64, 'laravel': 'b' * 64})
        self.assertEqual(set(report), {'mode', 'http_timing', 'verified_case_hashes',
                                      'environment', 'cache_modes', 'frameworks'})
        self.assertEqual(report['frameworks']['orku']['render_latency_ns'],
                         {'p50': 2, 'p95': 3, 'p99': 3})
        self.assertEqual(report['frameworks']['laravel']['raw_render_latencies_ns'], [2, 3, 4])
        self.assertEqual(report['frameworks']['orku']['memory_bytes'], 'UNMEASURED')
        self.assertEqual(report['cache_modes']['cold-template-cache'], 'NOT_IMPLEMENTED')
        with self.assertRaisesRegex(ValueError, 'output hash mismatch'):
            ssr_compare.build_report({'orku': [1], 'laravel': [1]},
                                     {'static': 'a' * 64}, {'orku': [1], 'laravel': [1]},
                                     {'orku': {}, 'laravel': {}}, {'php_cli': '8.4'},
                                     {'orku': 'a', 'laravel': 'b'})

    def test_report_builder_preserves_raw_samples_and_explicit_unknowns(self):
        report = ssr_compare.build_report({'orku': [1, 2, 3], 'laravel': [2, 3, 4]},
                                          {'static': 'a' * 64}, {'orku': [10], 'laravel': [10]},
                                          {'orku': {'cpu_user_us': [1]}, 'laravel': {'cpu_user_us': [2]}},
                                          {'python': '3', 'php_cli': '8.4'},
                                          {'orku': 'b' * 64, 'laravel': 'b' * 64})
        self.assertEqual(report['frameworks']['orku']['render_latency_ns'],
                         {'p50': 2, 'p95': 3, 'p99': 3})
        self.assertEqual(report['frameworks']['laravel']['raw_render_latencies_ns'], [2, 3, 4])
        self.assertEqual(report['frameworks']['orku']['memory_bytes'], 'UNMEASURED')
        self.assertEqual(report['cache_modes']['cold-template-cache'], 'NOT_IMPLEMENTED')
        with self.assertRaisesRegex(ValueError, 'output hash mismatch'):
            ssr_compare.build_report({'orku': [1], 'laravel': [1]},
                                     {'static': 'a' * 64}, {'orku': [], 'laravel': []},
                                     {'orku': {}, 'laravel': {}}, {'php_cli': '8.4'},
                                     {'orku': 'a', 'laravel': 'b'})

    def test_nearest_rank_percentiles(self):
        self.assertEqual(ssr_compare.percentiles([1, 2, 3, 4, 5]),
                         {'p50': 3, 'p95': 5, 'p99': 5})

    def test_rejects_empty_latency_samples(self):
        with self.assertRaisesRegex(ValueError, 'at least one'):
            ssr_compare.percentiles([])

    def test_rejects_output_mismatch_before_rendering(self):
        with self.assertRaisesRegex(ValueError, 'output mismatch'):
            ssr_compare.require_equal({'a': '<p>Orku</p>'}, {'a': '<p>Blade</p>'})

    def test_requires_identical_case_names(self):
        with self.assertRaisesRegex(ValueError, 'case names'):
            ssr_compare.require_equal({'a': 'a'}, {'b': 'a'})

    def test_laravel_identity_must_be_pinned(self):
        with self.assertRaisesRegex(ValueError, '12.71.0'):
            ssr_compare.validate_laravel_version('12.72.0')
        self.assertEqual(ssr_compare.validate_laravel_version('12.71.0'), '12.71.0')


if __name__ == '__main__':
    unittest.main()
