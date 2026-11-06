# ORK.md — Orku Framework Master Upgrade & Modernization Plan

> **Project:** Orku — Optimized Runtime Kernel Utility  
> **Repository:** https://github.com/kenzieradhika/Orku  
> **Planning baseline:** `main`, README `v0.2-dev`, inspected 7 November 2026  
> **Document status:** Architecture proposal / implementation roadmap (not an implementation report)  
> **Primary language:** PHP 8.4+  
> **Template format decision:** `.ork` (Orku Loom)  
> **Vision:** Fast where measured, lean where possible, secure by default, adaptive when safe.

---

## 1. Executive summary

Orku aims to become a **high-performance, modular, developer-friendly PHP framework** with a small HTTP core and optional full-stack capabilities. Its differentiator is not a blanket claim of being faster than Laravel, Go, or Rust. Orku should instead pursue **measurable advantages for defined workloads** and improve PHP developer ergonomics, operational safety, performance visibility, and resource management.

The headline innovation proposal is **Orku ThinkCore**, a safe hardware- and workload-aware optimization controller. Other Orku-named technologies work alongside it; **Orku Loom** introduces a `.ork` template DSL compiled to cached PHP.

**Non-negotiables:** no unverified performance promises; no unsafe implicit changes to production configuration; no framework-wide dependency on Rust, Node.js, Redis, or a database; full automated regression testing; backwards-compatibility reviews; and no accidental cross-request state leaks.

### 1.1 Outcomes

1. Keep a tiny, deterministic PHP HTTP kernel with clear extension points.
2. Offer **Micro**, **API**, and **Fullstack** installation/application presets using the same modular core.
3. Make error handling, state isolation, secret handling, and secure defaults first-class.
4. Support optional high-efficiency persistent workers with tested lifecycle boundaries.
5. Add the `.ork` template language and a secure, cached compiler.
6. Equip ThinkCore with observability, safe recommendations, and (later) bounded auto-tuning.
7. Publish reproducible comparisons against native PHP, Laravel, lightweight PHP frameworks, and Go/Rust workloads when appropriate.

## 2. Verified starting point and gaps

Based on the repository's README, source folder listing, benchmarks, and architecture/roadmap documents at inspection time:

| Area | Current repo status | Needed action |
|---|---|---|
| PHP version | PHP `^8.4`, JSON extension | Preserve and test PHP 8.4/8.5; consider compatibility only with evidence |
| HTTP | `src/Http/Request.php`, `Response.php`, `Headers.php` | Strengthen streaming, cookie headers, typed parsing, safe limits |
| Core | `src/Core/Kernel.php` | Add optional pluggable pipeline and lifecycle instrumentation |
| Router | `src/Routing/Router.php`; linear dynamic matching | Route groups, names, constraints, caching, measured dispatch optimization |
| Runtime | `src/Runtime/SapiRuntime.php` | Production deployment recipes; optional worker adapter with isolation tests |
| Web | `ViewRenderer`, `ViewContext`, `TrustedHtml`, `Session`, `CsrfManager`, etc. | Preserve existing PHP SSR; implement Loom in a separate optional layer |
| CLI | `bin/orku` for examples, help, routes | `new`, `doctor`, `test`, `benchmark`, `optimize`, ThinkCore commands |
| Tests | Unit, Integration, Security, Support | Add load, leak, concurrency, fuzz/property-based, cross-runtime checks |
| Benchmarks | In-process and local CLI HTTP tests | Production-like concurrent benchmarks, RSS/CPU/P95/P99, reproducible harness |
| Database | Not yet provided | Optional PDO package, transaction support, query profiler, migrations |
| Middleware/DI | Planned | Implement explicitly scoped dependency container and middleware |
| `.ork` DSL | Not implemented | Design parser, compiler, safe syntax, caching and debugging |
| ThinkCore/other coined systems | Concept only | Introduce as RFCs and implement incrementally |

**Documentation reconciliation:** `docs/roadmap.md` retains historical v0.1/v0.2 wording, including items now present in Web v0.2. Update it to clearly identify implemented vs proposed features. Never treat this plan as evidence a proposed feature works.

## 3. Problems we want to solve — correctly scoped

### 3.1 PHP limitations and Orku mitigations

| Challenge | What Orku can do | What Orku cannot promise |
|---|---|---|
| Traditional per-request initialization | Cache config/routes, use OPcache, offer optional persistent worker runtime | Remove all PHP interpreter overhead |
| Dynamic type surprises | Typed contracts/DTOs, strict code style, PHPStan, explicit validation | Turn PHP into Rust's compile-time type/ownership model |
| Memory overhead and leaks | Lazy dependencies, small value objects, scope cleanup, RSS limits, recycling | Rust-like deterministic memory ownership across arbitrary PHP code |
| Concurrency and I/O | Adapters for async/event-loop capable runtimes; backpressure | Automatic non-blocking behavior for every PHP extension |
| Deployment complexity | `doctor`, config validation, secure presets, FPM/Apache/Nginx guides | Full control of shared-hosting process manager settings |
| Runtime packaging | Streamlined Composer deployment, potential future launcher | Native standalone executable from PHP without extra toolchain/runtime |

### 3.2 Laravel trade-offs and differentiation

Laravel already provides dependency injection, route caching, extensive authentication/database tooling, and persistent workers through Octane. Therefore, **do not market these as novel inventions**. Orku differentiates by:

- Small core with optional packages rather than mandatory full-stack bootstrap.
- Explicit scopes and worker reset verification as a documented contract.
- Hardware-aware *recommendations* tied to reliable measurements and safeguards.
- Fine-grained runtime and memory budgeting with auditable policies.
- Zero-surprise configuration and detailed `doctor` diagnostics.
- A minimal, safe `.ork` compiler with explainable rendering costs.

Comparison claims must be accompanied by benchmark scripts, exact versions, and source data.

## 4. Product architecture

```text
Orku Application (Micro / API / Fullstack)
             |
Orku TurboKernel (HTTP, routing, pipeline)
             |
 +-----------+----------------------+--------------+
 |           |                      |              |
ScopeWeave  Loom (.ork)       SmartData         CLI/Doctor
 |           |                      |              |
 +-----------+----------+-----------+--------------+
                        |
                  ThinkCore control
             (Sense / Adapt / Predict /
               Guardian / Learn)
                        |
       Runtime adapters: PHP-FPM / SAPI / Worker
                        |
           Optional NativeBridge (Rust/C)
```

**Separation of concerns:** ThinkCore does not own application business logic. It reads only allowed telemetry, recommends or applies bounded runtime settings, and records decisions. Feature modules must function when ThinkCore is disabled.

### 4.1 Proposed module directories (subject to RFC)

```text
src/
  Core/            # Kernel and lifecycle contracts
  Http/            # Request, Response, headers, streaming
  Routing/         # Router, route definitions, compile/cache
  Runtime/         # SAPI and optional worker adapters
  Web/             # Existing PHP SSR and form utilities
  Container/       # Scoped dependency injection
  Middleware/      # Ordered HTTP pipeline
  Loom/            # Optional .ork parser/compiler/renderer
  ThinkCore/       # Optional hardware/workload intelligence
  Diagnostics/     # Doctor and reporting
packages/          # Optional modules, introduced only if needed
  data/            # PDO/migrations/query monitoring
  cache/           # Cache abstractions/adapters
  auth/            # Authentication/session integration
  native/          # Optional native bridge
```

Do **not** reorganize working code merely to match the diagram. Preserve namespaces or provide explicit deprecation paths.

## 5. Orku technology catalog — 14 main technologies

Names below are proposed Orku branding and feature specifications, **not verified globally unique inventions or existing implementations**.

| # | Proposed term | Purpose | Delivery priority |
|---|---|---|---|
| 1 | **ThinkCore** | Hardware/workload-aware safe optimization controller | P1 prototype; P2 auto mode |
| 2 | **ScopeWeave** | Request-scope isolation and lifecycle verification | P1 |
| 3 | **FluxCore** | Measured optimizations for execution pipeline | P2 |
| 4 | **MemBound** | Memory budgets and growth control | P1/P2 |
| 5 | **TraceDNA** | Behavioral and performance regression fingerprints | P2 |
| 6 | **RouteMorph** | Compiled/optimized routing strategy | P1 |
| 7 | **FailMesh** | Failure handling, bounded retries, circuit breakers | P2 |
| 8 | **QueryPulse** | Database query cost and N+1 monitoring | P2 |
| 9 | **DriftGuard** | Runtime/configuration drift detection | P2 |
| 10 | **SafeRuntime** | Worker/process safety policies and resource lifetimes | P1/P2 |
| 11 | **TurboKernel** | Small, fast, deterministic HTTP kernel | P0/P1 |
| 12 | **Doctor** | Environment, security, compatibility and performance diagnostics | P1 |
| 13 | **SmartData** | Lightweight optional data access layer | P2 |
| 14 | **NativeBridge** | Optional native acceleration where profiling justifies it | P3 |

**Additional product name:** **Orku Loom**, the `.ork` template engine (section 7), is an important distinct product component, not counted in the historical 14-name list.

### 5.1 ThinkCore subcomponents

- **Sense:** Detect effective logical CPU quota/affinity, available RAM, cgroup limits, PHP runtime, OPcache/JIT, process RSS, and capabilities. Treat hardware data as potentially incomplete, and never require root.
- **Adapt:** Generate recommendations for bounded worker count, memory budgets, queue/backpressure thresholds, and cache sizes. Only apply settings the active runtime adapter explicitly supports.
- **Predict:** Forecast overload risk from rolling measurements; start with simple statistics rather than an LLM.
- **Guardian:** Enforce guardrails: min/max, cooldowns, rollback, manual override, circuit breakers, and audit trails.
- **Learn:** Persist aggregate tuning outcomes (excluding secrets and PII) to improve future recommendations.

### 5.2 ThinkCore execution modes

| Mode | Behavior | Initial default |
|---|---|---|
| `observe` | Collect minimal opt-in telemetry; no config mutation | **Yes** |
| `recommend` | Emit suggested changes for operator approval | Optional |
| `auto` | Apply **only** allowlisted, reversible settings | Disabled until production-tested |

**ThinkCore policy requirements:**

- Respect container cgroup quota over physical host totals.
- Reserve RAM for OS, database, and other services; do not size from total RAM alone.
- Use observed worker RSS and latency, not CPU count alone, for sizing.
- Never change PHP-FPM, OS, or container resource limits without explicit external integration and operator consent.
- Prevent oscillation with hysteresis and cooldown.
- Reject risky tuning under insufficient samples or elevated error rates.
- Offer reproducible decision logs and a dry-run mode.
- Require capability negotiation from runtime adapters; fail safe.

### 5.3 Proposed ThinkCore public interfaces (illustrative only)

```php
$thinkCore = ThinkCore::for($runtime)
    ->mode('observe')
    ->resourcePolicy(memoryReservePercent: 25)
    ->guardrails(maxWorkers: 8, cooldownSeconds: 300);

$report = $thinkCore->analyze();
```

These APIs are **not yet implemented**.

## 6. Upgrade requirements by subsystem

### 6.1 TurboKernel and HTTP [P0–P1]

- [ ] Define public HTTP contracts and a stable exception/error model.
- [ ] Keep request parsing lazy and body limits explicit.
- [ ] Properly support multiple `Set-Cookie` header values (without merging improperly).
- [ ] Add safe stream response abstraction with chunking/backpressure only after runtime support is verified.
- [ ] Add explicit OPTIONS/CORS configuration (deny-by-default where appropriate; avoid accidental wildcard credentials).
- [ ] Add structured logs with request IDs and redact secrets.
- [ ] Preserve current SAPI behavior and test HEAD/405/404 invariants.
- [ ] Measure allocations and dispatch overhead before introducing new abstractions.

**Definition of done:** no protocol regressions; unit/integration/security checks pass; startup and dispatch benchmarks published.

### 6.2 RouteMorph [P1]

- [ ] Named routes, groups, prefixes, middleware association, parameter constraints.
- [ ] Separate exact/static lookup from dynamic dispatch.
- [ ] Compile route metadata at build/deploy time; invalidate caches on code change.
- [ ] Explicit rules for ambiguous paths, precedence, trailing slashes, path decoding.
- [ ] Generator for URLs that encodes parameters safely.
- [ ] Benchmarks: 10/100/1,000/10,000 route cases, static/dynamic mixes.

**Definition of done:** route semantics verified, compile cache invalidation tested, net benefit demonstrated for target route sets.

### 6.3 Middleware and dependency injection [P1]

- [ ] Explicit middleware order and short-circuit response semantics.
- [ ] DI lifetimes: singleton, worker, request, transient; reject inappropriate lifetime captures.
- [ ] Constructor injection and optional compile-time container metadata.
- [ ] Request-scope disposal/release hooks, tested in exception and cancellation paths.
- [ ] Interoperability adapters only when standards are fully honored.

### 6.4 ScopeWeave, SafeRuntime, MemBound [P1–P2]

- [ ] Register and dispose request-scoped objects deterministically where possible.
- [ ] Simulate users A → B → A through the same persistent worker; assert no cross-user state retention.
- [ ] Detect unexpected persistent service mutations using explicit tracked fields, not arbitrary reflection of private secrets.
- [ ] Enforce configurable RSS/process/worker-lifetime budgets with graceful recycle.
- [ ] Track exceptions, open handles, transactions, and cleanup failures.
- [ ] Support worker shutdown/drain and limits for in-flight requests.
- [ ] Avoid claiming comprehensive memory leak detection or ownership semantics.

### 6.5 FluxCore [P2]

- [ ] Profile bottlenecks before optimizing.
- [ ] Reduce redundant middleware/container allocations with measured results.
- [ ] Explore fast paths for trusted precompiled routing and static responses.
- [ ] Preserve identical security checks and response semantics in all fast paths.
- [ ] Publish benchmark gains together with trade-offs (memory, latency tails, startup).

### 6.6 SmartData and QueryPulse [P2]

- [ ] Separate PDO package, parameterized queries, transactions, migration runner.
- [ ] Provide predictable transaction rollback and explicit connection lifetimes.
- [ ] Prepared statements by default; no unsafe string interpolation of values.
- [ ] Slow-query and N+1 detection in development; sampling in production.
- [ ] Expose query cost without logging credentials or full sensitive SQL bindings.
- [ ] Do not require ORM in Micro/API modes.

### 6.7 FailMesh, TraceDNA, DriftGuard [P2]

- [ ] Typed failures, timeouts, idempotency-aware retry policies, jitter/backoff, and circuit breakers.
- [ ] No automatic retry of unsafe/non-idempotent writes without explicit idempotency handling.
- [ ] TraceDNA: baseline response shape, latency distribution, resource consumption, and failure behavior (privacy-safe sampling).
- [ ] DriftGuard: detect config drift, runtime capabilities changes, unexpected disabled security options; emit auditable alerts.
- [ ] Observability adapters opt-in and low-overhead by default.

### 6.8 Doctor & developer experience [P1]

- [ ] `php bin/orku doctor`: PHP version/extensions, OPcache, permissions, document root, debug mode, secure cookies, CI/runtime compatibility.
- [ ] `php bin/orku new <project> --preset=micro|api|fullstack`.
- [ ] `php bin/orku test`, `routes`, `benchmark`, `optimize` with stable exit codes.
- [ ] Helpful exceptions with source mapping but no production secret disclosure.
- [ ] Versioned starter apps and quickstarts in Indonesian and English; Chinese optional later.
- [ ] Editor support, completions, and syntax highlighting for `.ork`.

### 6.9 NativeBridge [P3]

- [ ] Benchmark overhead of PHP FFI versus native extension against real CPU-intensive tasks.
- [ ] Build optional Rust/C acceleration only for verified hotspots.
- [ ] Preserve pure-PHP fallback and feature parity.
- [ ] Review ABI, build matrix, releases, memory safety, and supply-chain security.

## 7. Orku Loom — `.ork` native template engine

**Final format choice:** `.ork`. Do not use `.blade.php` or `.loom.php` as the public template extension. Existing trusted PHP templates remain supported during migration.

### 7.1 Goals

- Readable HTML-first syntax, safe default HTML escaping, explicit components/layouts/slots.
- Compile `.ork` source to cached PHP **outside the public web root**; do not interpret arbitrary template input as executable PHP.
- Never require Node.js to render server-side templates.
- Useful source-mapped compile errors; deterministic cache invalidation.
- Optional islands, streaming, and profiling only after the basic compiler is stable.

### 7.2 Proposed directory layout

```text
resources/views/
  layouts/app.ork
  components/button.ork
  components/alert.ork
  pages/home.ork
  pages/dashboard.ork
storage/cache/loom/       # generated PHP; not web-accessible
```

### 7.3 Proposed template syntax (RFC, not implemented)

```html
@layout('layouts.app')
@title('Dashboard')

<main>
  <h1>Hello, {{ user.name }}</h1>
  @if(user.isAdmin)
    <ork:alert type="success">Welcome, Admin!</ork:alert>
  @endif
  @each(product in products)
    <ork:card>
      <h2>{{ product.name }}</h2>
      <p>{{ product.price }}</p>
    </ork:card>
  @endeach
</main>
```

**Compiler restrictions:**

- `{{ expr }}` HTML-escapes by default; support only an explicitly specified, safe expression grammar.
- Different output contexts (HTML text, attribute, URL, JS, CSS) need appropriate handling. Initially **forbid interpolation in JS/CSS and unsafe URL contexts**, rather than relying on HTML escaping alone.
- Raw HTML requires a trusted typed value and explicit directive; untrusted input must never become trusted through casting.
- Prevent path traversal in includes and component resolution.
- Separate template compilation from rendering, and precompile templates at deploy time.
- No arbitrary `eval` and no direct execution of untrusted user-supplied templates.
- Use lexical parsing/AST validation; do not compile a language with naive regex substitutions.

### 7.4 Loom feature names and order

| Feature | Description | Phase |
|---|---|---|
| **LoomCompile** | AST compiler to cached PHP | v0.3/v0.4 |
| **LoomGuard** | Safe expression grammar and context-aware escaping | v0.3/v0.4 |
| **LoomProfile** | Development-only per-component render timing | v0.5 |
| **LoomAdapt** | Safe ThinkCore cache/buffer recommendations | v0.6+ |
| **LoomStream** | Streamed HTML with bounded buffers | v0.6+ |
| **LoomIsland** | Opt-in JS islands, no full-page hydration dependency | v0.7+ |

### 7.5 Loom acceptance tests

- [ ] Basic text/attribute escaping and rejection of dangerous injection cases.
- [ ] Template include/slot/layout correctness and path traversal protection.
- [ ] Compile-time diagnostics preserve `.ork` file and line numbers.
- [ ] Recompilation triggers on changes and respects production cache settings.
- [ ] Render output matches fixtures and remains stable across PHP versions.
- [ ] Benchmark warm/cold render, allocated memory, RSS, and throughput against existing `ViewRenderer`.

## 8. Security baseline and hardening

- [ ] Security threat model for HTTP request boundaries, worker state, template compiler, sessions, and DB.
- [ ] CSRF for browser state changes; validation is not a substitute for authorization.
- [ ] Standard authorization and authentication package as optional module, with robust session rotation.
- [ ] Secure cookie defaults; CSP/security headers configurable without unsafe blanket exceptions.
- [ ] Request size/time/field-count limits, upload restrictions, and safe response header handling.
- [ ] No stack traces or secrets in production responses.
- [ ] Supply-chain checks, dependency scanning, secret scanning, static analysis, security regression suite.
- [ ] Audit logs with redaction and data retention settings.
- [ ] No default wildcard CORS with credentials.
- [ ] Worker stress tests cover shared static/singleton state and request-local storage.

## 9. Benchmark and verification plan

### 9.1 Baseline observations (historical; not new testing)

`docs/web-performance.md` records 25 October 2026 measurements on Linux x86_64, PHP 8.4.21, PHP CLI server, with concurrency one. One case recorded approximately **3.437 microseconds** mean in-process JSON kernel work; an HTTP core status endpoint recorded approximately **4.401 milliseconds** mean sequential local response time. These are different boundaries. They do **not** establish production throughput or competitiveness with Go/Rust/Laravel.

### 9.2 Reproducible matrix

Compare same hardware, OS/kernel, compiler/runtime flags, versions, worker count, request payloads, concurrency, duration, keepalive settings and client. Include:

- Native PHP + FPM baseline.
- Orku + FPM.
- Orku + persistent worker when available.
- Laravel + FPM and optionally Octane, clearly separated.
- A lightweight PHP framework.
- Go `net/http` reference application; Rust Axum/Actix reference if appropriate.

Scenarios: static/plain text response, JSON encode, dynamic routing, validation, templating, authenticated API, database read/write, slow downstream I/O. For each, report **requests/second, error rate, P50/P95/P99, CPU, peak RSS, sustained RSS slope, startup time, GC effects where relevant, and test setup**.

Use tools such as `wrk`, `k6`, or `oha` as appropriate, and release commands, fixtures and raw JSON/CSV results. Optimize only after measuring regressions, and never compare in-process dispatches/second to real HTTP requests/second.

### 9.3 Performance gates (relative, not absolute claims)

- [ ] No statistically significant regressions beyond an explicitly agreed tolerance in core dispatch.
- [ ] No unbounded RSS growth in soak tests at target load.
- [ ] Tail-latency and error-rate stay within agreed SLOs under sustained concurrency.
- [ ] New optimized path is benchmarked with its safety checks enabled.
- [ ] Document when Orku is slower or uses more RAM than alternatives.

## 10. Project presets and deployment

| Preset | Modules | Target environment |
|---|---|---|
| **Orku Micro** | Kernel, Router, HTTP | Simple API, webhook, low-resource VPS |
| **Orku API** | Micro + middleware, DI, validation, optional DB/auth | Business APIs, microservices |
| **Orku Fullstack** | API + Loom/SSR, forms, session/CSRF, assets | Fullstack websites |

Deployment recipes: Linux + Nginx/PHP-FPM; Apache/shared hosting; Docker; ARM Linux/Raspberry Pi. Always point document root to application `public/`. For ESP32/ESP8266, plan a separate lightweight C/C++/Rust **Orku IoT SDK** communicating over HTTP/MQTT; do not claim PHP Orku itself runs natively on microcontrollers.

## 11. Proposed staged release plan

### v0.3 — Reliability & developer foundations [P1]

- [ ] Reconcile README, roadmap, architecture and current code status.
- [ ] Middleware and scoped DI with exhaustive integration tests.
- [ ] Route groups/names, safe URL generator, baseline compiled-router prototype.
- [ ] `orku doctor`, simple generators and dev workflow improvements.
- [ ] Loom `.ork` lexer/parser proof of concept with secure expression subset.
- [ ] Define ThinkCore Sense interfaces and observe-only report.
- [ ] Expand protocol/security tests and baseline benchmark harness.

**Exit gate:** all checks pass; no known worker/state assumptions disguised as supported behavior; documentation synchronized.

### v0.4 — Data, Loom foundation & isolation [P1/P2]

- [ ] SmartData PDO foundation, migrations, prepared statements.
- [ ] LoomCompile/LoomGuard (layouts, components, escaping, cache).
- [ ] ScopeWeave request-scope resource lifecycle.
- [ ] ThinkCore Sense and recommendation engine with no automatic changes.
- [ ] Deployment tests on PHP-FPM and at least one ARM Linux target if available.

### v0.5 — Measured performance [P2]

- [ ] RouteMorph and container compilation if benchmarks justify.
- [ ] SafeRuntime persistent-worker **experimental** adapter.
- [ ] MemBound budgets, leak/soak tests, graceful worker recycling.
- [ ] FluxCore targeted optimizations and reproducible performance reports.
- [ ] ThinkCore Adapt recommends tuned settings; Guardian guardrails implemented.

### v0.6–v0.8 — Operational features [P2]

- [ ] FailMesh, TraceDNA, DriftGuard, QueryPulse.
- [ ] Configurable worker autoadjustment only for supported runtimes, behind explicit opt-in.
- [ ] LoomProfile, LoomStream, LoomIsland subject to demand and benchmarks.
- [ ] Auth/session hardening, observability, optional adapters, migration guides.

### v0.9 — Release candidate hardening

- [ ] Security review including fuzz tests, threat model and dependency audit.
- [ ] Semver and compatibility guarantees; API review.
- [ ] Benchmarks reproducible on shared CI hardware and documentation.
- [ ] Production-grade deployment and rollback runbooks.

### v1.0 — Stable

- [ ] Stable documented public API, tagged release, Composer distribution and examples.
- [ ] Production readiness evidence for supported runtimes only.
- [ ] Publish clear limitations and unimplemented/experimental flags.
- [ ] NativeBridge remains optional and **need not block v1.0**.

## 12. Immediate issue / PR backlog (suggested order)

| Rank | Proposed issue | Main files/areas | Acceptance |
|---|---|---|---|
| 1 | `docs: reconcile v0.2 status and create canonical roadmap` | `README.md`, `docs/roadmap.md`, `ORK.md` | No stale implemented/planned contradictions |
| 2 | `test: establish reproducible HTTP benchmark baseline` | `benchmarks/`, `.github/` | Reproducible scripts and machine-readable output |
| 3 | `feat: request-scoped dependency container` | `src/Container/`, `tests/` | Correct lifecycle and leak prevention tests |
| 4 | `feat: middleware pipeline` | `src/Middleware/`, `src/Core/` | Ordered, short-circuit, exception path verified |
| 5 | `feat: named/grouped routes` | `src/Routing/` | Compatibility/precedence tests |
| 6 | `feat: orku doctor` | `src/Console/`, `bin/orku` | Environment check, redacted output, stable exit codes |
| 7 | `rfc: Loom .ork grammar and compiler security model` | `docs/rfcs/` | Grammar, escaping rules, adversarial test plan |
| 8 | `feat: Loom compiler minimum viable implementation` | `src/Loom/`, `tests/` | Escaping, layouts, cache and source maps |
| 9 | `rfc: ThinkCore Sense and adapter capability interface` | `docs/rfcs/` | Read-only detection, limits and fallback |
| 10 | `feat: ThinkCore observe-only prototype` | `src/ThinkCore/` | Correct cgroup/resource reporting and no mutations |
| 11 | `feat: PDO data package` | `packages/data/` | Safe query and transaction tests |
| 12 | `experiment: worker adapter with ScopeWeave` | `src/Runtime/`, `tests/` | Cross-request privacy and soak tests |

**Workflow:** one coherent feature per branch, tests and docs in each PR, benchmark reports for performance PRs, review before merging to `main`.

## 13. Development and release quality gates

Run existing commands and add new coverage progressively:

```bash
composer validate --strict
composer check
php benchmarks/kernel.php 10000
php benchmarks/web.php 10000
```

Future commands below are **planned**, not currently implemented:

```bash
php bin/orku doctor
php bin/orku thinkcore:scan
php bin/orku thinkcore:analyze
php bin/orku thinkcore:observe
php bin/orku optimize --dry-run
php bin/orku loom:compile
```

Each proposed feature must specify: threat model, API contract, benchmark baseline, unit/integration tests, failure behavior, documentation, compatibility notes, and rollback plan.

## 14. Feature governance and naming

- Official main terms: **ThinkCore, ScopeWeave, FluxCore, MemBound, TraceDNA, RouteMorph, FailMesh, QueryPulse, DriftGuard, SafeRuntime, TurboKernel, Doctor, SmartData, NativeBridge**.
- Official template-engine proposal: **Orku Loom**, file extension **`.ork`**.
- ThinkCore subsystems: **Sense, Adapt, Predict, Guardian, Learn**.
- Loom subsystems: **Compile, Guard, Profile, Adapt, Stream, Island**.
- Treat all names as proposed Orku branding pending trademark/domain/ecosystem checks, not as guaranteed original globally.
- Do not advertise any unshipped feature as implemented or any tentative benchmark as a performance guarantee.

## 15. Risks and mitigation

| Risk | Mitigation |
|---|---|
| Feature creep and architecture bloat | Preserve micro core; optional packages; RFC approval gates |
| Worker state leaks | ScopeWeave lifetime checks, user A/B/A tests, recycle policies |
| False performance claims | Public benchmark harness and complete metrics |
| ThinkCore tuning destabilizes system | Observe default, allowlists, budgets, cooldown, rollback, audit |
| `.ork` XSS/compiler vulnerabilities | Restricted grammar, context-aware escaping, source tests, security review |
| Native extension portability/security | Optional fallback, ABI/build tests, postpone until proven useful |
| Stale documentation | Canonical implementation-status matrix updated each release |
| Plugin ecosystem fragmentation | Maintain versioned contracts and support policies |

## 16. Proposed first sprint (recommended)

**Sprint goal:** make the project ready for safe, measured expansion, not to ship every coined technology at once.

1. Create a canonical implementation-status table in repository docs; fix stale roadmap claims.
2. Record reproducible HTTP and memory baseline under PHP-FPM and CLI, with machine-readable output.
3. Implement middleware/DI RFC and request-scope test harness.
4. Write the `.ork` syntax/escaping RFC and compiler security tests **before** implementing DSL shortcuts.
5. Design ThinkCore Sense as read-only diagnostics and establish permission/cgroup boundaries.

**Sprint acceptance:** tests green, documentation truthful, no regression without review, and PRs scoped for independent review.

---

### Implementation status disclaimer

This document is an **upgrade plan**. It does not modify or implement Orku features by itself, and it does not imply Orku is faster than Laravel, Go, or Rust. Updates to the GitHub repository should be implemented through reviewed changes with passing tests and reproducible measurements.
