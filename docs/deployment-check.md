# Isolated Apache deployment check

Run `python3 tests/apache_check.py` after Composer dependencies are installed.
Requires Docker, Python 3 and the `php:8.4.26-apache` image (Docker may pull it).
The check starts its own disposable container, binds an ephemeral loopback port,
mounts the repository read-only and stops only that container in a finally block.
It does not modify existing services, databases or host web-server configuration.

The tested virtual host is `tests/fixtures/apache.conf`. DocumentRoot points only
at `examples/hello-world/public`; `FallbackResource /index.php` routes missing
resources, directory indexes/MultiViews and .htaccess overrides are disabled.
Never use the repository root as DocumentRoot. Never put secrets, source, upload
scripts or arbitrary PHP files in the public directory: existing files bypass
the fallback controller. The fixture is a smoke-test configuration, not an
installer or complete production hardening profile.

Verified assertions: home/dynamic routing, HEAD without body and with length,
405, security headers, no PHP version header, 404 for source/config paths and
no PHP source or private mount paths in those responses. CI runs this on the
PHP 8.4 job. The Apache image uses mod_php, not PHP-FPM.

Not verified: TLS, reverse proxy trust, FPM, Nginx, shared hosting, ARM, writable
session storage, persistent workers, sustained load, pool-wide resources or
production readiness. Add separate deployment checks before claiming support.
