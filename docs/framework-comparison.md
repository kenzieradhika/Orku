# Local stateless JSON comparison

Scope: Orku `ad42b29` and Laravel framework `v12.71.0`, local Linux Docker.
This is a minimal application comparison, not a full application or optimized
Laravel deployment comparison. No database, authentication, session or templates.

Both serve GET `/api/status` with exactly
`{"framework":"benchmark","status":"running"}`. Same PHP 8.4.26 FPM image,
Nginx 1.28 Alpine image, FastCGI configuration, four static FPM workers, one CPU,
256 MiB RAM/no additional swap and 128 process limit per container. OPcache is
enabled with 64 MiB allocation and timestamp validation disabled. Debug output
is disabled. Both use Composer autoloaders. Laravel default global middleware
remains; Orku has no corresponding middleware. HTTP headers may differ.

Laravel uses `Application::configure`, explicit route registration and no app
providers. Neither configuration nor routes are cached; Composer autoloaders
are not explicitly optimized. This is NOT the recommended optimized Laravel
production profile. Laravel uses bounded writable tmpfs for bootstrap/storage.
Dependencies were installed separately in `/tmp/opencode/orku-laravel-benchmark`;
existing applications and services were not used or changed.

Three batches per concurrency, 1,000 measured requests plus 50 sequential warmup
requests per batch. Fresh TCP connection per GET, concurrency 4/16/64/128,
closed-loop Python client, 10-second request timeout, 120-second batch deadline.
Frameworks run sequentially (Orku first), not concurrently. Client shares the
host; no CPU affinity, randomized order or quiet-host guarantee. Results are
machine-specific and sensitive to shared-host contention and client scheduling.

| Concurrency | Orku median requests/s | Laravel median requests/s | Orku median batch P95 ms | Laravel median batch P95 ms |
| --- | ---: | ---: | ---: | ---: |
| 4 | 1098 | 259 | 5.52 | 76.14 |
| 16 | 2508 | 266 | 8.99 | 92.86 |
| 64 | 2238 | 266 | 36.11 | 289.80 |
| 128 | 2401 | 259 | 33.14 | 545.37 |

All 24,000 measured requests returned 200; no measured transport errors or OOM
kills. The P95 column is a median of batch percentiles, not a pooled percentile.
Higher concurrency mainly increases queueing once the four workers saturate.
The non-monotonic Orku results warn against interpreting this short run as a
capacity ceiling or quoting an unconditional speed multiplier.

| Concurrency | Orku FPM max sampled MiB | Laravel FPM max sampled MiB | Orku median FPM CPU seconds/batch | Laravel median FPM CPU seconds/batch |
| --- | ---: | ---: | ---: | ---: |
| 4 | 21.59 | 35.06 | 0.453 | 4.114 |
| 16 | 21.76 | 35.85 | 0.429 | 4.038 |
| 64 | 20.80 | 34.89 | 0.450 | 4.079 |
| 128 | 21.18 | 34.83 | 0.432 | 4.122 |

Resource counters are whole-container cgroup v2 accounting, not PHP heap.
CPU snapshots include warmup and probe overhead; batch CPU values therefore
cover 1,050 requests, not exactly 1,000. Sampled memory is not peak memory.
Raw JSON also records Nginx resources and container lifetime peaks. JSON from
this local run is `/tmp/opencode/orku-laravel-comparison.jsonl`, ephemeral, not
a durable release artifact. Pin and archive dependency locks/image digests,
hardware and raw data before using these numbers as published release evidence.

Reproduce with a disposable directory containing Laravel 12 Composer dependencies:

```sh
python3 benchmarks/compare_frameworks.py --laravel /path/to/disposable/laravel-dependencies
```

The runner never installs dependencies, modifies that dependency directory or
publishes FPM. Only Nginx receives an ephemeral loopback port. It creates and
cleans up its own containers/network. This is an opt-in benchmark, not a security
test or CI performance gate. Next comparison should add optimized Laravel caches,
longer timed runs, alternating framework order, isolated client CPU and several
independent repetitions before making production performance claims.
