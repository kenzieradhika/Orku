# Orku overload lab

Local-only, finite-work comparison of baseline and protected Orku. Synthetic CPU work is deterministic and bounded; this is not a production benchmark or DDoS test.

## Run

Requirements: Docker Compose, PHP 8.4+ for native tests, Python 3.10+, Grafana k6 for load tests.

```sh
cd examples/overload-lab
docker compose up --build -d
curl http://127.0.0.1:18080/health
```

Baseline is default. Protected variant:

```sh
LAB_VARIANT=protected docker compose up --build -d --force-recreate
```

Run bounded cases (loopback target only; opt-in required):

```sh
python3 run.py --scenario baseline --yes-really-local-load
python3 run.py --scenario ramp --yes-really-local-load
python3 run.py --scenario spike --yes-really-local-load
python3 run.py --scenario recovery --yes-really-local-load
```

The launcher rejects public/non-loopback URLs, enforces 1–15 seconds and at most 60 requests via fixed 4-VU/1-iteration-per-second settings, and has a hard timeout. Each run writes a raw k6 summary JSON to `results/`. k6's bounded arrival rate, 4-VU ceiling and max-duration cap remain fixed; no load escalation. For baseline/protected comparison, run same scenario once per variant, save results under distinct filenames, compare the k6 JSON metrics manually. Never run both variants simultaneously on the same port.

## Stop and cleanup

Press Ctrl-C to stop k6 immediately. `docker compose down --remove-orphans` stops and removes lab containers/network; no host volumes or persistent app data exist. `docker compose down` also runs from another terminal. Do not expose the baseline publicly.

## Protections and limits

- Demo app: finite deterministic CPU loop; same response shape, work amount and API behavior in both variants.
- Protected Nginx config: 8 requests/second per source, burst 4, 2 concurrent PHP requests per source, controlled 429/503, 8 KiB body cap, short upstream/body timeouts. Baseline Nginx omits admission and concurrency limiting. `LAB_TEST_OVERLOAD=1` enables deterministic 503 fixture via `?overload=1`; disabled by default.
- PHP-FPM: four worker cap, 5-second request termination, recycle after 100 requests.
- Compose: internal-only network; only Nginx port 18080 bound to loopback; CPU, memory and PID caps.
- Orku: router, kernel, request and response APIs only; no framework resilience guarantees.

Nginx limiting is per source IP and enforced before Orku. Track response status, not presumed Orku attribution. Container resource metrics include PHP-FPM and Nginx overhead, not Orku-only overhead. No app-level admission or framework guarantee is claimed.

## Verification and evidence

`python3 -m unittest discover -s tests -v` exercises target/configuration guardrails and fixtures. PHP integration needs PHP 8.4 plus Composer dependencies. Docker and k6 experiments must be run manually; no load test runs in CI. Do not treat unexecuted scenarios as passed.

Schema: `results/schema.json`. Blank comparison: `results/comparison-template.md`. No measured results are committed.
