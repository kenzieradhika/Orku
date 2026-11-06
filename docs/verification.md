# Core v0.1 verification

Verified locally on 2026-10-25, Linux x86_64, PHP 8.4.21 CLI.
Host default PHP 8.3.6 was not used because package requires PHP 8.4+.

## Commands and observations

| Check | Observation |
| --- | --- |
| `composer validate --strict` | Valid Composer configuration |
| `composer install` in source-only copy | Installed locked development dependencies |
| `composer check` | 71 tests, 173 assertions; PHPStan maximum level clean; formatter clean |
| `composer install --no-dev` in another source-only copy | Zero runtime packages needed |
| `php bin/orku routes hello-world` | Listed three actual registered routes |
| `composer audit --locked` | No known vulnerability advisories returned at check time |
| `php benchmarks/kernel.php 10000` | Three cases completed, zero errors |

Source-only copies excluded vendor, git metadata and tool caches. Remote clone
verification is not claimed: changes have not been committed or pushed. Hosted
GitHub Actions has not run. PHP 8.5, PHP-FPM, Apache, ARM and shared hosting have
not been tested locally.

Standalone verification tools were placed under `/tmp/opencode`. PHPStan's
optional Turbo dynamic extension cannot load in this static PHP build, so local
checks used:

```bash
PHPSTAN_RESTART= /tmp/opencode/php84/php /tmp/opencode/composer.phar check
```

That setting prevents PHPStan's optional process restart, not analysis rules;
no baselines or ignored errors were added. Normal PHP installations use the
README commands. Error-path tests intentionally write a sample diagnostic and a
reporter-failure message to logs; these are not PHPUnit failures.

## Live HTTP assertions in automated tests

- Home returns 200 and plain text; API status returns valid JSON.
- Encoded UTF-8 dynamic parameter reaches handler decoded once.
- Unknown path and `/.env` return 404; wrong method returns 405 with `Allow`.
- HEAD returns GET status/length without body.
- POST valid greeting returns 201; invalid value returns 422.
- Malformed JSON returns 400; wrong media type returns 415; oversized body 413.
- Query, cookie, custom header and JSON body captured through actual HTTP.
- Handler exception returns safe 500 without accidentally echoed private text.
- Malformed percent escape and encoded slash return 400.
- Redirect emits location; 204 emits no body or content length.
- HTML transport emits HTML content type and restrictive default CSP.
- `202 Accepted` with Location retains status, not PHP's implicit 302.

Unit/security tests additionally cover CRLF/header injection, traversal paths,
response framing validation, duplicate routes, static precedence, explicit HEAD,
logging callbacks and repeated-kernel route parameter isolation. Review regression
tests cover overlapping GET/HEAD precedence, excessive parameter names, ignored
exception framing headers and invalid UTF-8 diagnostics.

These checks are scoped evidence, not an independent security audit or a claim
of production readiness. Microbenchmark is not an HTTP benchmark; no comparison
with Laravel, Symfony or Go has been performed.
# Web v0.2 local verification, 2026-10-25

Baseline before source edits: PHP 8.4.21, 71 tests / 173 assertions, maximum
PHPStan and formatter pass. Git main unborn, no HEAD SHA. Core source present.

Current verification: `composer check` passed 129 tests / 334 assertions,
maximum PHPStan, formatter (57 PHP files, zero changes needed).
Tests include real HTTP Core/demo routes, form GET/POST, CSRF 403, validation
422, redirect 303, parser 400/413/415, CSP, safe static serving and prefix routing.
Intentional exception-reporter/error-renderer tests log failures to stderr;
these are not PHPUnit failures or hidden warnings.

Composer validate --strict passed. Composer audit returned empty advisories and
abandoned lists. A separate copied source tree under `/tmp/opencode/orku-web-clean`
installed with `--no-dev --prefer-dist --no-interaction`, zero runtime packages,
then rendered demo home through Kernel successfully. No mandatory packages added.

Browser: real installed Chromium headless shell, 375/768/1440 widths, pages,
assets, empty/valid forms, links and keyboard focus. Details and complete UI
delivery gate: [design/browser evidence](web-design-verification.md).
Performance: [measured methodology/results](web-performance.md).

Host default PHP 8.3.6 remains unsupported. Commands used isolated PHP 8.4.21:
`PHPSTAN_RESTART= /tmp/opencode/php84/php /tmp/opencode/composer.phar check`.
Empty PHPSTAN_RESTART disables optional Turbo restart unsupported by static PHP
build; no errors ignored or baselined. Tests/framework still target PHP ^8.4.

Hosted GitHub Actions not run. CI config includes PHP 8.4/8.5, but PHP 8.5,
Apache, Nginx/FPM, ARM/Raspberry Pi and cPanel were not executed here. No claim
of production readiness, broad compatibility certification or cross-framework speed.
No commits/pushes. All project content remains untracked on unborn main.

---
