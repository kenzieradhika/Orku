"""Record native runtime/platform capability metadata; no host modifications."""
import argparse
import json
from pathlib import Path
import platform
import re
import shutil
import subprocess

SUPPORTED_PHP = {'8.4', '8.5'}
REQUIRED_MODULES = {'PDO', 'pdo_sqlite'}


def build_report(*, system, machine, php_version, php_binary, php_modules,
                 image_digest, emulated):
    if not system or not machine or not re.fullmatch(r'\d+\.\d+\.\d+', php_version):
        raise ValueError('system, machine and semantic PHP version are required')
    if not php_binary or not php_binary.startswith('/'):
        raise ValueError('php_binary must be an absolute path')
    if image_digest is not None and not re.fullmatch(r'sha256:[0-9a-f]{64}', image_digest):
        raise ValueError('image_digest must be sha256:<64 lowercase hex> or null')
    modules = sorted(set(php_modules))
    missing = sorted(REQUIRED_MODULES - set(modules))
    major_minor = '.'.join(php_version.split('.')[:2])
    if emulated:
        status = 'emulated_not_native_evidence'
    elif system != 'Linux' or machine not in ('x86_64', 'aarch64'):
        status = 'unsupported_platform'
    elif major_minor not in SUPPORTED_PHP:
        status = 'unsupported_php_version'
    elif missing:
        status = 'missing_required_capabilities'
    else:
        status = 'metadata_only_checks_not_run'
    return {'system': system, 'architecture': machine, 'php_version': php_version,
            'php_binary': php_binary, 'php_modules': modules,
            'missing_capabilities': missing, 'image_digest': image_digest,
            'execution': 'emulated' if emulated else 'native', 'support_status': status,
            'checks': {'cli': 'not_run', 'http_dispatch': 'not_run', 'session': 'not_run',
                       'pdo_sqlite': 'not_run', 'thinkcore': 'not_run'}}


def local_report():
    php = shutil.which('php')
    if php is None:
        raise RuntimeError('PHP CLI not found')
    version = subprocess.check_output([php, '-r', 'echo PHP_VERSION;'], text=True, timeout=5)
    modules = [line.strip() for line in subprocess.check_output(
        [php, '-r', 'echo implode("\\n", get_loaded_extensions());'], text=True, timeout=5
    ).splitlines() if line.strip()]
    return build_report(system=platform.system(), machine=platform.machine(),
                        php_version=version, php_binary=php, php_modules=modules,
                        image_digest=None, emulated=False)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    report = local_report()
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(report, indent=2) + '\n')
    print(json.dumps(report, indent=2))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
