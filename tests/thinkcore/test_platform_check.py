import importlib.util
from pathlib import Path
import unittest


MODULE = Path(__file__).with_name('platform_check.py')
SPEC = importlib.util.spec_from_file_location('platform_check', MODULE)
platform_check = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(platform_check)


class PlatformCheckTest(unittest.TestCase):
    def test_supports_only_native_metadata_and_marks_missing_evidence(self):
        report = platform_check.build_report(
            system='Linux', machine='x86_64', php_version='8.3.6', php_binary='/usr/bin/php',
            php_modules=['PDO', 'sqlite3'], image_digest=None, emulated=False)
        self.assertEqual(report['architecture'], 'x86_64')
        self.assertEqual(report['execution'], 'native')
        self.assertEqual(report['support_status'], 'unsupported_php_version')
        self.assertIn('pdo_sqlite', report['missing_capabilities'])
        self.assertIsNone(report['image_digest'])

    def test_rejects_empty_or_malformed_required_metadata(self):
        for kwargs in [
            {'system': '', 'machine': 'x86_64', 'php_version': '8.4.1',
             'php_binary': '/usr/bin/php', 'php_modules': [], 'image_digest': None, 'emulated': False},
            {'system': 'Linux', 'machine': '', 'php_version': '8.4.1',
             'php_binary': '/usr/bin/php', 'php_modules': [], 'image_digest': None, 'emulated': False},
            {'system': 'Linux', 'machine': 'x86_64', 'php_version': 'bogus',
             'php_binary': '/usr/bin/php', 'php_modules': [], 'image_digest': None, 'emulated': False},
        ]:
            with self.subTest(kwargs=kwargs), self.assertRaises(ValueError):
                platform_check.build_report(**kwargs)

    def test_emulation_never_claimed_as_native(self):
        report = platform_check.build_report(
            system='Linux', machine='aarch64', php_version='8.4.1', php_binary='/usr/bin/php',
            php_modules=['PDO', 'pdo_sqlite'], image_digest='sha256:' + 'a' * 64,
            emulated=True)
        self.assertEqual(report['execution'], 'emulated')
        self.assertEqual(report['support_status'], 'emulated_not_native_evidence')


if __name__ == '__main__':
    unittest.main()
