import unittest
from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from safety import validate_config, validate_target


class SafetyTest(unittest.TestCase):
    def test_allows_only_loopback_targets(self):
        self.assertEqual(validate_target('http://127.0.0.1:18080'), 'http://127.0.0.1:18080')
        self.assertEqual(validate_target('http://localhost:18080'), 'http://localhost:18080')
        self.assertEqual(validate_target('http://[::1]:18080'), 'http://[::1]:18080')

    def test_allows_only_internal_compose_service_target(self):
        self.assertEqual(validate_target('http://nginx:8080'), 'http://nginx:8080')

    def test_load_launcher_uses_internal_target_and_explicit_opt_in(self):
        launcher = (Path(__file__).resolve().parents[1] / 'run.py').read_text()
        self.assertIn("parser.add_argument('--target', default='http://nginx:8080')", launcher)
        self.assertIn("--yes-really-local-load", launcher)

    def test_front_controller_checks_fixture_router_type(self):
        entrypoint = (Path(__file__).resolve().parents[1] / 'public/index.php').read_text()
        self.assertIn('use Orku\\Routing\\Router;', entrypoint)
        self.assertIn('$router instanceof Router', entrypoint)

    def test_rejects_arbitrary_compose_service(self):
        with self.assertRaises(ValueError):
            validate_target('http://unrelated:8080')

    def test_rejects_external_targets(self):
        for target in ('https://example.com', 'http://192.0.2.1:18080', 'http://127.0.0.1:70000', 'http://localhost:8080'):
            with self.assertRaises(ValueError):
                validate_target(target)

    def test_rejects_overlong_or_unbounded_scenarios(self):
        with self.assertRaises(ValueError):
            validate_config({'duration_seconds': 16})
        with self.assertRaises(ValueError):
            validate_config({'duration_seconds': 0})

    def test_cleanup_script_is_idempotent_compose_down(self):
        readme = (Path(__file__).resolve().parents[1] / 'README.md').read_text()
        self.assertIn('docker compose down --remove-orphans', readme)

    def test_baseline_and_protected_share_same_app_workload(self):
        root = Path(__file__).resolve().parents[1]
        app = (root / 'app.php').read_text()
        self.assertIn("'work' => $value", app)
        for config in ('nginx.baseline.conf', 'nginx.protected.conf'):
            self.assertTrue((root / config).is_file())

    def test_overload_fixture_is_opt_in_and_controlled(self):
        app = (Path(__file__).resolve().parents[1] / 'app.php').read_text()
        self.assertIn("getenv('LAB_TEST_OVERLOAD') === '1'", app)
        self.assertIn('new HttpException(503', app)

    def test_fixtures_stay_within_owned_directory(self):
        root = Path(__file__).resolve().parents[1]
        for fixture in ('compose.yaml', 'nginx.protected-tight.conf', 'k6/scenarios.js', 'README.md', 'results/schema.json', 'results/comparison-template.md'):
            self.assertTrue((root / fixture).is_file(), fixture)

    def test_load_scenario_bound_is_capped_at_60_requests(self):
        script = (Path(__file__).resolve().parents[1] / 'k6/scenarios.js').read_text()
        self.assertIn('preAllocatedVUs: 4, maxVUs: 4', script)
        self.assertIn("baseline: [{ duration: '6s', target: 1 }]", script)
        self.assertIn('handleSummary(data)', script)
        self.assertIn('http_status_${status}', script)
        self.assertNotIn('maxDuration =', script)
        self.assertIn("admission: [{ duration: '6s', target: 4 }]", script)
        self.assertIn("legitimate: [{ duration: '6s', target: 1 }]", script)
        self.assertIn('transportFailures.add(1)', script)

    def test_run_exports_actual_compose_profile_metadata(self):
        root = Path(__file__).resolve().parents[1]
        launcher = (root / 'run.py').read_text()
        compose = (root / 'compose.yaml').read_text()
        self.assertIn("'LAB_VARIANT': os.environ.get('LAB_VARIANT', 'baseline')", launcher)
        self.assertIn("'LAB_NGINX_CONFIG': os.environ.get('LAB_NGINX_CONFIG', os.environ.get('LAB_VARIANT', 'baseline'))", launcher)
        self.assertIn('command: ["/bin/sh", "-c", "cp /etc/nginx/lab.conf', compose)
        self.assertIn('nginx.${LAB_NGINX_CONFIG:-${LAB_VARIANT:-baseline}}.conf', compose)
