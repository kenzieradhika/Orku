import unittest

from resource_check import usage_delta


class ResourceTest(unittest.TestCase):
    def test_cpu_is_one_core_percent_not_host_percent(self):
        result = usage_delta({'cpu_usec': 100, 'memory_bytes': 1024},
                             {'cpu_usec': 500100, 'memory_bytes': 2048}, 2)
        self.assertEqual(result['cpu_seconds'], .5)
        self.assertEqual(result['cpu_one_core_percent'], 25)
        self.assertEqual(result['memory_growth_bytes'], 1024)

    def test_invalid_measurements_are_not_reported_as_zero(self):
        for elapsed, cpu in [(0, 200), (1, 99)]:
            with self.assertRaises(ValueError):
                usage_delta({'cpu_usec': 100, 'memory_bytes': 0},
                            {'cpu_usec': cpu, 'memory_bytes': 0}, elapsed)


if __name__ == '__main__':
    unittest.main()
