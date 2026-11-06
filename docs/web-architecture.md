# Orku Web v0.2 architecture

## Verified starting point

2026-10-25, PHP 8.4.21: `composer check` passed 71 tests / 173 assertions,
maximum PHPStan, and formatting (22 files). Log: `/tmp/opencode/orku-web-baseline.log`.
Branch `main` has no commits; HEAD SHA unavailable. Core source exists locally.
All project files are untracked. No commits or pushes authorized.

## Boundaries and API

Core stays independent. `Orku\Web\ViewRenderer` receives trusted directories;
`render(string $name, array $data = [], array $slots = []): string` returns HTML.
Dot-separated names map to `.php` files. Resolved files must stay inside a
configured canonical root: symlinks inside roots are allowed only when their
canonical target stays inside that same root. Deployments must not let request
users write templates or change symlinks. Validated paths are cached per renderer.

Templates receive only `$view` (`ViewContext`), not extracted variables.
`get()` accesses explicit props, `escape()` handles text and quoted attributes,
`url()` validates local absolute paths or HTTP(S) URLs before escaping.
`raw(TrustedHtml)` is the only helper that emits intentionally trusted markup.
PHP templates remain trusted executable application code, not a sandbox;
arbitrary `echo`, scripts, CSS, headers and URL schemes are NOT auto-escaped.
`TrustedHtml::fromTrustedString()` marks deliberate application-owned HTML.

`partial()` renders with explicit data. `component()` uses the `components.`
namespace and explicit props/named `TrustedHtml` slots. `layout()` renders a
page as the `content` slot in a layout; layouts can themselves nest. Contexts
are per render, with no global current view. Every render owns one output buffer,
restores its original depth on failure, and wraps errors in a path-free
`WebException` retaining the original exception for server-side diagnostics.
Templates must not close framework buffers or alter buffer handlers.

## Assets and HTTP

`AssetManager` validates relative application-owned paths, resolves within a
public asset root, generates encoded URLs with an explicit deployment prefix,
and serves only an allowlisted set of CSS, JS, raster images, SVG and font MIME
types. SVG is trusted application content. No arbitrary static repository files.
Conditional GET uses content ETags; cache lifetime is short without fingerprints.
Apache/Nginx should serve the same public assets and route other requests to
`public/index.php`. Never use the repository as document root.

Core restrictive CSP remains unchanged. Demo explicitly permits same-origin
styles/scripts/images/fonts, forbids objects/base/frame embedding, and restricts
form actions. No inline code, unsafe-inline, unsafe-eval or automatic HSTS.

## Forms, sessions and CSRF

`FormRequest::parse(Request)` accepts bounded URL-encoded bodies only, rejects
duplicate keys, bracket/array notation, malformed percent encoding, invalid UTF-8,
and excess fields. Keys are explicit ASCII identifiers. Unknown keys can be
ignored by validation; no parameter normalization or nesting. Multipart unsupported.
`validate()` returns typed values and field-keyed lists of errors. Lengths count
Unicode code points; numeric values must be finite. Request's body limit remains.

`Session` is optional and lazy, using PHP's native session extension only on form
routes. Strict IDs, cookie-only transport at application boundary, HttpOnly,
SameSite=Lax, explicit path, Secure by default, no domain attribute. Session
cookies are emitted explicitly on the Response, not hidden header side effects.
One session cookie suffices; Core's single-value header contract stays unchanged.
Local loopback HTTP requires explicit `secure: false`. Never infer TLS from
Forwarded/X-Forwarded-* headers. Native session cache headers/cookie emission are
disabled. Session storage must be private and GC configured by deployment.

`CsrfManager` stores 32 random bytes encoded as hex in session, compares with
`hash_equals`, rejects missing/invalid tokens with 403, and runs before validation
or state changes. Invalid submissions never mutate business state. Tokens stay
stable for multiple tabs. Close session promptly after handling requests.
CSRF does not replace XSS prevention, authentication, authorization, rate limits,
or TLS. GET only initializes security state on contact form, never business state.

Demo validates but never stores/sends feedback. Valid POST uses 303 to a distinct
success page; 422 re-renders errors; 403 rejects CSRF. Read-only pages/API neither
start sessions nor require DB. Demo HTML error rendering is application-owned;
Core retains its existing JSON errors and logging behavior.
