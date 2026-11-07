import importlib.util
from pathlib import Path
import socket
import subprocess
import sys
import tempfile
import unittest


MODULE = Path(__file__).with_name('soak_check.py')
SPEC = importlib.util.spec_from_file_location('soak_check', MODULE)
soak = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(soak)


class SoakCheckTest(unittest.TestCase):
    def test_duration_rate_and_concurrency_are_bounded(self):
        self.assertEqual(soak.validate_config({'duration_hours': 24, 'rate': 2,
                                               'concurrency': 4}),
                         {'duration_hours': 24, 'rate': 2, 'concurrency': 4})
        for config in [
            {'duration_hours': 0, 'rate': 2, 'concurrency': 4},
            {'duration_hours': 73, 'rate': 2, 'concurrency': 4},
            {'duration_hours': 24, 'rate': 2.1, 'concurrency': 4},
            {'duration_hours': 24, 'rate': 2, 'concurrency': 5},
        ]:
            with self.subTest(config=config), self.assertRaises(ValueError):
                soak.validate_config(config)

    def test_window_errors_and_memory_slope(self):
        window = soak.summarize_window([
            {'status': 200, 'latency_ms': 1, 'memory_bytes': 10 * 1024 * 1024},
            {'status': 500, 'latency_ms': 2, 'memory_bytes': 12 * 1024 * 1024},
        ])
        self.assertEqual(window['errors'], 1)
        self.assertEqual(window['requests'], 2)
        slope = soak.memory_slope_mib_per_hour([
            {'elapsed_seconds': 0, 'memory_bytes': 10 * 1024 * 1024},
            {'elapsed_seconds': 3600, 'memory_bytes': 11 * 1024 * 1024},
        ])
        self.assertEqual(slope, 1.0)

    def test_stop_file_stops_loop_before_next_request(self):
        self.assertTrue(soak.should_stop(stop_exists=True, now=1, deadline=10))
        self.assertTrue(soak.should_stop(stop_exists=False, now=10, deadline=10))
        self.assertFalse(soak.should_stop(stop_exists=False, now=1, deadline=10))

    def test_invalid_or_non_loopback_url_rejected(self):
        for url in ['https://127.0.0.1', 'http://example.com', 'http://user@127.0.0.1']:
            parsed = __import__('urllib.parse', fromlist=['urlsplit']).urlsplit(url)
            self.assertFalse(soak.is_safe_url(parsed))
        self.assertTrue(soak.is_safe_url(__import__('urllib.parse', fromlist=['urlsplit']).urlsplit(
            'http://127.0.0.1:80/health')))

    def test_runner_rejects_existing_stop_file_without_request(self):
        with tempfile.TemporaryDirectory() as directory:
            stop = Path(directory) / 'stop'
            stop.touch()
            sock = socket.socket()
            sock.bind(('127.0.0.1', 0))
            sock.listen()
            sock.settimeout(.2)
            port = sock.getsockname()[1]
            result = subprocess.run([sys.executable, str(MODULE), '--url',
                                     f'http://127.0.0.1:{port}/', '--duration-hours', '1',
                                     '--stop-file', str(stop), '--output', str(Path(directory) / 'out.json')],
                                    capture_output=True, text=True, timeout=3)
            with self.assertRaises(TimeoutError):
                sock.accept()
            sock.close()
        self.assertEqual(result.returncode, 2)

if __name__ == '__main__':
    unittest.main()
