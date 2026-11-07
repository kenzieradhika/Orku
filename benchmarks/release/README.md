# Bounded API comparison

`api_check.py` accepts HTTP loopback URLs only. Per run it caps the total at
10,000 requests, concurrency at 128, elapsed time at 120 seconds, request
timeouts at 5 seconds, and response bodies at 64 KiB. The supplied scenario is
deliberately a single health endpoint: no database API fixture exists yet, and
this runner alone does not satisfy the real-world API release gate.

Use the same endpoint contracts against Orku and a separately prepared,
disposable Laravel app. Verify response equivalence before measuring:

```sh
python3 benchmarks/release/api_check.py benchmarks/release/api_scenario.json \
  --verify-against http://127.0.0.1:8081 --output /tmp/opencode/api-contract.json
```

The scenario's `base_url` is the candidate; `--verify-against` is the baseline.
Both are checked against each endpoint's expected status and exact JSON bytes.
Only run when the two local apps are ready. Then run each measurement, swapping
the scenario `base_url` to the app being measured:

```sh
python3 benchmarks/release/api_check.py /path/to/scenario.json \
  --output /tmp/opencode/api-orku.json
```

Output includes every status/body/latency, percentiles, client CPU, and a clear
`not_sampled` server-resource marker. Server cgroup memory/CPU must be captured
separately by the disposable deployment harness before any comparison claim.
Run in alternating framework order and record host contention. Never supply a
non-loopback URL. No load test runs in CI; only deterministic unit tests do.

## Soak runner status

`soak_check.py` is a bounded rehearsal harness, not the release soak gate. It
supports a maximum of 24 hours and 2 sequential requests/second. Use only with a
disposable local endpoint and an operator-created stop file:

```sh
python3 benchmarks/release/soak_check.py --url http://127.0.0.1:8080/api/status \
  --duration-hours 1 --rate 2 --concurrency 1 \
  --stop-file /tmp/opencode/stop-soak --output /tmp/opencode/soak.json
```

Current scope cannot observe server cgroup memory, restarts, per-session state,
or actual concurrency. Its memory slope is therefore `null`; it cannot close
the 24-hour soak gate. A 5-minute disposable rehearsal and instrumentation are
required before any 24-hour run. `concurrency` is bounded for planned use but
does not increase offered load in this sequential prototype.

Never use `--stop-file` as an output path: stopping is by creating that file
while the process runs. Its parent must already exist, and the file must not
exist at start.

## Stress runner status

`stress_check.py` is an opt-in bounded loopback ramp, not a measured breaking
point. It caps each level at 60 seconds and 10,000 total requests, ramps only
through the fixed levels 4/16/32/64/128, and preserves raw responses. It does
not inspect server resource caps or calibrate client capacity; its apparent
breaking point is therefore provisional. Do not interpret or run it against
anything except a disposable local service. No stress run occurs in CI.

## Spike/recovery runner status

`spike_check.py` encodes baseline, bounded spike, and recovery phases. It uses
response-only observations, caps the spike at 10 seconds, recovery at 10 minutes,
peak concurrency at 128, and total runtime at 15 minutes. It does not monitor
server resources or process health, so its result cannot independently satisfy
the recovery release gate. Run only against a disposable local service; CI runs
the phase/threshold tests only.

## Cold-start runner status

`cold_start.py` accepts at most 20 fresh disposable containers, caps readiness at
30 seconds, and captures the local image ID. It separates readiness, first HTTP
request, and a second warm request. Current target is a tiny built-in PHP server
fixture, not Orku/Laravel over FPM/Nginx; its readiness request also warms the
process. This is harness validation only, not framework cold-start evidence.
CI tests metric separation and failure retention; no cold-start run is scheduled.
