# Orku vs Laravel CRUD/API benchmark

Disposable SQLite CRUD fixtures with one shared contract and one shared PHP
implementation of request semantics (`CrudStore.php`). Orku and Laravel 12.71.0
run behind identical PHP-FPM 8.4 + Nginx containers with equal CPU/memory limits,
worker counts, OPcache settings and matching SQLite configuration.

This suite measures **end-to-end HTTP CRUD only**. Direct database microbenchmarks
remain in `tests/release/database_check.py` (Orku only); cross-framework DB-only
timings are not claimed here.

Not a Laravel Eloquent vs Orku Database comparison: both apps call the same
`Orku\CrudBench\CrudStore` class on raw PDO, so the measured difference is the
framework HTTP stack around identical data-access code. ORM/query-builder
performance is out of scope for this suite.

## Files

| Path | Purpose |
| --- | --- |
| `contract.json` | Routes, status codes, field limits, seed size |
| `schema.sql` | SQLite schema (unique `client_key`, version column) |
| `CrudStore.php` | Shared validation, prepared statements, transactions, rollback |
| `seed.py` | Deterministic seed used by tests/tools |
| `orku/` | Orku fixture (`public/index.php`) |
| `laravel/` | Laravel 12.71.0 fixture (`composer.lock` pins the framework) |
| `Dockerfile`, `compose.yaml` | Identical FPM images, Nginx, resource limits |
| `verify_parity.py` | Contract/status/JSON equivalence before measuring |
| `run_benchmark.py` | Bounded load, percentiles, integrity and cgroup evidence |

## Reproduce

```bash
cd benchmarks/crud-api
docker compose --profile orku --profile laravel build
docker compose --profile orku --profile laravel up -d
python3 verify_parity.py --candidate http://127.0.0.1:18090 \
  --reference http://127.0.0.1:18091 --output results/parity.json
python3 run_benchmark.py --base-url http://127.0.0.1:18090 --requests 400 \
  --concurrency 8 --run-tag orku --container orku-crud-orku \
  --output results/orku.json
python3 run_benchmark.py --base-url http://127.0.0.1:18091 --requests 400 \
  --concurrency 8 --run-tag laravel --container orku-crud-laravel \
  --output results/laravel.json
docker compose --profile orku --profile laravel down -v
```

Run frameworks sequentially on the same idle host. Alternate repeats if comparing.
Report the median of three repeats and the observed range, never the best run.
A measured interval is roughly 1-2 seconds, so P99 is noisy and a single outlier
moves it; treat sub-5% RPS gaps as unresolved. If one container pins its CPU
limit (cgroup `cpu_fraction_of_one_core` at the `cpus` quota), the throughput gap
is a lower bound, not an exact ratio.
`--requests` is per phase (five phases: list, get, create, replace, delete), so
400 means 2,000 measured requests. Hard caps: 2,000 requests/phase, 5,000 measured
total, concurrency 64, 120 seconds, 5-second request timeout, loopback URLs only.

## What is measured

- Per-phase and aggregate requests/second, P50/P95/P99, status distribution,
  transport errors and error rate.
- Database integrity after the run: row count must equal seed + created - deleted.
  Setup requests (reset and update/delete pools) are excluded from timing.
- Optional FPM container cgroup v2: cumulative CPU seconds and current/peak memory.
  These are **container** values, not framework-only memory; Nginx and host page
  cache are excluded. Missing cgroup files report `UNAVAILABLE`, never zero.

Committed evidence: `results/parity.json` plus three alternating repeats
(`results/orku-run{1,2,3}.json`, `results/laravel-run{1,2,3}.json`), all at
400 requests/phase and concurrency 8.

Raw per-request rows are preserved in each result JSON. Environment metadata
(host, PHP image digest, PHP/SQLite versions, OPcache, FPM pool, resource limits,
Laravel lock hash, source revision) is in `results/environment.json`.

## Deliberate limits

- SQLite only. PostgreSQL/MySQL parity is a separate milestone.
- No authentication, sessions, CSRF or throttle middleware; benchmark endpoints
  are loopback-only and must never be exposed publicly.
- `CRUD_BENCH_MAINTENANCE=1` enables destructive reset/state endpoints; the compose
  file enables it for disposable fixtures only.
- Results are comparative evidence for this host and configuration, not production
  capacity claims. Hardware, image digests and commands must travel with any number.
