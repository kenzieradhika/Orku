# Orku Release Validation Matrix Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver reproducible, bounded evidence for the 12 proposed release tests, keeping stable-release gates open until their explicit acceptance criteria pass.

**Architecture:** Keep ordinary deterministic checks in CI; add opt-in runners for long, resource-intensive, platform-dependent experiments. Each run records source revision, dependency/image versions, configuration, raw machine-readable observations, errors, and limitations. Use only disposable local containers/databases and synthetic data; never exercise a live/shared service.

**Tech Stack:** Existing PHP 8.4/8.5, PHPUnit, Python stdlib runners, Docker, Nginx/PHP-FPM, SQLite/PDO, Linux cgroup v2. No new dependencies unless a test cannot be implemented adequately with existing tools.

**Spec:** User-provided 12-test priority matrix in conversation; project boundaries in `docs/roadmap.md`, `docs/stable-release-checklist.md`, `docs/security-review-focused.md`, and `benchmarks/results/README.md`.

## Global Constraints

- Stable-release status remains **not release-ready** until every existing release gate is resolved.
- PHP runtime support is `^8.4`; CI covers PHP 8.4 and 8.5.
- Test services must be disposable, loopback-only, read-only source-mounted where practical; clean up only resources created by the runner.
- Default load-test ceiling: one CPU, 256 MiB memory, 128 processes; every runner has a wall-clock/request cap and reports saturation/errors rather than hiding them.
- Soak duration is manual opt-in, maximum 24 hours per initial run; extend to 72 hours only after review of first-run behavior and resource budget.
- Fuzzing uses local parsers and bounded synthetic input; no live targets, external network, credentials, or production data.
- SQLite remains explicitly experimental; no cross-driver guarantee is implied.
- Keep benchmark raw results separate from universal performance claims; preserve exact environment and per-run data.
- Do not alter existing user services, host firewall, sysctls, or machine-wide settings.

## Review Focus

- Load generator saturation can masquerade as framework saturation — record client CPU and errors; validate with a client-only baseline.
- Keep-alive and concurrency can mask connection churn — test both configured production mode and explicit connection policy.
- Container memory counters are not PHP heap/RSS — label the measure and pair with process/cgroup counters where available.
- Database tests can pass while leaking transactions/connections — assert rollback, isolation, bounded pool use, and post-run DB cleanliness.
- Sanitizer and fuzz findings are not application impact — report only a concrete unsafe render/input boundary or a reproducible crash with practical resource impact.

---

## File Structure

- Modify `docs/stable-release-checklist.md`: link each test gate to its evidence and leave unmet boxes unchecked.
- Modify `docs/roadmap.md`: reflect only validated test coverage and distinguish smoke, benchmark, and soak evidence.
- Create `tests/release/`: deterministic local fixtures and integration assertions shared by release runners.
- Create `benchmarks/release/`: opt-in bounded benchmark/soak/stress runners and configuration snapshots.
- Create `tests/thinkcore/`: cgroup fixture cases for ThinkCore parsers and status semantics.
- Create `docs/release-evidence/README.md`: evidence naming, required metadata, retention, and claims policy; raw run artifacts stored per run, not checked in by default.
- Create `docs/release-validation-results.md`: concise index of completed runs, exact commit, results, blockers, and unverified environments.

## Phased Execution

Do not execute all 12 load scenarios in one CI job. Ship each task independently, run deterministic tests locally, then verify CI. Full-scale tests are explicit opt-in commands.

### Task 1: Real-world API benchmark (P0)

**Files:** Create `benchmarks/release/api_check.py`, `benchmarks/release/api_scenario.json`, `benchmarks/release/test_api_check.py`; modify benchmark docs and checklist.

**Deliverable:** Compare representative REST endpoints (status, dynamic user lookup with synthetic fixture, JSON POST validation, error path) through identical Nginx/FPM topology. Capture status/error counts, throughput, P50/P95/P99, CPU and cgroup memory. Include Orku and one pinned Laravel baseline; no database-backed endpoint until Task 2.

- [x] Write tests for scenario validation (only loopback URLs; positive request/concurrency caps), correct status/error aggregation, and P50/P95/P99 output.
- [x] Run tests; verify failures before implementation.
- [x] Implement runner with a 120-second batch deadline, max 10,000 requests per scenario, concurrency ≤128, JSON raw output.
- [x] Verify endpoint response bodies/status are equivalent before measuring; fail run on mismatches.
- [ ] Run a bounded local sample; record both client and server resource use plus all batch rows. Blocked: matching representative API fixtures are not available; current health route is not real-world API coverage.
- [x] Update evidence index/checklist; CI runs tests only, never the benchmark.

**Acceptance:** All endpoints use same payload/semantics; zero hidden errors; raw and summarized results retained; client bottleneck and middleware differences disclosed.

### Task 2: Database queries, transactions, connections (P0)

**Files:** Create `tests/release/database_check.php`, `tests/release/test_database_check.py`, `benchmarks/release/database_check.py`; modify `docs/migrations.md` only if validated behavior is added.

**Deliverable:** SQLite/PDO integration validation using a temporary database and synthetic rows. Measure prepared point lookup, bounded list query, transaction commit/rollback, and repeated acquire/release; compare identical SQL/data for Orku and Laravel. No claim about MySQL/PostgreSQL.

- [x] Test temporary DB creation, fixed seed count, rollback leaves no row, commit persists expected row, and teardown removes DB.
- [x] Add bounded query/transaction loops and assert expected result hashes after each case.
- [x] Capture latency percentiles, connection count/driver, and error counts.
- [x] Verify timeout cleanup of the unique temp directory. Not yet run in a resource-limited container; keep that portion gated.
- [x] Mark feature/status experimental and ensure no migration is implicitly run.

**Acceptance:** Identical schema/seed/query semantics; no persistent DB artifact; all writes confined to temp DB; transactions and teardown verified.

### Task 3: Soak test, memory growth and state isolation (P0)

**Files:** Create `benchmarks/release/soak_check.py`, `benchmarks/release/test_soak_check.py`, `docs/release-evidence/soak-runbook.md`; modify stable checklist.

**Deliverable:** Opt-in sustained API test. Initial supported run: 24 hours maximum; 72-hour run is a separate explicit duration after review. Default low rate: 2 requests/second, concurrency 4; user may lower but not raise ceilings without a reviewed config change. Exercise stateless route plus disposable session/CSRF flow with synthetic sessions.

- [x] Unit-test duration/rate limits, stop-file handling, and metric/result serialization; heartbeat/session checks remain unimplemented.
- [ ] Run a 5-minute disposable rehearsal; server instrumentation missing.
- [ ] Capture interval health, HTTP errors, latency percentiles, cgroup current/peak memory, CPU, process restarts, session count, and session-isolation assertions. Current runner lacks these server metrics.
- [ ] Fail if error rate >0.1% in any 5-minute window, any cross-session token acceptance, OOM/restart, or monotonic memory slope >1 MiB/hour after warmup; only HTTP error-window threshold implemented.
- [ ] Run 24-hour test only on a dedicated local machine/isolated runner; no default CI scheduling.
- [ ] If 24-hour passes, record decision before a separate opt-in 72-hour run.

**Acceptance:** Full duration completed, no state leakage/OOM/restarts, limits respected, memory trend within threshold, raw interval data retained. A short rehearsal does not close the soak gate.

### Task 4: Security regression and bounded fuzz testing (P0)

**Files:** Create `tests/release/fuzz_http_inputs.py`, `tests/release/test_fuzz_http_inputs.py`; extend `docs/security-review-focused.md` with scope and remaining deployment validation.

**Deliverable:** Deterministic seeded mutations for path, headers, JSON/form body, route parameters, session/CSRF inputs; fuzz only in-process parsers and disposable loopback. Test max 10,000 generated cases, fixed seed recorded, 30-second timeout, 256 MiB process memory ceiling.

- [x] Tests assert corpus seed, maximum case count, and reproducibility. Parser exception normalization and uncaught-fatal checks await isolated execution harness.
- [x] Add deterministic boundary corpus: malformed percent-encoding, encoded delimiters, CRLF, oversized value, duplicate fields, invalid UTF-8, unusual method, truncated JSON, and CSRF length.
- [ ] Assert no reflection of secret marker in production-mode response/log fixture and no route authorization bypass (authorization cases remain N/A until auth exists).
- [ ] Run under isolated offline sandbox; blocked because required OS-enforced sandbox unavailable.
- [ ] Triage findings against actual security boundary; no parser execution/findings claimed.

**Acceptance:** Deterministic bounded corpus, no uncaught parser failures or unsafe response disclosure; security review remains partial for missing auth and deployment behavior.

### Task 5: Stress and bounded breaking point (P0)

**Files:** Create `benchmarks/release/stress_check.py`, `benchmarks/release/test_stress_check.py`, `docs/release-evidence/stress-runbook.md`.

**Deliverable:** Increasing load levels against only disposable containers: concurrency 4/16/32/64/128, fixed 60-second max each; stop once error threshold or memory/CPU ceiling reached. Never target host services or public endpoints.

- [x] Unit-test ramp ordering, hard request/time ceilings, and two-consecutive-window calculation; abort and partial-result retention need follow-up.
- [ ] Add a client-only loopback calibration to establish that the generator can exceed tested service throughput.
- [x] Record offered/completed count, timeout/status breakdown, P50/P95/P99. Queue/saturation and CPU/RAM/OOM metrics are not sampled.
- [x] Define provisional breaking point as first two consecutive windows with >1% errors or an explicit resource cap; report last stable level.
- [ ] Run a small disposable rehearsal only after client calibration and server metrics are implemented.

**Acceptance:** Breaking point is evidenced without host resource exhaustion; harness saturation is ruled out or explicitly marks result invalid.

### Task 6: Spike and recovery (P1)

**Files:** Create `benchmarks/release/spike_check.py`, `benchmarks/release/test_spike_check.py`.

**Deliverable:** Baseline at 2 requests/second, bounded 10-second spike at approved peak ≤128 concurrency, then 10-minute recovery observation.

- [x] Test phase sequence, capped spike, and response-derived recovery calculation.
- [ ] Record availability, tail latency, CPU/RAM, FPM worker recovery, and time-to-baseline. Only status and P95 currently collected.
- [ ] Fail recovery if HTTP errors persist >60 seconds after spike or P95 remains >2× baseline for >2 minutes. Response-window threshold implemented; server/continuous-window monitoring incomplete.
- [ ] Run only in disposable containers, maximum 15-minute execution; not run because no matching disposable deploy fixture is available.

**Acceptance:** Recovery thresholds pass; no OOM or leaked worker/session state; limits documented.

### Task 7: Concurrency and data integrity (P1)

**Files:** Create `tests/release/test_concurrent_integrity.php`, `tests/release/concurrent_integrity_check.py`.

**Deliverable:** Race-focused parallel requests against disposable SQLite DB and separate synthetic sessions. Test atomic increments/unique inserts within transactions; no shared mutable user services.

- [ ] Write tests that run N workers against the same counter and assert exact final count, uniqueness, transaction rollback and cross-session CSRF rejection.
- [ ] Cap at 32 workers/10,000 operations; use per-run DB and fixed seed.
- [ ] Assert every operation gets a classified result and no partial/corrupt rows remain.
- [ ] Run repetitions at concurrency 1/4/16/32; record lock/busy errors separately from data-integrity failures.

**Acceptance:** Exact expected DB state, no cross-session acceptance, all SQLite lock behavior explicitly reported; no concurrency guarantees beyond tested mode.

### Task 8: Cold start and bootstrap (P1)

**Files:** Create `benchmarks/release/cold_start.py`, `benchmarks/release/test_cold_start.py`.

**Deliverable:** Fresh disposable containers per sample, measure process-ready time and first successful request separately from warm request latency; 20 samples per framework, same image/cache policy.

- [ ] Test metric distinction, unsuccessful readiness deadline (30 seconds), and metadata capture.
- [ ] Record image digest, Composer classmap state, OPcache state, route/config cache, and whether dependencies are mounted or bundled.
- [ ] Sample warm request only after explicit warmup; don't mix it with cold-start timing.
- [ ] Report median/P95 range and failures; no single-sample claims.

**Acceptance:** 20 valid samples each; setup/cache states match; failed starts retained, not dropped.

### Task 9: Deployment and rollback (P1)

**Files:** Create `tests/release/deploy_check.py`, `tests/fixtures/deploy/`, `docs/release-evidence/deployment-rollback.md`.

**Deliverable:** Disposable blue/green or versioned container deployment; health-gated switch and rollback to previous image. Verify active endpoint throughout, source remains read-only, and persistent data is a disposable named volume.

- [ ] Test deploy success path, failed health gate, rollback path, and cleanup preserving only test volume until assertions complete.
- [ ] Record request continuity and failed requests around switch; assert previous version resumes within 30 seconds on rollback.
- [ ] Test migration compatibility only with explicitly experimental SQLite fixture; never run destructive migration against shared data.
- [ ] Document TLS/proxy as `needs-validation` unless a local certificate/proxy fixture is added and verified.

**Acceptance:** Successful deploy and forced rollback both preserve expected synthetic state; no existing services or volumes touched.

### Task 10: SSR/template benchmark and output safety (P1)

**Files:** Create `benchmarks/release/ssr_check.php`, `benchmarks/release/ssr_check.py`, `benchmarks/release/test_ssr_check.py`.

**Deliverable:** Compare identical HTML template output through Orku PHP views and Laravel Blade with matching static/layout/escaped-variable cases. Benchmark rendering only and HTTP path separately.

- [ ] Test output equality for static content, UTF-8, null/empty values, nested layout, and escaped `<script>` marker.
- [ ] Test raw/unescaped output only as an explicit expected unsafe capability; ensure default escaping never renders marker as executable markup.
- [ ] Measure P50/P95/P99 and memory after warmup; fixed 1,000 renders per repeat, 5 repeats.
- [ ] Keep compilation/cache settings explicit and equivalent where available.

**Acceptance:** Output semantics match; escaping assertions pass; rendering and HTTP timings labeled separately.

### Task 11: Hardware and runtime compatibility (P2)

**Files:** Create `docs/release-evidence/hardware-matrix.md`, `tests/thinkcore/platform_check.py`; modify CI only for supported native runners.

**Deliverable:** Matrix for Linux x86_64 PHP 8.4/8.5, ARM64 PHP 8.4, and one small-resource profile (1 CPU/512 MiB). Windows/macOS/shared hosting remain unsupported unless separately volunteered and tested.

- [ ] Verify basic CLI, HTTP dispatch, session, PDO SQLite capability, and ThinkCore status per available native/container architecture.
- [ ] Record OS/kernel/architecture/PHP/image digest, unavailable capabilities, and skipped tests with reasons.
- [ ] Never emulate and label as native hardware evidence; QEMU results are separately marked emulated.
- [ ] Add CI matrix only where stable official runner images and services exist; no hard-coded flaky timing gates.

**Acceptance:** Each claimed runtime has complete evidence or is explicitly excluded from support.

### Task 12: ThinkCore resource-detection validation (P2)

**Files:** Create `tests/thinkcore/fixtures/`, `tests/thinkcore/test_cgroup_fixtures.php`, `tests/thinkcore/live_check.py`; update `docs/thinkcore-rfc.md`.

**Deliverable:** Deterministic cgroup v2 fixture tests for memory.max/current, cpu.max fractional quota, malformed/missing files, unlimited values, process affinity, and unavailable statuses. Separate optional live-container comparison to actual cgroup limits.

- [ ] Test exact units/conversions, quota rounding, unlimited values, malformed values, and each per-measurement status.
- [ ] Test parent/child cgroup path handling with synthetic hierarchy and inaccessible/missing files.
- [ ] Live check compares reported limits to Docker-configured CPU/memory in a disposable container; tolerance zero for hard limits, explicit status if environment hides counters.
- [ ] Preserve read-only behavior; assert no file writes, tuning, or host setting changes.

**Acceptance:** All fixture cases deterministic; live observation matches configured ceilings where exposed; unsupported/missing counters remain unknown, never fabricated zero.

## Release Gate Integration

- [ ] After each task, update the existing checklist only with completed evidence links and an exact run commit; leave unrelated gates unchecked.
- [ ] Run `composer check`, Python unit tests, `composer audit --locked`, deployment smoke checks, package check, and `git diff --check` before each push.
- [ ] After tasks 1–12, conduct a fresh compatibility/security review and reconcile README, architecture, roadmap, changelog, and deployment docs.
- [ ] Start release-candidate soak only after P0 gates pass; record explicit readiness decision after the soak period.

## Self-Review

- All 12 user-prioritized test classes map one-to-one to Tasks 1–12.
- Priority and required artifact scope stay visible; none of the tasks claim support for an untested DB driver, OS, architecture, or deployment mode.
- Long-running, stress, fuzz, and deployment commands are opt-in, bounded, isolated, and forbidden from modifying existing services.
- Raw batch preservation and percentile semantics are defined; test-generator limitations are treated as invalidating evidence, not framework failures.
