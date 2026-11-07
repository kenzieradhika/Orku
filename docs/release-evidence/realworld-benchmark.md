# Real-world API and Laravel SSR comparison

## Status

**SSR parity verified; bounded rendering-only run completed.** Laravel 12.71.0
fixture and PHP 8.4.27 Docker image built. A short run (3 × 100 renders per
framework) passed byte equality. Raw JSON: `/tmp/opencode/ssr-render-report.json`
(ephemeral; rerun to reproduce). These are smoke-scale local results, not a
release-grade performance claim.

Host PHP 8.3.6 is unsupported; comparison used PHP 8.4.27 in Docker for both
frameworks. Laravel `composer.lock` pins 12.71.0.

## Smoke results

| Renderer | Mean | P50 | P95 | P99 | Throughput per batch |
|---|---:|---:|---:|---:|---:|
| Orku | 11.17 µs | 11.06 µs | 11.43 µs | 13.61 µs | 87,567–89,966 renders/s |
| Laravel Blade | 1.81 µs | 1.81 µs | 1.88 µs | 2.24 µs | 510,053–533,584 renders/s |

3 repeats × 100 renders, 100 warmups per subprocess. First render per process:
Orku 13.06–25.09 µs; Blade 17.24–21.14 µs. Timer overhead and CPU are retained in the raw JSON. CPU
recorded separately per process; memory `UNMEASURED`. Warm numbers measure one
escaped variable template only; case parity covers all 11 scenarios.

## Method

- SSR fixture cases: byte equality required before rendering measurements.
- Rendering-only latency: repeated CLI renders; per-render raw nanoseconds,
  nearest-rank P50/P95/P99, arithmetic mean, per-batch throughput, timer cost,
  user/system CPU, and output SHA-256.
- Laravel version: read from disposable dependency root's `composer.lock`, must
  be exactly `12.71.0`.
- Memory: `UNMEASURED`; sampled arrays and CLI process allocations would confound
  renderer attribution. External process/cgroup metrics require separate work.
- Cold template-cache comparison, Orku/Laravel HTTP, API/SQLite CRUD and
  application bootstrap cold-start are separate remaining phases; none are
  inferred from first-render observations.

## Configuration and reproduction

See `benchmarks/release/ssr_compare_scenario.json` and
`benchmarks/release/laravel-ssr/README.md`. Preserve Orku commit, PHP patch,
OS/architecture, Composer lockfile, cache state, and raw JSON for every run.
Use only local disposable fixtures. No CI load benchmark runs.

## Limitations

The Orku side uses the legacy `src/Web/ViewRenderer`; it is not Loom. The fixture
measures a small synthetic template workload, not a production application.
PHP 8.5 not tested. HTTP, cold template cache, app bootstrap cold start, CRUD,
and memory remain unmeasured. Smoke output should not be generalized to
production performance.
