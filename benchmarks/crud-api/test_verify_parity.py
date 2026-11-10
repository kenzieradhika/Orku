import importlib.util
from pathlib import Path
import unittest

MODULE = Path(__file__).with_name('verify_parity.py')
SPEC = importlib.util.spec_from_file_location('verify_parity', MODULE)
parity = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(parity)


class ParityTest(unittest.TestCase):
    def test_only_loopback_http_urls(self):
        self.assertEqual(parity.validate_url('http://127.0.0.1:8080'),
                         'http://127.0.0.1:8080')
        for value in ('https://127.0.0.1', 'http://user:pass@127.0.0.1',
                      'http://example.com', 'http://127.0.0.1/path',
                      'http://127.0.0.1?x=1'):
            with self.subTest(value=value), self.assertRaises(ValueError):
                parity.validate_url(value)

    def test_scenario_covers_crud_conflict_and_rollback(self):
        names = [name for name, *_ in parity.scenario()]
        for required in ('list', 'get', 'get-missing', 'create', 'create-duplicate',
                         'create-invalid', 'update', 'update-stale', 'delete-stale',
                         'delete', 'get-deleted', 'rollback', 'rollback-state'):
            self.assertIn(required, names)
        self.assertEqual(len(names), len(set(names)))

    def test_scenario_uses_one_created_key_for_duplicate_probe(self):
        entries = {name: (method, path, payload) for name, method, path, payload in parity.scenario()}
        self.assertEqual(entries['create'][2]['client_key'], 'parity-1')
        self.assertEqual(entries['create-duplicate'][2]['client_key'], 'parity-1')
        self.assertEqual(entries['rollback'][2]['client_key'], 'parity-fail')

    def test_transport_failures_are_not_reported_as_equivalent(self):
        # Content type is only compared when a JSON body exists (204 responses have none).
        self.assertFalse(parity.call('http://127.0.0.1:1', 'GET', '/api/items')['status'])
