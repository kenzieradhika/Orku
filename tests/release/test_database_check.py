import importlib.util
from pathlib import Path
import shutil
import tempfile
import unittest
from unittest.mock import patch


MODULE = Path(__file__).with_name('database_check.py')
SPEC = importlib.util.spec_from_file_location('database_check', MODULE)
database_check = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(database_check)


class DatabaseCheckTest(unittest.TestCase):
    def test_iteration_limits(self):
        for value in (1, 10, 1000):
            self.assertEqual(database_check.validate_iterations(value), value)
        for value in (0, -1, 1001, True, '4'):
            with self.subTest(value=value), self.assertRaises(ValueError):
                database_check.validate_iterations(value)

    def test_summarizes_durations_and_preserves_failures(self):
        summary = database_check.summarize([
            {'case': 'lookup', 'duration_ms': 1.0, 'ok': True},
            {'case': 'lookup', 'duration_ms': 2.0, 'ok': True},
            {'case': 'rollback', 'duration_ms': 3.0, 'ok': False},
        ])
        lookup = summary['cases']['lookup']
        self.assertEqual(lookup['operations'], 2)
        self.assertEqual(lookup['p50_ms'], 1.0)
        self.assertEqual(lookup['p95_ms'], 2.0)
        self.assertEqual(summary['errors'], 1)

    @unittest.skipUnless(shutil.which('php'), 'PHP CLI required')
    def test_php_fixture_verifies_seed_commit_rollback_and_cleanup(self):
        if not database_check.sqlite_available():
            self.skipTest('pdo_sqlite unavailable')
        with tempfile.TemporaryDirectory(prefix='orku-db-test-') as tmp:
            report = database_check.run_php(Path(tmp) / 'fixture.sqlite', 5)
            self.assertEqual(report['driver'], 'sqlite')
            self.assertEqual(report['seed_rows'], 100)
            self.assertTrue(report['rollback_verified'])
            self.assertTrue(report['commit_verified'])
            self.assertEqual(report['errors'], 0)
            self.assertEqual(report['summary']['point_lookup_orku']['operations'], 5)
            self.assertEqual(report['summary']['bounded_list_orku']['operations'], 5)
            self.assertEqual(report['summary']['transaction_cycle_orku']['operations'], 5)
            self.assertGreaterEqual(len(report['operations']), 5)
            self.assertFalse((Path(tmp) / 'fixture.sqlite').exists())

    def test_timeout_cleans_temporary_directory(self):
        with tempfile.TemporaryDirectory(prefix='orku-db-parent-') as parent:
            def timeout_after_db_created(command, **kwargs):
                Path(command[2]).write_bytes(b'disposable')
                raise database_check.subprocess.TimeoutExpired(command, kwargs['timeout'])

            with patch.object(database_check.subprocess, 'run', side_effect=timeout_after_db_created):
                with self.assertRaises(database_check.subprocess.TimeoutExpired):
                    database_check.run_php(Path('fixture.sqlite'), 5, temp_root=parent)
            self.assertEqual(list(Path(parent).iterdir()), [])


if __name__ == '__main__':
    unittest.main()
