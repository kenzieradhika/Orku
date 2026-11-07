# Baseline vs protected (bounded local runs)

Eight fresh runs: 2026-11-07 UTC. Baseline first, then protected; protected Nginx config verified with `nginx -T` (`limit_req`, `limit_conn`). Same app image/workload. Smoke-scale local measurements only; no capacity or DDoS-resistance claim.

| Scenario | Variant | Requests/s | Success / requests | P50 ms | P95 ms | P99 ms | Error rate | Dropped iterations | HTTP statuses | Container CPU/memory | Recovery time | Raw JSON |
|---|---|---:|---:|---:|---:|---:|---:|---:|---|---|---|---|
| baseline | baseline | 0.954 | 6/6 | 40.86 | 56.09 | 56.56 | 0.00% | NOT EXPORTED | 200=6 | NOT MEASURED | NOT MEASURED | 20261107T034404.286243Z-baseline.json |
| baseline | protected | 0.954 | 6/6 | 40.85 | 91.26 | 91.49 | 0.00% | NOT EXPORTED | 200=6 | NOT MEASURED | NOT MEASURED | 20261107T034441.547647Z-baseline.json |
| ramp | baseline | 1.938 | 18/18 | 53.94 | 84.92 | 97.90 | 0.00% | NOT EXPORTED | 200=18 | NOT MEASURED | NOT MEASURED | 20261107T034411.047184Z-ramp.json |
| ramp | protected | 1.931 | 18/18 | 53.60 | 98.51 | 100.98 | 0.00% | NOT EXPORTED | 200=18 | NOT MEASURED | NOT MEASURED | 20261107T034448.358680Z-ramp.json |
| spike | baseline | 1.901 | 15/15 | 37.35 | 53.52 | 53.80 | 0.00% | NOT EXPORTED | 200=15 | NOT MEASURED | NOT MEASURED | 20261107T034420.772874Z-spike.json |
| spike | protected | 1.901 | 15/15 | 52.36 | 96.38 | 97.48 | 0.00% | NOT EXPORTED | 200=15 | NOT MEASURED | NOT MEASURED | 20261107T034458.139602Z-spike.json |
| recovery | baseline | 2.408 | 20/20 | 52.55 | 72.40 | 86.70 | 0.00% | NOT EXPORTED | 200=20 | NOT MEASURED | NOT MEASURED | 20261107T034429.203128Z-recovery.json |
| recovery | protected | 2.413 | 20/20 | 42.37 | 86.16 | 91.15 | 0.00% | NOT EXPORTED | 200=20 | NOT MEASURED | NOT MEASURED | 20261107T034506.556901Z-recovery.json |

Notes:
- k6 omitted `dropped_iterations`; `NOT EXPORTED` does not mean zero.
- Container CPU/memory wasn’t sampled. Recovery time wasn’t defined or probed.
- Error rate is `http_req_failed`; 0% transport/protocol failures. All recorded HTTP responses were 200.
- Requests/s = completed HTTP requests / measured k6 duration. Short-run variation; no causal performance claim.
- Limits: k6 4 VUs, 4 req/s peak, 6–9 s stages, 0.25 CPU / 128 MiB / 32 PIDs; app 0.5 CPU / 128 MiB; Nginx 0.25 CPU / 64 MiB. Host: 16 CPUs, ~14.9 GiB RAM.
- Version: Grafana k6 v1.8.2; PHP 8.4 FPM Alpine; nginx 1.27 Alpine.
- Command: `python3 examples/overload-lab/run.py --scenario <scenario> --yes-really-local-load`; variants set via `LAB_VARIANT=baseline|protected`.
- Older files and `smoke*.json` excluded: stale variant labels or smoke runs.
