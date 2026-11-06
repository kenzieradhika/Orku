# Orku Web security boundaries

## Rendering

Templates are trusted application code. No sandbox, compiler, eval or arbitrary
string execution. Only named templates in configured canonical directories are
selected. Names reject slash/backslash/traversal/null bytes/extensions and lengths
over 200 bytes. Canonical containment rejects escaping symlinks. Trusted deployer
must prevent filesystem replacement after resolution; cached paths are not a
defense against a writer controlling template roots.

`escape()` uses ENT_QUOTES + ENT_SUBSTITUTE UTF-8 HTML escaping for HTML text and
quoted attributes. Arbitrary PHP echo is not escaped. `raw()` requires explicitly
constructed `TrustedHtml`; never wrap request data in it. `url()` accepts local
absolute paths and valid HTTP(S) URLs, rejects protocol-relative URLs, controls and
backslashes. It is not an external-host allowlist or redirect authorization API.
HTML escaping does not protect JS/CSS/headers. No JS/CSS interpolation helpers.

Output buffers isolate partial output on exceptions, including nested buffers.
Application templates must not close framework buffers, flush output, alter
buffer callbacks or emit HTTP headers. They are trusted code. Public WebException
messages omit filesystem paths; previous exceptions retain server-side diagnostics.

## Static files and CSP

Only public application assets, known extensions and canonical-contained files.
No source/config/environment exposure. Public directories must contain only public
content. SVG/JS/CSS are trusted active application assets, not sanitized uploads.
Cache policy is 300 seconds with content ETag; no fingerprint or bundling promise.

API CSP remains default-src 'none', frame-ancestors 'none', base-uri 'none',
form-action 'self'. Demo allows only same-origin styles/scripts/images/fonts, with
no inline scripts/styles. No unsafe-inline/eval. `nosniff` and no-referrer remain.
No automatic HSTS: configure it only after verifying HTTPS deployment policy.

## Form contract

URL-encoded only, default 65,536 bytes / 100 fields, under Core's 1 MiB limit.
Oversize returns 413, unsupported media/charset 415, malformed encoding/UTF-8,
duplicate names, bracket notation and malformed pairs 400. Keys match ASCII
identifier grammar. Duplicate encoded aliases also fail after decoding. No
multipart, files, arrays, nesting, authentication or authorization.

Validation is server-side. Fields return structured errors; successful number
values are finite floats, not decimal-money or arbitrary-precision values.
Unknown fields are ignored by rules. Application must enforce business semantics.

## Sessions and CSRF

Native session extension is optional until forms use it. No automatic sessions
on read-only pages/API. Configure private session storage, storage limits, GC,
TLS and deployment-specific cookie scope. Strict mode rejects uninitialized IDs;
only Request cookies supply IDs. URL IDs/trans-SID and hidden native cookie/cache
headers are disabled. Cookie is attached to immutable Response explicitly,
HttpOnly, SameSite=Lax, Secure by default, no Domain, session lifetime.
Only one cookie is needed; no multi-value header API added.

Do not mix this session wrapper with other native-session owners in one request.
Call commit before response emission. Destructor closes outstanding sessions on
exception. Session state is process-global inside PHP: persistent worker runtimes
are unsupported. Session storage errors must fail, not silently accept forms.

CSRF uses 32 random bytes encoded as 64 hex characters in server session. Tokens
are compared with hash_equals and stable across tabs. Missing/invalid tokens
return 403 before field validation or business mutation. Tokens never enter URLs
or error details. Session/token-bearing responses use no-store.

This does not prevent XSS, compromised same-origin scripts, replay by someone
holding token+cookie, spam or resource exhaustion. No rate limiter, login, session
rotation/auth lifecycle or timeout policy supplied. Do not build authentication
on this minimal wrapper without those controls. Set session.auto_start=0.

Demo validates feedback but never stores/sends it. Production errors are generic
HTML; explicitly enabled debug mode includes escaped diagnostics and must stay
off on public deployments. Logs can contain sensitive application exception data;
keep them private and avoid throwing secrets in exception messages.
