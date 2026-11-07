"""Deterministic bounded HTTP-input corpus generator; execution is separate."""
import argparse
import hashlib
import json
import random
from pathlib import Path

MAX_CASES = 10000
MAX_VALUE_BYTES = 65536
MAX_TOTAL_BYTES = 16 * 1024 * 1024
SEED_DEFAULT = 20261107


def boundary_corpus():
    return [
        {'class': 'percent_encoding', 'value': '%'},
        {'class': 'encoded_delimiter', 'value': '%2f%5c'},
        {'class': 'control_crlf', 'value': '\r\nInjected: yes'},
        {'class': 'oversized_body', 'value': 'x' * MAX_VALUE_BYTES},
        {'class': 'duplicate_field', 'value': 'id=1&id=2'},
        {'class': 'invalid_utf8', 'value': '\udcff'},
        {'class': 'unusual_method', 'value': 'TRACE'},
        {'class': 'truncated_json', 'value': '{"field":'},
        {'class': 'csrf_token', 'value': 'f' * 65},
    ]


def validate_boundary_size():
    if sum(len(case['value'].encode('utf-8', errors='surrogatepass'))
           for case in boundary_corpus()) > MAX_TOTAL_BYTES:
        raise ValueError('boundary corpus exceeds byte limit')


def build_corpus(seed, count):
    if type(count) is not int or not 1 <= count <= MAX_CASES:
        raise ValueError(f'count must be an integer from 1 to {MAX_CASES}')
    if type(seed) is not int or not 0 <= seed <= 2**32 - 1:
        raise ValueError('seed must be an unsigned 32-bit integer')
    rng = random.Random(seed)
    classes = ('path', 'header', 'json', 'form', 'route_parameter', 'csrf_token')
    alphabet = 'abcXYZ012%/?&=<>\r\n\\'
    cases = []
    total = 0
    for index in range(count):
        length = rng.randint(1, 96)
        value = ''.join(rng.choice(alphabet) for _ in range(length))
        case_class = classes[index % len(classes)]
        total += len(value.encode('utf-8', errors='surrogatepass'))
        if len(value.encode('utf-8', errors='surrogatepass')) > MAX_VALUE_BYTES or total > MAX_TOTAL_BYTES:
            raise ValueError('generated corpus exceeds byte limit')
        cases.append({'class': case_class, 'value': value})
    return cases


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--seed', type=int, default=SEED_DEFAULT)
    parser.add_argument('--count', type=int, default=1000)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    validate_boundary_size()
    cases = boundary_corpus() + build_corpus(args.seed, args.count)
    encoded = json.dumps(cases, ensure_ascii=True, separators=(',', ':')).encode()
    if len(encoded) > MAX_TOTAL_BYTES:
        parser.error('serialized corpus exceeds byte limit')
    report = {'seed': args.seed, 'random_cases': args.count, 'total_cases': len(cases),
              'sha256': hashlib.sha256(encoded).hexdigest(), 'cases': cases,
              'execution_status': 'not_run',
              'limitation': 'Corpus generation only; parser execution requires an OS-enforced offline sandbox.'}
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(report, indent=2) + '\n')
    print(json.dumps({key: report[key] for key in ('seed', 'random_cases', 'total_cases', 'sha256', 'execution_status')}))


if __name__ == '__main__':
    main()
