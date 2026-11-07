import importlib.util
import json
from pathlib import Path
import socket
import threading
import unittest


MODULE = Path(__file__).with_name('api_check.py')
SPEC = importlib.util.spec_from_file_location('api_check', MODULE)
api = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(api)


class ApiScenarioTest(unittest.TestCase):
    def test_rejects_non_loopback_or_unbounded_scenario(self):
        good = {'requests': 20, 'concurrency': 2, 'duration_seconds': 10,
                'base_url': 'http://127.0.0.1:8080',
                'endpoints': [{'name': 'status', 'path': '/api/status', 'method': 'GET',
                               'expected_status': 200, 'expected_body': {'ok': True}}]}
        self.assertTrue(api.validate_scenario(good))
        for changes in [
            {'base_url': 'https://example.com'},
            {'base_url': 'http://127.0.0.1.evil:80'},
            {'requests': 10001}, {'concurrency': 129},
            {'duration_seconds': 121}, {'requests': 0},
        ]:
            with self.subTest(changes=changes), self.assertRaises(ValueError):
                api.validate_scenario({**good, **changes})
        with self.assertRaises(ValueError):
            api.validate_scenario({**good, 'endpoints': good['endpoints'] * 2,
                                   'requests': 5001})

    def test_aggregates_status_errors_and_percentiles(self):
        rows = [{'status': 200, 'latency_ms': 1.0},
                {'status': 200, 'latency_ms': 2.0},
                {'status': 422, 'latency_ms': 3.0},
                {'status': None, 'latency_ms': 4.0}]
        summary = api.summarize(rows, expected_status=200)
        self.assertEqual(summary['requests'], 4)
        self.assertEqual(summary['errors'], 2)
        self.assertEqual(summary['statuses'], {'200': 2, '422': 1})
        self.assertEqual(summary['transport_errors'], 1)
        self.assertEqual(summary['p50_ms'], 2.0)
        self.assertEqual(summary['p95_ms'], 4.0)
        self.assertEqual(summary['p99_ms'], 4.0)

    def test_checks_expected_body_and_status_before_measurement(self):
        self.assertTrue(api.response_matches(200, b'{"ok":true}', 200, b'{"ok":true}'))
        self.assertFalse(api.response_matches(500, b'{"ok":true}', 200, b'{"ok":true}'))
        self.assertFalse(api.response_matches(200, b'{"ok":false}', 200, b'{"ok":true}'))

    def test_bounded_run_preserves_raw_rows_and_request_errors(self):
        listener = socket.socket()
        listener.bind(('127.0.0.1', 0))
        listener.listen()
        port = listener.getsockname()[1]
        def serve():
            conn, _ = listener.accept()
            with conn:
                conn.recv(4096)
                body = b'{"ok":true}'
                conn.sendall(b'HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n'
                             + f'Content-Length: {len(body)}\r\nConnection: close\r\n\r\n'.encode() + body)
            listener.close()
        thread = threading.Thread(target=serve)
        thread.start()
        scenario = {'base_url': f'http://127.0.0.1:{port}', 'requests': 1,
                    'concurrency': 1, 'duration_seconds': 2,
                    'endpoints': [{'name': 'status', 'method': 'GET', 'path': '/',
                                   'expected_status': 200, 'expected_body': {'ok': True}}]}
        try:
            report = api.run(scenario)
        finally:
            thread.join(timeout=2)
            listener.close()
        self.assertEqual(report['endpoints'][0]['errors'], 0)
        self.assertEqual(report['endpoints'][0]['contract_mismatches'], 0)
        self.assertEqual(json.loads(report['raw'][0]['body']), {'ok': True})

    def test_contract_verification_compares_two_loopback_apps(self):
        servers = []
        for _ in range(2):
            sock = socket.socket()
            sock.bind(('127.0.0.1', 0))
            sock.listen()
            servers.append((sock, sock.getsockname()[1]))
        def serve(sock):
            conn, _ = sock.accept()
            with conn:
                conn.recv(4096)
                body = b'{"ok":true}'
                conn.sendall(b'HTTP/1.1 200 OK\r\nContent-Length: 11\r\nConnection: close\r\n\r\n' + body)
            sock.close()
        threads = [threading.Thread(target=serve, args=(sock,)) for sock, _ in servers]
        for thread in threads:
            thread.start()
        scenario = {'base_url': f'http://127.0.0.1:{servers[0][1]}', 'requests': 1,
                    'concurrency': 1, 'duration_seconds': 2,
                    'endpoints': [{'name': 'status', 'method': 'GET', 'path': '/',
                                   'expected_status': 200, 'expected_body': {'ok': True}}]}
        try:
            result = api.verify_contract(scenario, {'base_url': f'http://127.0.0.1:{servers[1][1]}'})
        finally:
            for thread in threads:
                thread.join(timeout=2)
            for sock, _ in servers:
                sock.close()
        self.assertTrue(result['passed'])


if __name__ == '__main__':
    unittest.main()
