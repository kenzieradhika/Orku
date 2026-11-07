# Baseline vs protected (bounded local runs)

Legacy exploratory runs: 2026-11-07 UTC. These runs do not establish admission protection: labels/config propagation was wrong, and the Nginx config was not verified per run. Exclude them from protection claims. See the curated tight-admission report below.

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
- These initial eight files remain raw historical records; do not pool with later profile-verified runs.

## Tight admission validation (curated)

Same application workload (`LAB_APP_VARIANT=baseline`) for baseline and protected comparison. Protected profile selected independently (`LAB_NGINX_CONFIG=protected-tight`), and `nginx -T` confirmed 1 req/s, burst 1, 429. Three runs per case. The scheduler's nominal arrival rate is 4 iterations/s; observed baseline request rate was ~2.39/s because each iteration sleeps 250 ms and requests take time. Results show a real but modest burst rejection, not a 4 req/s sustained load.

| Case | Runs | HTTP 200 | HTTP 429 | HTTP 503 | Transport failures | Throughput (range) | P50 ms (range) | P95 ms (range) | P99 ms (range) | Dropped iterations |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---|
| Baseline admission | 3 | 45 | 0 | 0 | 0 | 2.39 req/s each | 37.4–47.8 | 38.95–95.39 | 39.06–100.59 | 0 observed |
| Tight admission | 3 | 19 | 24 | 0 | 0 | 2.23–2.39 req/s | 0.49–0.57* | 61.27–65.59 | 61.79–82.65 | 0 observed |
| Legitimate low-rate, baseline | 3 | 17 | 0 | 0 | 0 | 0.79–0.95 req/s | 38.42–53.55 | 54.30–82.82 | 54.43–91.17 | 0 observed |
| Legitimate low-rate, tight | 3 | 17 | 0 | 0 | 0 | 0.79–0.95 req/s | 52.23–96.48 | 53.53–102.64 | 53.76–103.41 | 0 observed |
| Intentional app overload fixture | 3 | 0 | 0 | 5 | 0 | 0.67–0.95 req/s | 1.13–4.47 | 1.13–5.57 | 1.13–5.66 | 0 observed |
| Recovery check, valid samples | 5 | 26 | 0 | 0 | 0 | 0.48–0.95 req/s | 38.35–84.03 | 39.35–86.72 | 39.41–86.96 | 0 observed |

`*` Tight-admission P50 includes fast HTTP 429 responses; it is not comparable to successful-request latency. Do not claim faster performance from rejection. One extra recovery raw file had 6 DNS transport failures and was excluded; it is preserved as `20261107T040416.638434Z-recovery_check.json`. Recovery criterion: low-rate restart/probe yielded HTTP 200, zero 429/503 and zero transport errors in five valid samples. This establishes successful post-restart service, not recovery time from load. The lab network is private; runs bounded to 4 VUs, ≤4 iter/s nominal, 6s (overload fixture 2s), and existing resource caps.

Raw evidence: all `20261107T0401*.json` through `20261107T0405*.json` files. Metadata records variant, Nginx profile, app profile, scenario, internal target, and k6 version. CPU/memory were not sampled. `dropped_iterations` was zero in the retained JSON summaries; one interrupted recovery probe had DNS failures and was excluded rather than counted as successful recovery.
