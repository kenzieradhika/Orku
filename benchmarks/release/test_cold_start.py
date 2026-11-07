import importlib.util
from pathlib import Path
import unittest


MODULE = Path(__file__).with_name('cold_start.py')
SPEC = importlib.util.spec_from_file_location('cold_start', MODULE)
cold = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(cold)


class ColdStartTest(unittest.TestCase):
    def test_separates_process_readiness_first_request_and_warm_request(self):
        sample = {'process_ready_ms': 10, 'first_request_ms': 20, 'warm_request_ms': 2,
                  'ready': True, 'error': None}
        summary = cold.summarize([sample])
        self.assertEqual(summary['process_ready']['p50_ms'], 10)
        self.assertEqual(summary['first_request']['p50_ms'], 20)
        self.assertEqual(summary['warm_request']['p50_ms'], 2)

    def test_readiness_deadline_and_sample_count_are_bounded(self):
        self.assertEqual(cold.validate_config(20, 30), (20, 30))
        for samples, timeout in [(0, 30), (21, 30), (20, 31), (True, 30)]:
            with self.subTest(samples=samples, timeout=timeout), self.assertRaises(ValueError):
                cold.validate_config(samples, timeout)

    def test_preserves_failed_starts_in_sample_summary(self):
        samples = [{'process_ready_ms': None, 'first_request_ms': None,
                    'warm_request_ms': None, 'ready': False, 'error': 'deadline'},
                   {'process_ready_ms': 10, 'first_request_ms': 20,
                    'warm_request_ms': 2, 'ready': True, 'error': None}]
        summary = cold.summarize(samples)
        self.assertEqual(summary['samples'], 2)
        self.assertEqual(summary['failed_samples'], 1)
        self.assertEqual(summary['ready_samples'], 1)


if __name__ == '__main__':
    unittest.main()
