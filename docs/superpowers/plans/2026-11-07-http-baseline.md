# HTTP Baseline Implementation Plan

> **For agentic workers:** Use `executing-plans` to implement this plan task-by-task. Track completion with the checkboxes below.

**Goal:** Extend the existing local HTTP benchmark with reproducible latency, error and optional server-resource measurements.

**Architecture:** Keep `benchmarks/http_latency.py` as a Python standard-library CLI. Measure an explicitly started local server; do not add a framework runtime or claim production throughput. Support CLI-server and PHP-FPM HTTP endpoints through the same client, recording operator-supplied runtime metadata rather than guessing the server type.

**Tech Stack:** Python 3 standard library, PHP 8.4+, existing PHPUnit/PHPStan/format checks.

**Spec:** `ORK.md`, sections 9, 12 (backlog item 2), 16. This increment establishes a sequential baseline, not the entire concurrent comparison suite.

## Global Constraints

- No new dependencies or application runtime changes.
- Local loopback HTTP only; do not follow redirects.
- Report measurement boundaries and unavailable metrics honestly.
- Run local checks before push, then verify hosted CI.
- Do not publish performance comparisons or production-capacity claims.

## Review Focus

- Connection failures/timeouts count as errors, never disappear from results.
- Query strings reach the endpoint; credentials/fragments and invalid ports are rejected.
- Percentiles use nearest rank, including a single-sample run.
- Optional Linux process measurements reject invalid PIDs and tolerate process exit without inventing zero usage.
- Resource results describe the selected process only, not its children or a complete FPM pool.

## Task 1: Extend the existing benchmark and document its boundary

**Files:**
- Modify: `benchmarks/http_latency.py`
- Create: `benchmarks/test_http_latency.py`
- Modify: `benchmarks/README.md`

**Interfaces:**
- Preserve positional `url` and `--requests` (default 500, range 1..100000).
- Add `--warmup` (default 50, range 0..100000), `--runtime-label` (required), optional `--server-pid` (positive integer).
- Emit JSON with runtime/client metadata, counts, actual wall-time throughput, mean/P50/P95/P99 latency, status counts, transport errors, optional Linux process RSS/CPU deltas.
- Exit nonzero on measured errors; unavailable process measurements are null with an explanation. Never collect environment secrets or full command lines.
- Keep fresh TCP connections, sequential requests and a documented 10-second timeout. Bound response buffering by draining in fixed-size chunks.

- [ ] Write stdlib `unittest` tests using a local fixture server for success, non-200, disconnect, query propagation, one-sample percentile and invalid arguments. Add Linux PID resource tests where supported.
- [ ] Run `python3 -m unittest discover -s benchmarks -p 'test_http_latency.py'`; confirm new expectations fail before implementation.
- [ ] Refactor behind `main()` for import-safe testing; implement the options and JSON measurements using stdlib only. Use `/proc` only for an explicitly supplied local PID; label RSS samples as start/end, not peak.
- [ ] Repeat Python tests; require all passing. Document CLI-server/FPM endpoint commands, metadata, units, limitations and raw JSON capture under ignored `benchmarks/results/`.
- [ ] Run a local PHP 8.4 Docker demo and capture a short benchmark JSON outside tracked source. Validate counts/units and zero errors. Stop the demo afterwards.
- [ ] Run PHPUnit, PHPStan and formatting locally in PHP 8.4 Docker, plus `composer validate --strict`, `composer audit --locked`, and `git diff --check`.
- [ ] Commit/push only after local success; watch CI PHP 8.4/8.5 to completion. Report unavailable FPM measurements explicitly; do not label CLI-server results as FPM.

## Deferred

## Execution record

- Implemented on `benchmark-http-baseline`; no application runtime changes.
- Python regression suite: 7 passing tests, including transport errors, query preservation, invalid URLs and exited processes.
- Local PHP 8.4.26: 129 tests / 334 assertions; PHPStan and formatting passed.
- Composer validation and locked dependency audit passed.
- Demo baseline: 100 measured HTTP 200 responses, 10 warmup, zero errors; raw JSON at `/tmp/opencode/orku-http-baseline.json` (local artifact, not portable evidence).
- Fresh-context review: fixed explicit port zero, non-ASCII URL rejection and zombie PID sampling after failing regression tests.
- Scope decision: added the Python regression command to CI so benchmark behavior remains checked. FPM/pool measurements remain deferred, not inferred from CLI-server results.
- Hosted CI verification follows push; no merge to `main` in this increment.

Concurrent load generation, sustained RSS slope, pool-wide FPM measurements, ARM validation and framework comparisons require separate measured increments. No new abstraction is needed for this baseline.
