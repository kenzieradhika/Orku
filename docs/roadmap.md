# Roadmap and implementation status

Current milestone: **v0.2-dev**, not a stable or production-ready release.
This page is the canonical implementation-status table. [ORK.md](../ORK.md)
contains the longer upgrade proposal; its examples and feature names are plans,
not available APIs. Update this table when a feature ships.

## Implementation status

| Area | Implemented | Not implemented |
| --- | --- | --- |
| HTTP/core | Immutable Request/Response, headers, kernel, SAPI, safe JSON errors, reporting hook | Streaming, duplicate Set-Cookie values, automatic OPTIONS/CORS |
| Routing | Static/dynamic routes, method helpers, HEAD fallback, 404/405, named routes, nested prefix groups, safe URL generator | Constraints API, route middleware, compiled routing |
| Web | Trusted PHP SSR, explicit escaping, layouts, partials, components, slots, assets | Loom `.ork` parser/compiler, islands, streamed HTML |
| Forms | URL-encoded parsing, typed validation, lazy session-backed CSRF | Multipart uploads, authentication, session rotation workflow, rate limiting |
| CLI | `help`, `serve`, `routes`, read-only `doctor`; three runnable examples | Project generators, `test`, `benchmark`, `optimize`, ThinkCore commands |
| Application services | Explicit handler construction; optional scoped container, ordered callable HTTP middleware, PDO prepared-query/transaction foundation (SQLite tested) | Autowiring, route middleware, standalone data package, migrations |
| Runtime | SAPI adapter, local CLI-server integration tests | Persistent workers, worker lifecycle/reset contracts, resource auto-tuning |
| Verification | Unit/security/live HTTP tests, PHPStan, formatting, dependency audit in CI | Production deployment validation, concurrent production-like comparison suite |
| Optimization proposals | Existing kernel/view microbenchmarks | ThinkCore, ScopeWeave, FluxCore, MemBound, TraceDNA, RouteMorph, FailMesh, QueryPulse, DriftGuard, SafeRuntime, SmartData, NativeBridge |

TurboKernel is proposed branding for the existing small kernel and future
extensions, not a separate implemented optimization system. Doctor is a proposed
CLI feature. No comparative performance guarantees are available.

## Completed development milestones

### v0.1: HTTP core

Composer setup, immutable HTTP values, SAPI adapter, kernel, safe JSON errors,
literal/dynamic routes, method helpers, HEAD, 404/405, CLI commands, Hello World
and REST API examples, automated checks. Dynamic matching remains linear.

### v0.2: Optional PHP web layer

PHP SSR, explicit escaping (not automatic escaping of arbitrary PHP output),
layouts/components/slots, static assets, validated URL-encoded forms, session-backed
CSRF, and the fullstack example. No Node.js, compiler, or hydration requirement.
See [Web architecture](web-architecture.md) and [getting started](web-getting-started.md).

## Proposed releases

These are sequencing goals, not release dates or guarantees. Each change requires
tests, documentation, compatibility review, and measurements for performance claims.

| Milestone | Proposed scope |
| --- | --- |
| v0.3 | Documentation reconciliation, middleware/scoped DI, route groups/names, safe URL generation, Doctor; Loom grammar proof of concept and read-only ThinkCore contracts |
| v0.4 | Optional PDO/migrations, Loom compiler/escaping/cache foundation, request-scope lifecycle, ThinkCore recommendations, deployment validation |
| v0.5 | Benchmark-justified routing/container optimization, experimental workers, memory budgets, state isolation and soak tests |
| v0.6–v0.8 | Optional resilience/observability, guarded opt-in runtime tuning, later Loom features subject to demand |
| v0.9 | API/security review, compatibility policy, reproducible benchmarks, deployment/rollback runbooks |
| v1.0 | Stable documented API and release, with readiness evidence only for verified runtimes |

Native acceleration remains optional and does not block v1.0. ThinkCore starts
read-only; no automatic production configuration changes. Existing PHP templates
remain supported when the optional `.ork` layer arrives.

## Immediate backlog

1. Reconcile documentation and preserve the master proposal in `ORK.md`.
2. Establish reproducible HTTP/memory benchmarks with machine-readable results.
3. Specify and test scoped DI, then middleware and named/grouped routes.
4. Implement Doctor with redacted diagnostics and stable exit codes.
5. Approve Loom grammar/escaping and ThinkCore resource-boundary RFCs before implementation.

Use one coherent change per branch. Run local checks before push, then verify CI.
Do not treat proposed features or historical measurements as implementation evidence.

## Explicit limitations

- PHP 8.3 and older unsupported; no stable API compatibility promise.
- No worker mode, autowiring, route middleware, cross-driver migrations, or Loom compiler yet.
- Explicit SQLite-only migrations are experimental; see [migration boundaries](migrations.md).
- No streaming, uploads, duplicate Set-Cookie values, automatic OPTIONS/CORS.
- Route placeholders occupy whole segments; overlapping dynamic routes use registration order.
- CLI serves repository examples, not arbitrary applications or generated projects.
- PHP-FPM/Apache, Nginx, shared hosting, and ARM deployment remain unverified here.
- PHP Orku does not run natively on ESP32/ESP8266; an independent IoT SDK is only a proposal.

See [verification](verification.md), [security](security.md), and
[benchmark methodology](../benchmarks/README.md) for evidence and boundaries.
