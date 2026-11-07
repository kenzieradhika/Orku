import importlib.util
from pathlib import Path
import unittest


MODULE = Path(__file__).with_name('spike_check.py')
SPEC = importlib.util.spec_from_file_location('spike_check', MODULE)
spike = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(spike)


class SpikeCheckTest(unittest.TestCase):
    def test_phase_sequence_and_caps(self):
        self.assertEqual(spike.validate_phases(2, 10, 128, 600),
                         {'baseline_rps': 2, 'spike_seconds': 10,
                          'peak_concurrency': 128, 'recovery_seconds': 600})
        for args in [(0, 10, 128, 600), (2, 11, 10, 600), (2, 10, 129, 600), (2, 10, 10, 601)]:
            with self.subTest(args=args), self.assertRaises(ValueError):
                spike.validate_phases(*args)

    def test_recovery_threshold_and_time_to_baseline(self):
        windows = [{'elapsed': 0, 'phase': 'baseline', 'p95_ms': 10, 'errors': 0},
                   {'elapsed': 10, 'phase': 'spike', 'p95_ms': 40, 'errors': 2},
                   {'elapsed': 70, 'phase': 'recovery', 'p95_ms': 15, 'errors': 0}]
        self.assertEqual(spike.recovery_result(windows), {'recovered': True, 'time_to_baseline_seconds': 70})
        bad = windows + [{'elapsed': 130, 'phase': 'recovery', 'p95_ms': 30, 'errors': 1},
                         {'elapsed': 200, 'phase': 'recovery', 'p95_ms': 30, 'errors': 1}]
        self.assertFalse(spike.recovery_result(bad)['recovered'])


if __name__ == '__main__':
    unittest.main()
