import importlib.util
from pathlib import Path
import unittest

MODULE = Path(__file__).with_name('run_benchmark.py')
SPEC = importlib.util.spec_from_file_location('run_benchmark', MODULE)
runner = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(runner)


class BenchmarkConfigTest(unittest.TestCase):
    def test_only_loopback_http_targets(self):
        for value in ('https://127.0.0.1', 'http://example.com', 'http://[::1]:9000'):
            with self.assertRaises(ValueError):
                runner.validate_target(value)
        self.assertEqual(runner.validate_target('http://127.0.0.1:8080/'),
                         'http://127.0.0.1:8080')
        self.assertEqual(runner.validate_target('http://localhost'), 'http://localhost')
        for value in ('https://127.0.0.1', 'http://user:pass@127.0.0.1',
                      'http://example.com', 'http://127.0.0.1:8080/path',
                      'http://127.0.0.1:8080?x=1'):
            with self.subTest(value=value), self.assertRaises(ValueError):
                runner.validate_target(value)

    def test_request_and_concurrency_caps(self):
        self.assertEqual(runner.validate_config(10, 2), (10, 2))
        for requests, concurrency in ((0, 1), (2001, 1), (10, 0), (10, 65),
                                      (True, 1), (10, True), (1001, 1)):
            with self.subTest(requests=requests, concurrency=concurrency), self.assertRaises(ValueError):
                runner.validate_config(requests, concurrency)

    def test_nearest_rank_percentiles(self):
        values = [4.0, 1.0, 3.0, 2.0]
        self.assertEqual(runner.percentile(values, .50), 2.0)
        self.assertEqual(runner.percentile(values, .95), 4.0)
        self.assertEqual(runner.percentile(values, .99), 4.0)
        self.assertIsNone(runner.percentile([], .50))

    def test_summary_counts_transport_and_unexpected_statuses(self):
        rows = [{'status': 200, 'latency_ms': 3.0, 'error': None},
                {'status': 409, 'latency_ms': 2.0, 'error': None},
                {'status': None, 'latency_ms': 1.0, 'error': 'transport'}]
        summary = runner.summarize(rows, {200})
        self.assertEqual(summary['errors'], 2)
        self.assertEqual(summary['statuses'], {'200': 1, '409': 1, 'transport': 1})
        self.assertAlmostEqual(summary['error_rate'], 2 / 3)
        self.assertEqual(summary['p50_ms'], 2.0)
        self.assertEqual(summary['p95_ms'], 3.0)

    def test_phases_are_deterministic_and_use_unique_create_keys(self):
        phases = {phase['name']: phase for phase in
                  runner.build_phases('tag', [201, 202], [301, 302])}
        self.assertEqual(phases['get']['path'](5), '/api/items/6')
        self.assertEqual(phases['create']['payload'](7)['client_key'], 'tag-c7')
        self.assertEqual(phases['replace']['path'](1), '/api/items/202')
        self.assertEqual(phases['delete']['path'](0), '/api/items/301?version=1')
        self.assertEqual(phases['delete']['expected'], {204})
        self.assertEqual(phases['create']['expected'], {201})

    def test_resource_sampling_is_explicitly_unmeasured_without_container(self):
        self.assertEqual(runner.container_resources(None)['status'], 'UNMEASURED')
