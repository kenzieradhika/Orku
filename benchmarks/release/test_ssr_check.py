import unittest
import subprocess
from pathlib import Path

import ssr_check


class SsrCheckTest(unittest.TestCase):
    def test_real_orku_renderer_produces_safe_expected_cases(self):
        script = Path(__file__).with_name('ssr_check.php')
        result = subprocess.run(['php', str(script), '--cases'], capture_output=True,
                               text=True, timeout=10, check=True)
        outputs = __import__('json').loads(result.stdout)
        ssr_check.validate_outputs(outputs)
        self.assertNotIn('<script>', outputs['escaped'])

    def test_output_cases_cover_utf8_null_nested_layout_and_safe_escape(self):
        outputs = ssr_check.validate_outputs({
            'static': '<p>Release validation</p>', 'static_laravel': '<p>Release validation</p>',
            'utf8': '<p>café</p>', 'utf8_laravel': '<p>café</p>',
            'null': '<p></p>', 'null_laravel': '<p></p>',
            'empty': '<p></p>', 'empty_laravel': '<p></p>',
            'nested': '<main><p>inner</p></main>', 'nested_laravel': '<main><p>inner</p></main>',
            'escaped': '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>',
            'escaped_laravel': '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>',
        })
        self.assertEqual(outputs['escaped'], '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>')
        self.assertNotIn('<script>', outputs['escaped'])

    def test_raw_marker_is_only_accepted_as_explicit_unsafe_case(self):
        outputs = {'static': '<p>Release validation</p>', 'static_laravel': '<p>Release validation</p>',
                   'utf8': '<p>café</p>', 'utf8_laravel': '<p>café</p>',
                   'null': '<p></p>', 'null_laravel': '<p></p>',
                   'empty': '<p></p>', 'empty_laravel': '<p></p>',
                   'nested': '<main><p>inner</p></main>', 'nested_laravel': '<main><p>inner</p></main>',
                   'escaped': '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>',
                   'escaped_laravel': '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>',
                   'raw_unsafe': '<script>marker</script>',
                   'raw_unsafe_expected': '<script>marker</script>'}
        ssr_check.validate_outputs(outputs)
        with self.assertRaises(ValueError):
            ssr_check.validate_outputs({'escaped': '<script>marker</script>'})

    def test_rejects_framework_output_mismatch(self):
        with self.assertRaisesRegex(ValueError, 'output mismatch'):
            ssr_check.validate_outputs({'static': '<p>orku</p>', 'static_laravel': '<p>blade</p>'})

    def test_percentiles_use_nearest_rank_and_repeats_are_bounded(self):
        self.assertEqual(ssr_check.percentiles([1, 2, 3, 4, 5]),
                         {'p50': 3, 'p95': 5, 'p99': 5})
        with self.assertRaises(ValueError):
            ssr_check.validate_repeats(0)
        with self.assertRaises(ValueError):
            ssr_check.validate_repeats(6)


if __name__ == '__main__':
    unittest.main()
