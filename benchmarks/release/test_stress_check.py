import importlib.util
from pathlib import Path
import unittest


MODULE = Path(__file__).with_name('stress_check.py')
SPEC = importlib.util.spec_from_file_location('stress_check', MODULE)
stress = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(stress)


class StressCheckTest(unittest.TestCase):
    def test_levels_are_fixed_and_ordered(self):
        self.assertEqual(stress.validate_levels(stress.DEFAULT_LEVELS), [4, 16, 32, 64, 128])
        for levels in ([4, 32, 16], [4, 4], [0, 4], [129], []):
            with self.subTest(levels=levels), self.assertRaises(ValueError):
                stress.validate_levels(levels)

    def test_hard_duration_and_request_caps(self):
        self.assertEqual(stress.validate_window(60, 10000), (60, 10000))
        for duration, requests in [(61, 1), (1, 10001), (0, 1), (True, 1)]:
            with self.subTest(duration=duration, requests=requests), self.assertRaises(ValueError):
                stress.validate_window(duration, requests)

    def test_breaking_point_requires_two_consecutive_bad_windows(self):
        windows = [
            {'concurrency': 4, 'error_rate': 0, 'resource_cap': False},
            {'concurrency': 16, 'error_rate': 0, 'resource_cap': False},
            {'concurrency': 32, 'error_rate': 0, 'resource_cap': False},
            {'concurrency': 64, 'error_rate': .02, 'resource_cap': False},
            {'concurrency': 128, 'error_rate': .03, 'resource_cap': False},
        ]
        self.assertEqual(stress.find_breaking_point(windows), {'last_stable': 32, 'breaking': 64})


if __name__ == '__main__':
    unittest.main()
