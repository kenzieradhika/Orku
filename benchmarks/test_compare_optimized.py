import unittest

from compare_optimized import aggregate


class AggregateTest(unittest.TestCase):
    def test_uses_batch_medians_and_preserves_errors(self):
        def row(rps, p99, errors):
            return {
                'framework': 'orku', 'concurrency': 4,
                'benchmark': {'requests_per_second': rps, 'p50_ms': 1,
                              'p95_ms': 2, 'p99_ms': p99, 'errors': errors},
                'containers': {'fpm': {'memory_bytes': 1048576,
                                       'cpu_seconds': .2}},
            }

        result, = aggregate([row(100, 4, 0), row(200, 8, 1)])
        self.assertEqual(result['median_requests_per_second'], 150)
        self.assertEqual(result['median_p99_ms'], 6)
        self.assertEqual(result['max_p99_ms'], 8)
        self.assertEqual(result['errors'], 1)
        self.assertEqual(result['batches'], 2)


if __name__ == '__main__':
    unittest.main()
