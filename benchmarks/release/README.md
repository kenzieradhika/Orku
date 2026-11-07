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
