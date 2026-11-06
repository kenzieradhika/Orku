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

Not verified by the Apache check: TLS, reverse proxy trust, FPM, Nginx, shared hosting, ARM, writable
session storage, persistent workers, sustained load, pool-wide resources or
production readiness. Add separate deployment checks before claiming support.

## Nginx/PHP-FPM

Run `python3 tests/fpm_check.py`. Images: `nginx:1.28-alpine` and
`php:8.4.26-fpm`. The fixture `tests/fixtures/nginx.conf` executes only the exact
public `index.php`; other PHP paths and dot paths return 404. FPM has no published
port. Both containers share a disposable bridge network; only Nginx exposes an
ephemeral loopback port. Repository mounts are read-only. Cleanup stops only the
created containers and removes their network.

The check covers routing, HEAD/length, 405, security headers, source isolation and
100 successful requests at concurrency 4. CI runs it on PHP 8.4. This is a smoke
check, not a sustained-load or capacity claim. SAPI capture omits empty CGI
`CONTENT_LENGTH`/`CONTENT_TYPE` metadata, as sent by Nginx when absent; nonempty
malformed lengths remain rejected. TLS, proxy trust, session storage, sustained
memory behavior and rollback remain separate [stable release gates](stable-release-checklist.md).

The same runner separately boots `fullstack-app` under the FPM worker identity.
It verifies writable default file-session storage across requests, distinct
sessions/tokens, Secure/HttpOnly/SameSite=Lax cookie attributes, rejection of
missing/cross-session CSRF tokens (403), invalid fields (422), and valid form
redirect (303). Cookies are explicitly replayed by the test client over loopback
HTTP to exercise server-side state while retaining the production Secure policy.
This does not verify browser Secure-cookie delivery or TLS. Session files exist
only inside the disposable container, not on the read-only repository mount;
shared/persistent production storage still needs deployment-specific validation.
