# Orku PostgreSQL Reliability Milestone Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Extend the existing SQLite/PDO release fixtures to PostgreSQL, proving wrapper behaviour (point query, bounded list, transaction commit/rollback, concurrent integrity) against a raw-PDO control on the same database.

**Architecture:** Reuse `tests/release/database_fixture.php` and `tests/release/concurrent_integrity_check.php` with a driver switch selected by environment, not a fork. Driver-specific DDL lives in the fixture behind a small helper; schema reset is per run on a disposable container database. A Python runner starts/stops a pinned `postgres` container, applies a bounded resource budget, waits for readiness, runs the PHP fixture, and records raw JSON plus environment metadata. Skip cleanly when Docker or the image is unavailable; never fall back silently to SQLite while claiming PostgreSQL.

**Tech Stack:** PHP 8.4/8.5, `Orku\Data\Database`, PDO (`pdo_sqlite`, `pdo_pgsql`), Python stdlib runner, Docker `postgres:<pinned>` with `cpus`/`mem_limit`/`pids_limit`.

**Spec:** `docs/stable-release-checklist.md` database gate, `tests/release/README.md`, `docs/release-evidence/README.md`.

## Global Constraints

- SQLite evidence stays as recorded; PostgreSQL evidence is a separate row, never merged into one number.
- No production database, credentials, or shared host service; disposable container only, loopback binding, no published port unless required.
- Fixed budgets: 2 CPUs, 512 MiB, 128 processes for the database container; wall-clock and iteration caps identical to the SQLite fixture.
- Record image digest, server version, PHP version, driver version, isolation level, limits, and source revision with every raw run.
- Report median and range across repeats; do not claim cross-driver performance superiority.
- Missing Docker/pdo_pgsql is a recorded `UNAVAILABLE` result, not a failure of the framework.
- CI stays SQLite-only unless a PostgreSQL job can run within the existing timeout and be deterministic.

## Task 1 — Driver abstraction in fixtures

- [ ] Add `--driver` (`sqlite` default, `pgsql` opt-in) to `database_fixture.php`; DSN from env (`PGSQL_DSN`) and no default credentials.
- [ ] Replace SQLite-only DDL with per-driver equivalents (`SERIAL`/identity, `BIGINT`, `TEXT`); keep seed size and operation order identical.
- [ ] Preserve existing SQLite output shape so `test_database_check.py` keeps passing unchanged.
- [ ] Add a test that `pgsql` without DSN exits non-zero with a single clear message.

## Task 2 — Concurrent integrity on PostgreSQL

- [ ] Parameterise `concurrent_integrity_check.php` DSN/driver; keep worker/operation/busy-timeout validation.
- [ ] Use `SERIALIZABLE` or explicit `SELECT ... FOR UPDATE` only where needed; document the chosen isolation level.
- [ ] Assert exact counter/event counts and a verified rollback, same as SQLite.
- [ ] Record retry counts separately from lock errors; do not hide deadlocks.

## Task 3 — Disposable PostgreSQL runner

- [ ] Add `tests/release/postgres_check.py`: pull pinned image, start container with limits, poll readiness, run fixture, capture `docker inspect` resource deltas, stop container, delete volume.
- [ ] Refuse non-loopback targets; refuse a pre-existing container name.
- [ ] Emit raw JSON to `docs/release-evidence/` outside the repo per existing policy; keep only a summary row in-repo.
- [ ] Unit-test the runner with a fake subprocess, mirroring `test_database_check.py`.

## Task 4 — Evidence and gates

- [ ] Run three alternating repeats wrapper vs raw PDO; record median/range and zero-error requirement.
- [ ] Add a separate `docs/release-validation-results.md` row with driver/version/limits and remaining gaps.
- [ ] Keep the checklist database gate open until both drivers report 0 errors and verified rollback/concurrency.
- [ ] Do not close framework-parity or alternate-driver gates from SQLite results.

## Task 5 — Review and integration

- [ ] `composer validate --strict`, `composer check`, Python suites, `git diff --check` on PHP 8.4 and 8.5.
- [ ] One PR, benchmark evidence included; no merge without review.
