import http.server
import json
import os
from pathlib import Path
import socket
import subprocess
import sys
import threading
import unittest


class Handler(http.server.BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'

    def setup(self):
        super().setup()
        self.server.connections = getattr(self.server, 'connections', 0) + 1

    def do_GET(self):
        if self.path == '/concurrent':
            try:
                self.server.overlap.wait(timeout=3)
                self.send_response(200)
            except threading.BrokenBarrierError:
                self.send_response(503)
            self.send_header('Content-Length', '2')
            self.end_headers()
            self.wfile.write(b'ok')
            return
        if self.path == '/disconnect':
            try:
                self.connection.shutdown(socket.SHUT_RDWR)
            except OSError:
                pass
            self.connection.close()
            self.close_connection = True
            return
        body = b'ok'
        self.send_response(200 if self.path == '/?name=Ada' else 503)
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, *args):
        pass


class BenchmarkTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        cls.thread = threading.Thread(target=cls.server.serve_forever)
        cls.thread.start()

    @classmethod
    def tearDownClass(cls):
        cls.server.shutdown()
        cls.server.server_close()
        cls.thread.join()

    def run_benchmark(self, path='/?name=Ada', *options):
        return subprocess.run([
            sys.executable, str(Path(__file__).with_name('http_latency.py')),
            f'http://127.0.0.1:{self.server.server_port}{path}',
            '--requests', '1', '--warmup', '0', '--runtime-label', 'fixture',
            *options,
        ], capture_output=True, text=True, timeout=15)

    def test_query_and_single_sample_percentiles(self):
        result = self.run_benchmark()
        self.assertEqual(result.returncode, 0, result.stderr)
        data = json.loads(result.stdout)
        self.assertEqual(data['statuses'], {'200': 1})
        self.assertEqual(data['errors'], 0)
        self.assertEqual(data['p50_ms'], data['p99_ms'])
        self.assertGreater(data['requests_per_second'], 0)

    def test_non_200_is_error(self):
        result = self.run_benchmark('/unavailable')
        self.assertEqual(result.returncode, 1)
        self.assertEqual(json.loads(result.stdout)['errors'], 1)

    def test_concurrent_requests_actually_overlap(self):
        self.server.overlap = threading.Barrier(2)
        result = self.run_benchmark('/concurrent', '--requests', '4', '--concurrency', '2')
        self.assertEqual(result.returncode, 0, result.stderr)
        data = json.loads(result.stdout)
        self.assertEqual(data['concurrency'], 2)
        self.assertEqual(data['statuses'], {'200': 4})
        self.assertEqual(data['errors'], 0)
        self.assertLessEqual(data['p50_ms'], data['p99_ms'])

    def test_concurrent_failure_accounting(self):
        for path, field in [('/unavailable', 'errors'), ('/disconnect', 'transport_errors')]:
            result = self.run_benchmark(path, '--requests', '5', '--warmup', '2', '--concurrency', '2')
            self.assertEqual(result.returncode, 1, result.stderr)
            data = json.loads(result.stdout)
            self.assertEqual(data[field], 5)
            self.assertEqual(data['warmup_errors'], 2)
            self.assertEqual(data['error_rate'], 1)

    def test_disconnect_is_transport_error(self):
        result = self.run_benchmark('/disconnect')
        self.assertEqual(result.returncode, 1)
        self.assertEqual(json.loads(result.stdout)['transport_errors'], 1)

    def test_keep_alive_reuses_connections(self):
        self.server.connections = 0
        result = self.run_benchmark('/?name=Ada', '--requests', '5',
                                    '--concurrency', '1', '--keep-alive')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(json.loads(result.stdout)['statuses'], {'200': 5})
        self.assertEqual(self.server.connections, 1)

    def test_default_mode_opens_fresh_connection_per_request(self):
        self.server.connections = 0
        result = self.run_benchmark('/?name=Ada', '--requests', '3', '--concurrency', '1')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.server.connections, 3)

    def test_invalid_arguments(self):
        for options in [('--requests', '0'), ('--warmup', '-1'), ('--server-pid', '0'), ('--concurrency', '0'), ('--concurrency', '129')]:
            with self.subTest(options=options):
                self.assertEqual(self.run_benchmark('/?name=Ada', *options).returncode, 2)
        script = str(Path(__file__).with_name('http_latency.py'))
        for url in ['http://user@localhost/', 'http://localhost:99999/',
                    'http://localhost/#secret', 'http://example.com/',
                    'http://localhost:0/', 'http://localhost/é']:
            result = subprocess.run([sys.executable, script, url,
                                     '--runtime-label', 'fixture'], capture_output=True)
            self.assertEqual(result.returncode, 2)

    @unittest.skipUnless(Path('/proc/self/stat').exists(), 'Linux /proc required')
    def test_process_resources(self):
        result = self.run_benchmark('/?name=Ada', '--server-pid', str(os.getpid()))
        self.assertEqual(result.returncode, 0, result.stderr)
        resources = json.loads(result.stdout)['server_process']
        self.assertGreater(resources['rss_start_bytes'], 0)
        self.assertGreaterEqual(resources['cpu_seconds'], 0)

    def test_missing_process_is_unavailable(self):
        result = self.run_benchmark('/?name=Ada', '--server-pid', '2147483647')
        data = json.loads(result.stdout)
        self.assertIsNone(data['server_process']['cpu_seconds'])
        self.assertTrue(data['server_process']['unavailable_reason'])

    @unittest.skipUnless(Path('/proc/self/stat').exists(), 'Linux /proc required')
    def test_exited_process_is_unavailable(self):
        child = subprocess.Popen([sys.executable, '-c', 'pass'])
        try:
            os.waitid(os.P_PID, child.pid, os.WEXITED | os.WNOWAIT)
            result = self.run_benchmark('/?name=Ada', '--server-pid', str(child.pid))
            data = json.loads(result.stdout)['server_process']
            self.assertIsNone(data['rss_start_bytes'])
            self.assertTrue(data['unavailable_reason'])
        finally:
            child.wait()


if __name__ == '__main__':
    unittest.main()
