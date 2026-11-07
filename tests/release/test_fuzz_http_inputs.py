import importlib.util
from pathlib import Path
import unittest


MODULE = Path(__file__).with_name('fuzz_http_inputs.py')
SPEC = importlib.util.spec_from_file_location('fuzz_http_inputs', MODULE)
fuzz = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(fuzz)


class FuzzCorpusTest(unittest.TestCase):
    def test_fixed_seed_is_reproducible_and_bounded(self):
        first = fuzz.build_corpus(seed=17, count=100)
        second = fuzz.build_corpus(seed=17, count=100)
        self.assertEqual(first, second)
        self.assertEqual(len(first), 100)
        self.assertLessEqual(sum(len(case['value']) for case in first), fuzz.MAX_TOTAL_BYTES)

    def test_case_and_byte_limits_reject_unbounded_values(self):
        for count in [0, -1, fuzz.MAX_CASES + 1, True]:
            with self.subTest(count=count), self.assertRaises(ValueError):
                fuzz.build_corpus(seed=1, count=count)

    def test_boundary_corpus_covers_input_classes(self):
        names = {case['class'] for case in fuzz.boundary_corpus()}
        self.assertTrue({'percent_encoding', 'encoded_delimiter', 'control_crlf',
                         'oversized_body', 'duplicate_field', 'invalid_utf8',
                         'unusual_method', 'truncated_json', 'csrf_token'} <= names)


if __name__ == '__main__':
    unittest.main()
