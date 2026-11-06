# Roadmap and limitations

## Web v0.2 local development milestone

Implemented optional SSR/helpers, layouts/components/named slots, assets,
URL-encoded validation, lazy session-backed CSRF and fullstack demo. See
`web-architecture.md`, `web-getting-started.md`, `web-security.md` and
`web-performance.md`. Local verification recorded separately; not a stable or
production release. Roadmap below retains original Core-era planning.

Next: authentication/session rotation lifecycle, rate limits, optional multipart
uploads, asset fingerprints, generators and production deployment validation.
No worker mode or SPA/hydration runtime in this milestone.

## v0.1: Core

Implemented: Composer setup, immutable Request/Response, SAPI adapter, kernel,
safe JSON errors and logging hook, literal/dynamic routes, method helpers, HEAD,
404/405, CLI serve/routes/help, Hello World and REST API, automated tests and
development checks. Linear routing is baseline, not a performance conclusion.

## v0.2: Orku Web (planned)

PHP-based SSR view API, escaped ordinary interpolation, explicit trusted HTML,
layouts, components/slots, safe asset URLs and full-stack example with navigation,
accessible responsive pages, server validation and CSRF-protected forms. Optional
progressive JavaScript only. No JSX/template compiler or whole-page hydration.

## v0.3: Developer experience (planned)

Constructor DI and explicit lifetimes, ordered short-circuiting middleware,
route groups/names, typed validation, Doctor, generators and test CLI. Sessions,
cookie handling and CSRF must be tested before browser state-changing examples.
Authentication/rate limiting remain optional adapters, not pretend implementations.

## v0.4: Data (planned)

Independent PDO package, prepared queries, transactions, migrations, result
mapping and timing. No mandatory ORM or DB bootstrap in HTTP core.

## v0.5: Performance (planned)

Only evidence-backed route/container compilation, worker integration with scoped
state reset and leak tests, HTTP concurrency benchmark automation. Compare pinned
Laravel, Symfony, lightweight PHP and Go workloads fairly. No speed ranking yet.

## Production readiness (planned)

Configuration validation, deployment tests, security review, observability opt-in,
versioning/deprecation policy, API compatibility testing and broader documentation.
CI config alone is not evidence of a successful hosted CI run.

## Explicit limitations

- PHP 8.3 and older unsupported. No stable release, Packagist publication or tags.
- No named/grouped routes, middleware, DI, views, static asset server, DB or worker.
- No streaming, file uploads, duplicate Set-Cookie headers, automatic OPTIONS/CORS.
- Only full-segment route placeholders; no optional segments or custom regex API.
- Dynamic patterns with differing shapes can overlap; registration order decides.
- CLI serves repository examples, not a project generator or arbitrary app runner.
- Apache, Nginx/PHP-FPM and shared hosting recipes not integration-tested yet.
- ESP32/ESP8266 cannot run full framework; future independent C/C++ SDK may use
  HTTP/MQTT contract. It adds no code or dependencies to Core.
