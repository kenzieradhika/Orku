#!/usr/bin/env python3
"""Verify Orku and Laravel CRUD fixtures expose identical status codes and JSON.

Maintenance endpoints must be enabled on both disposable fixtures. The scenario is
deterministic: reset restarts AUTOINCREMENT so both applications allocate the same
resource IDs for the same create sequence.
"""
import argparse
from concurrent.futures import ThreadPoolExecutor
import http.client
import json
import platform
import subprocess
import urllib.parse
from pathlib import Path

MAX_BODY_BYTES = 65536
CONTRACT = json.loads((Path(__file__).with_name('contract.json')).read_text())


def validate_url(base_url):
    parsed = urllib.parse.urlsplit(base_url)
    valid = (parsed.scheme == 'http' and parsed.hostname in ('127.0.0.1', 'localhost', '::1')
             and not parsed.hostname.startswith('[') and parsed.username is None
             and parsed.password is None and parsed.path in ('', '/')
             and not parsed.query and not parsed.fragment)
    if not valid:
        raise ValueError('Only credential-free loopback HTTP base URLs are accepted.')
    return base_url.rstrip('/')


def call(base_url, method, path, payload=None):
    parsed = urllib.parse.urlsplit(base_url)
    try:
        connection = http.client.HTTPConnection(parsed.hostname, parsed.port or 80, timeout=5)
        headers = {'Accept': 'application/json'}
        body = None
        if payload is not None:
            body = json.dumps(payload, separators=(',', ':')).encode()
            headers['Content-Type'] = 'application/json'
        connection.request(method, path, body=body, headers=headers)
        response = connection.getresponse()
        status = response.status
        raw = response.read(MAX_BODY_BYTES + 1)
        content_type = (response.getheader('content-type') or '').split(';')[0].strip().lower()
        connection.close()
    except (OSError, http.client.HTTPException) as error:
        return {'status': None, 'json': None, 'content_type': None, 'error': type(error).__name__}
    body = None
    error = None
    if len(raw) > MAX_BODY_BYTES:
        error = 'response_body_limit'
    elif raw:
        try:
            body = json.loads(raw)
        except ValueError:
            error = 'invalid_json'
    return {'status': status, 'json': body, 'content_type': content_type, 'error': error}


def scenario():
    def created(client_key='parity-1', title='Parity item'):
        return {'title': title, 'body': 'Parity body', 'client_key': client_key}

    return [
        ('list', 'GET', '/api/items?limit=3', None),
        ('get', 'GET', '/api/items/1', None),
        ('get-missing', 'GET', '/api/items/9999', None),
        ('list-invalid', 'GET', '/api/items?limit=0', None),
        ('create', 'POST', '/api/items', created()),
        ('create-duplicate', 'POST', '/api/items', created()),
        ('create-invalid', 'POST', '/api/items', created(title='')),
        ('create-unknown-field', 'POST', '/api/items', {**created('parity-2'), 'extra': 1}),
        ('update', 'PUT', '/api/items/201', {'title': 'Updated', 'body': 'Updated body', 'version': 1}),
        ('update-stale', 'PUT', '/api/items/201', {'title': 'Stale', 'body': 'Stale body', 'version': 1}),
        ('delete-stale', 'DELETE', '/api/items/201?version=1', None),
        ('delete', 'DELETE', '/api/items/201?version=2', None),
        ('get-deleted', 'GET', '/api/items/201', None),
        ('rollback', 'POST', '/api/items?simulate_failure=1', created('parity-fail')),
        ('rollback-state', 'GET', '/api/bench/state', None),
    ]


def compare(candidate, reference):
    candidate = validate_url(candidate)
    reference = validate_url(reference)
    for base in (candidate, reference):
        reset = call(base, 'POST', CONTRACT['maintenance']['reset']['path'])
        if reset['status'] != 200 or reset['json'] != {'rows': CONTRACT['seed_rows']}:
            raise RuntimeError(f'reset failed for {base}: {reset}')
    checks = []
    for name, method, path, payload in scenario():
        left = call(candidate, method, path, payload)
        right = call(reference, method, path, payload)
        equivalent = (left['error'] is None and right['error'] is None
                      and left['status'] == right['status'] and left['json'] == right['json'])
        if left['json'] is not None:
            equivalent = equivalent and left['content_type'] == right['content_type']
        checks.append({'name': name, 'method': method, 'path': path,
                       'candidate_status': left['status'], 'reference_status': right['status'],
                       'candidate': left['json'], 'reference': right['json'],
                       'content_type': {'candidate': left['content_type'],
                                        'reference': right['content_type']},
                       'equivalent': equivalent})
    return {'mode': 'contract_parity', 'passed': all(check['equivalent'] for check in checks),
            'environment': {'python': platform.python_version(), 'platform': platform.platform(),
                            'source_revision': subprocess.check_output(
                                ['git', 'rev-parse', 'HEAD'], text=True).strip()},
            'candidate': candidate, 'reference': reference, 'checks': checks}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--candidate', required=True)
    parser.add_argument('--reference', required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    report = compare(args.candidate, args.reference)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(report, indent=2) + '\n')
    return int(not report['passed'])


if __name__ == '__main__':
    raise SystemExit(main())
