# Security considerations

Web v0.2 adds optional URL-encoded parsing, validation, lazy native sessions and
synchronizer CSRF. See [Web security](web-security.md). Statements below about
missing forms/session/CSRF describe historical Core-only boundary, not Web.
Core restrictive headers and stateless default remain unchanged.

Orku v0.1 is pre-release. Tests cover listed boundaries, not a full security audit.
Do not interpret secure defaults as authentication, production certification or
protection against arbitrary hostile PHP code.

## Threat assumptions

HTTP clients are untrusted. Application PHP files, handlers, route registrations,
logging callbacks and template HTML are trusted code. Web server/PHP SAPI own
HTTP message framing, TLS, connection limits and header parsing. Duplicate raw
request headers may already be combined before Orku sees them. No protection
against compromised application code or a malicious reverse proxy is claimed.

## Tested controls

- Request method and header validation, UTF-8 paths, single decoding, rejection of
  traversal, control bytes, malformed percent escapes and encoded slash/backslash.
- Request body limit defaults to 1 MiB, bounded input read and declared-length
  precheck. Configure upstream limits: PHP or proxy may buffer bytes before capture.
- JSON parsing throws HTTP 400 on invalid input and 415 for wrong media type.
- Response header injection, duplicate case-insensitive headers, forged content
  length/transfer encoding and invalid statuses rejected before emission.
- Production errors use standard messages, never supplied exception messages.
  5xx exceptions go to configurable reporter; protect log files and avoid putting
  secrets in exception messages. Debug exposes paths and traces; keep it private.
  Error rendering ignores exception framing headers and substitutes malformed
  UTF-8 diagnostic bytes rather than failing while handling an exception.
- Adapter discards ordinary handler echo output, defaults to nosniff, no-referrer
  and restrictive CSP. Trusted code can bypass output buffers; no PHP sandbox.
- CLI validates example names and bind addresses, invokes PHP using argument
  arrays (or pcntl_exec), and defaults to loopback. `0.0.0.0` exposes server to
  network; do not use development server on public networks.
- Reused kernel route parameter isolation tested; persistent worker safety is not
  implemented or claimed.

## Application responsibilities and gaps

- `Response::html()` is a trusted-string transport, **not escaping**. Until v0.2,
  escape ordinary HTML text/attribute data with
  `htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` and separately
  validate URLs. Do not interpolate untrusted JS/CSS; escaping is context-specific.
- No sessions, CSRF tokens, authentication, authorization, CORS, rate limiting,
  file upload parser or general validation package. Do not build cookie-authenticated
  state-changing browser forms until CSRF/session controls exist. JSON API sample
  has no persistence or private data; it is not an auth example.
- Redirect destinations must be application-controlled or allowlisted. Header
  validation blocks CRLF, not open redirects to external origins.
- SAPI accepts PHP-parsed query/cookie values; repeated query keys follow PHP
  semantics. No trusted proxy handling; client forwarding headers stay untrusted.
- Request size limit covers raw body, not headers, query strings or response size.
  Set ingress header/URI, upload, timeout and memory limits. Multipart and form
  handling are unsupported; set `enable_post_data_reading=0` for raw-body operation.
- Response security headers are configurable for application needs. No HSTS by
  default without knowledge of HTTPS policy. Future static assets require CSP
  configuration; current `default-src 'none'` intentionally allows no scripts/assets.
- Fatal startup/parse errors before runtime capture are outside Kernel handling.
  Set `display_errors=Off` in production PHP configuration, not just application code.

Report security issues privately to repository maintainers through GitHub private
vulnerability reporting if enabled. Do not publish secrets or exploit live systems.
