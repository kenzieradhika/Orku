# Core v0.1 architecture

## Web v0.2 extension

Optional `Orku\Web` adds trusted PHP rendering, explicit escaping, composition,
assets and protected URL-encoded forms. Core never imports these classes.
Kernel adds optional final `renderError(Throwable, Response): Response` callback
after existing safe error generation/logging; exceptions in callback fall back
to safe JSON. Default behavior and earlier constructor arguments stay unchanged.
CLI adds `fullstack-app` without replacing existing examples.
See [Web architecture](web-architecture.md) for exact contracts.

## Boundary and lifecycle

`SapiRuntime` captures a fresh immutable Request. `Kernel` dispatches through
`Router`; the handler returns an immutable Response. SAPI emits headers and body.
Neither router nor kernel reads superglobals or stores the current request.
Applications can call `Kernel::handle()` without a web server. Exceptions from
capture and handlers share `Kernel::error()` response formatting.

## Decisions

- Native PHP values, no runtime dependencies: keep installation and request path
  small. PHPUnit, PHPStan and formatter dependencies are development-only.
- Composer PSR-4 namespace `Orku\`: library lives in `src/`; examples stay outside.
- No PSR-7/15 claim: values intentionally use a smaller API for this milestone.
  A future interop adapter must preserve standards rather than pretend compliance.
- Whole-segment parameters, quoted literals, registration-time regex preparation:
  keep routing auditable. Static routes win, equivalent same-method route shapes
  fail early, ambiguous dynamic patterns use registration order. Matching is
  linear, not a compiled dispatcher; optimization requires benchmark evidence.
  Parameter names are ASCII identifiers, at most 32 bytes to fit PCRE limits.
- HEAD fallback invokes GET, then emitter suppresses body. Handlers must avoid
  side effects on GET/HEAD. Explicit HEAD is available through `Router::add`.
- Explicit body decoding: no eager JSON parsing for endpoints that do not need it.
  No form/session parsing package yet. SAPI uses PHP's query and cookie parsing.
- Request path is decoded once, plus signs remain plus signs. Reject dot segments,
  backslashes, encoded separators, control bytes and invalid UTF-8. Keep trailing
  slash significant; do not redirect unsafe methods automatically.
- Default errors are JSON for API-first behavior, not an HTML debug page.
  5xx logging is replaceable; reporter failures cannot replace client-safe errors.
- Response framing belongs to runtime; no streaming, informational responses or
  duplicate header values in this version. Responses are strings in memory.
- PHP SAPI is first adapter, not a generic interface with one implementation.
  Worker adapter and request-scope lifecycle arrive after dedicated isolation tests.

## Isolation and deployment

Library values do not leak route parameters between calls on a reused kernel.
This is not proof that user services or a future worker are isolated. No worker
loop is supplied. Configure web server document root to application `public/`
and rewrite application requests to `index.php`; never expose repository root.
PHP-FPM captures one request per process invocation of the entry point. Keep
`display_errors=Off`, `log_errors=On`, TLS at reverse proxy, request size/time
limits upstream. Specific Apache/Nginx/cPanel recipes await deployment testing.

## Next boundaries

v0.2 adds Orku Web: trusted PHP template files, automatically escaped data API,
explicit trusted HTML, layouts/components and an actual SSR form application.
v0.3 adds DI, middleware, groups/names, validation and CLI diagnostics. These
modules must not become unconditional database/frontend runtime dependencies.
