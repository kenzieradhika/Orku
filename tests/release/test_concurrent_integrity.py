import json
from pathlib import Path
import shutil
import subprocess
import unittest


@unittest.skipUnless(shutil.which('php'), 'PHP CLI required')
class ConcurrentIntegrityTest(unittest.TestCase):
    def test_parallel_transactions_have_exact_state_and_clean_exit(self):
        result = subprocess.run(['php', str(Path(__file__).with_name('concurrent_integrity_check.php')),
                                 '200', '8'], capture_output=True, text=True, timeout=20)
        self.assertEqual(result.returncode, 0, result.stderr)
        report = json.loads(result.stdout)
        self.assertTrue(report['integrity_passed'], report)
        self.assertEqual(report['counter_value'], 200)
        self.assertEqual(report['event_count'], 200)
        self.assertEqual(report['worker_failures'], 0)
        self.assertTrue(report['rollback_verified'])

    def test_caps_invalid_operations(self):
        result = subprocess.run(['php', str(Path(__file__).with_name('concurrent_integrity_check.php')),
                                 '10001', '1'], capture_output=True, text=True, timeout=3)
        self.assertEqual(result.returncode, 2)


if __name__ == '__main__':
    unittest.main()
