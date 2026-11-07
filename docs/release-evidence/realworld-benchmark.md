# Real-world API and Laravel SSR comparison

## Status

SSR parity verified: **11/11 fixture outputs byte-identical**. Bounded smoke run:
3 repeats × 100 renders per framework, 100 warmups per subprocess. Committed raw
JSON: [`ssr-smoke-2026-11-07.json`](ssr-smoke-2026-11-07.json).

The current smoke means modestly favor the measured Blade file execution path;
latency distributions overlap and batch throughput varies. This is not a
release-grade performance claim: render abstractions differ, and results cover
one escaped-variable template only.

## Smoke results

| Renderer | Mean | P50 | P95 | P99 | Throughput per batch |
|---|---:|---:|---:|---:|---:|
| Orku | 13.39 µs | 12.00 µs | 21.06 µs | 24.59 µs | 69,213–81,702 renders/s |
| Laravel Blade (file) | 11.46 µs | 11.23 µs | 11.94 µs | 20.13 µs | 84,611–87,193 renders/s |

First render per process, timer overhead, and CPU retained in raw JSON. Memory
remains **UNMEASURED**.

## Method and limitations

- PHP 8.4.27 Docker, Linux; Laravel 12.71.0 pinned in fixture lockfile.
- Orku commit: `100b3e85e9047d89591b4304c98d1bfb3f286fe6`; Laravel framework:
  `v12.71.0`; PHP patch: `8.4.27`.
- Equivalent high-level mode: Orku `ViewRenderer` path resolution/include versus
  Blade `BladeCompiler::compile()` to a file followed by include. This aligns
  file-based execution, not the full framework APIs: Blade uses no view factory
  or container, and Orku has no matching service-container cost.
- Optional low-level `blade_string` microbenchmark uses `compileString` + `eval`;
  report separately. Never combine it with high-level file-mode measurements.
- Strict byte comparison precedes timing; parity covers static, escaping, UTF-8,
  null/empty, layout/partial, collection, and conditional cases.
- Memory **UNMEASURED**; cold template-cache comparison **NOT_IMPLEMENTED**.
- HTTP, CRUD/API, application cold-start, and PHP 8.5 benchmark measurements
  remain pending. First-render observations do not substitute for cold-cache
  benchmarking.
- Legacy `src/Web/ViewRenderer`, not Loom; synthetic workload, not a production
  application. Do not generalize smoke timings to production performance.

## Reproduction

See `benchmarks/release/ssr_compare_scenario.json` and
`benchmarks/release/laravel-ssr/README.md`. Preserve exact commit, PHP patch,
OS/architecture, Composer lock, cache state, and raw JSON per run.
