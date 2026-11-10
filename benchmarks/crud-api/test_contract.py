import importlib.util
import json
from pathlib import Path
import sqlite3
import tempfile
import unittest

HERE = Path(__file__).resolve().parent
SPEC = importlib.util.spec_from_file_location('crud_seed', HERE / 'seed.py')
seed = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(seed)


class ContractTest(unittest.TestCase):
    def test_contract_declares_crud_surface_and_limits(self):
        contract = json.loads((HERE / 'contract.json').read_text())
        methods = {(route['method'], route['path']) for route in contract['routes']}
        self.assertEqual(methods, {
            ('GET', '/api/items'), ('GET', '/api/items/{id}'),
            ('POST', '/api/items'), ('PUT', '/api/items/{id}'),
            ('DELETE', '/api/items/{id}'),
        })
        self.assertEqual(contract['seed_rows'], 200)
        self.assertEqual(contract['limits']['list_limit_max'], 100)
        self.assertEqual(contract['envelope']['fields'],
                         ['id', 'title', 'body', 'version', 'client_key'])

    def test_seed_is_deterministic_and_unique(self):
        with tempfile.TemporaryDirectory() as tmp:
            connection = seed.open_database(Path(tmp) / 'fixture.sqlite')
            try:
                self.assertEqual(seed.seed(connection), 200)
                first = connection.execute(
                    'SELECT id, title, body, version, client_key FROM items ORDER BY id').fetchall()
                self.assertEqual(seed.seed(connection), 200)
                second = connection.execute(
                    'SELECT id, title, body, version, client_key FROM items ORDER BY id').fetchall()
            finally:
                connection.close()
        self.assertEqual(first, second)
        self.assertEqual(first[0], (1, 'Seed item 0001', 'Seed body 0001', 1, 'seed-0001'))
        self.assertEqual(len({row[4] for row in first}), 200)
        self.assertEqual({row[3] for row in first}, {1})

    def test_schema_enforces_uniqueness_and_field_limits(self):
        with tempfile.TemporaryDirectory() as tmp:
            connection = seed.open_database(Path(tmp) / 'fixture.sqlite')
            try:
                seed.seed(connection, 1)
                with self.assertRaises(sqlite3.IntegrityError):
                    connection.execute(
                        'INSERT INTO items (title, body, version, client_key) VALUES (?, ?, 1, ?)',
                        ('duplicate', 'body', 'seed-0001'))
                with self.assertRaises(sqlite3.IntegrityError):
                    connection.execute(
                        'INSERT INTO items (title, body, version, client_key) VALUES (?, ?, 1, ?)',
                        ('', 'body', 'empty-title'))
                with self.assertRaises(sqlite3.IntegrityError):
                    connection.execute(
                        'INSERT INTO items (title, body, version, client_key) VALUES (?, ?, 1, ?)',
                        ('title', 'x' * 2001, 'long-body'))
            finally:
                connection.close()
