# Orku Web measurements

2026-10-25, Linux x86_64, PHP 8.4.21 static CLI build, CLI OPcache disabled.
Shared development host, no CPU pinning, no production FPM or cross-framework
comparison. Results describe this run, not a capacity or speed guarantee.

## In-process

```bash
php benchmarks/web.php 10000
```

Each case: 1,000 warmups, then 10,000 sequential samples using hrtime. Fixtures
under `benchmarks/views`, five components per component sample. Renderer reused,
validated template lookup cached. PHP files still included each render; no compiler.
Validation sample parses bad email, validates it and renders HTTP 422 HTML.
Microcases measure different boundaries: HTML construction alone versus render,
and Kernel cases include Request construction/route dispatch. Do not compare these
as equal-work endpoints or interpret them as HTTP throughput.

| Case | Mean µs | P95 µs |
|---|---:|---:|
| Core JSON | 3.437 | 3.657 |
| Web-enabled API | 3.677 | 3.667 |
| Plain HTML response | 0.739 | 1.242 |
| One template | 10.246 | 13.695 |
| Template + layout | 23.105 | 28.273 |
| Layout + five components | 80.727 | 91.579 |
| Validation failure HTML | 19.384 | 24.946 |

Same-process API comparison: +0.240 µs mean (about 7.0%), with differing route
tables. Web page renderer stays lazy on API route. This is not an isolated causal
estimate of package overhead and excludes per-request application startup.
Unchanged v0.1 example remains available for HTTP baseline.

Additional 10,000 component-layout renders after warmup: PHP used-memory delta
0 bytes in this run. Peak allocated PHP memory 4,194,304 bytes including harness;
process maximum RSS 34,016 KiB from Linux getrusage. One repeat loop is not proof
against every leak. P95 came from sorted individual samples, not mean extrapolation.

## Local HTTP

Start `hello-world` on 8093 and `fullstack-app` on 8094 using same PHP binary,
with ORKU_DEBUG unset for both:

```bash
php bin/orku serve hello-world 127.0.0.1:8093
php bin/orku serve fullstack-app 127.0.0.1:8094
python3 benchmarks/http_latency.py http://127.0.0.1:8093/api/status
python3 benchmarks/http_latency.py http://127.0.0.1:8094/api/status
python3 benchmarks/http_latency.py http://127.0.0.1:8094/
```

Python stdlib harness: 50 warmups, 500 sequential measured GETs, new TCP connection
per request, full body read, concurrency one, loopback. No cookies/sessions on
these routes. Throughput below is reciprocal of mean measured HTTP duration,
excluding client-loop overhead; it is not concurrent production saturation.

| Endpoint | Mean ms | P95 ms | Sequential requests/s | Errors |
|---|---:|---:|---:|---:|
| Core `/api/status` | 4.401 | 4.945 | 227.23 | 0 |
| Web `/api/status` | 4.677 | 5.216 | 213.80 | 0 |
| Web `/` | 5.100 | 5.661 | 196.06 | 0 |

Measured Web API startup/route-table difference: +0.276 ms mean (6.3%). Earlier
exploratory debug-enabled run is excluded from this table. PHP CLI
server is development-only. No Go/Laravel/ARM/Apache/Nginx/FPM performance claim.
Raw logs originally captured under `/tmp/opencode/orku-*-benchmark.json` and
`/tmp/opencode/orku-http-*.json`; ephemeral files are not required to reproduce.
