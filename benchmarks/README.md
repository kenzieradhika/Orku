# Benchmarks

```bash
php benchmarks/kernel.php 10000
php -d opcache.enable_cli=1 benchmarks/kernel.php 10000
```

This sequential, in-process microbenchmark measures fresh Request construction,
kernel dispatch and Response construction for text, JSON and dynamic routes.
Route registration and autoloading occur before timing. Each case warms up for
1,000 iterations. Percentiles use nearest rank on per-dispatch samples; elapsed
time includes sample collection. Peak memory includes Composer and the benchmark's
latency array, not framework-only memory. Results are JSON with runtime metadata.

It does **not** measure network HTTP throughput, concurrency, cold bootstrap,
database operations, middleware, worker isolation, process RSS or CPU utilization.
Do not use dispatches/second as requests/second. No Laravel, Symfony, lightweight
framework or Go comparisons have been run or claimed.

Future HTTP comparison suite must pin framework versions, hardware, worker counts,
runtime/OPcache settings, HTTP client, keep-alive policy, concurrency, duration and
identical endpoints. Capture errors, throughput, mean/P95/P99 latency, RSS, peak
memory and CPU together; publish scripts and raw results, not isolated wins.

## Sequential and concurrent HTTP baseline

Python 3 standard library only. Start the PHP CLI example in another terminal:

```bash
php bin/orku serve hello-world 127.0.0.1:8000
```

Then capture raw JSON (results are git-ignored):

```bash
mkdir -p benchmarks/results
python3 benchmarks/http_latency.py http://127.0.0.1:8000/api/status \
  --runtime-label 'PHP 8.4 CLI server, OPcache disabled, one process' \
  --warmup 50 --requests 500 > benchmarks/results/cli-status.json
python3 -m unittest discover -s benchmarks -p 'test_http_latency.py'
```

For an already configured local Nginx/Apache + PHP-FPM application, use its actual
loopback URL and record its PHP version, OPcache settings and pool configuration:

```bash
python3 benchmarks/http_latency.py http://127.0.0.1:8080/api/status \
  --runtime-label 'Replace with actual PHP-FPM version, OPcache and pool settings' \
  --requests 500 > benchmarks/results/fpm-status.json
```

This command does not configure FPM or verify the operator-supplied label. FPM
deployment and measurements remain unverified until run against a real setup.
Preserve server image/version, host hardware/OS, resource limits and commands with
each result. Never put credentials or secrets in URLs or labels.

Default concurrency is 1. Set `--concurrency 4` (range 1..128) for bounded
closed-loop workers. Each worker starts its next request only after completing
the previous one; this is not a fixed arrival-rate load generator. Warmup stays
sequential. The timer includes worker startup/shutdown; individual latency starts
when a worker opens its request, excluding time before that worker starts.
No more than the requested number of attempts is sent, even when concurrency
exceeds request count. Do not increase load on a shared local service without
operator approval. Example:

```bash
python3 benchmarks/http_latency.py http://127.0.0.1:8000/api/status \
  --runtime-label 'PHP CLI development server, one process' \
  --warmup 10 --requests 100 --concurrency 4
```

The client makes GET requests with fresh connections, no redirects,
a 10-second timeout and bounded 64 KiB response-read chunks. Queries are preserved.
Only ASCII loopback HTTP URLs without credentials/fragments are accepted; percent-encode
non-ASCII paths and query values. A non-200 or
transport failure counts as an error; measured errors produce exit code 1. Invalid
arguments produce exit code 2. Warmup errors are recorded separately, excluded
from the measured error count and exit code.

JSON includes nearest-rank P50/P95/P99 and mean latency in milliseconds, elapsed
wall seconds, attempted requests/second including client loop overhead, HTTP
status counts, transport failures, and error rate (0..1). Latency samples include
failed attempts; throughput is not successful requests/second. Warmup is excluded.

Optional `--server-pid PID` samples Linux `/proc` before and after measurement.
RSS is reported in bytes at those two points, **not peak RSS**. CPU is the selected
process's user+system seconds delta, not CPU percent. Children, FPM pools, proxies,
containers and other PID namespaces are not aggregated. An exited/reused or
unreadable process leaves unavailable metrics null with a reason. Without a PID,
resource metrics are null. These are not sustained memory-growth measurements.

This baseline does not establish production capacity, cold bootstrap,
pool-wide resources or comparative framework performance.

## CRUD/API comparison over SQLite

`benchmarks/crud-api/` contains disposable Orku and Laravel 12.71.0 fixtures that
share one contract, schema, seed and PHP request-semantics implementation, plus
equal PHP-FPM/Nginx containers. Run `verify_parity.py` before `run_benchmark.py`;
see that directory's README for bounded load, integrity checks, cgroup sampling
and the exact environment metadata that must accompany any published number.
