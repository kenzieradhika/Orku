# Production-optimized comparison

Source: `optimized-comparison.json`. Raw JSON contains 80 batch observations:
two clean sessions × five repeats × four concurrency levels × two frameworks.
Each batch measures 1,000 requests after a 200-request sequential OPcache warmup.
The runner has `--keep-alive`; each client worker reuses one TCP connection.

Laravel 12.71.0 dependencies are installed with `--no-dev`, then Composer's
authoritative classmap is generated. Laravel runs `route:cache` followed by
`config:cache`; FPM restarts before warmup. Cache files verified:
`config.php`, `packages.php`, `routes-v7.php`, `services.php`. OPcache enabled,
timestamp validation disabled, 128 MiB, 20,000 file slots, JIT disabled. Orku
uses the same PHP-FPM, Nginx, FPM pool, OPcache and container limits. Laravel is
a minimal stateless JSON app, not an existing application. Default global
middleware remains enabled.

| Concurrency | Framework | Median req/s | Median P95 ms | Median P99 ms | Errors | Max sampled FPM MiB |
|---:|---|---:|---:|---:|---:|---:|
| 4 | Orku | 4,818 | 0.70 | 2.94 | 0 | 23.32 |
| 4 | Laravel | 582 | 72.18 | 76.23 | 0 | 38.15 |
| 16 | Orku | 4,197 | 5.28 | 54.28 | 0 | 23.05 |
| 16 | Laravel | 577 | 83.39 | 85.25 | 0 | 38.12 |
| 64 | Orku | 4,266 | 46.97 | 56.61 | 0 | 23.42 |
| 64 | Laravel | 547 | 181.93 | 185.89 | 0 | 38.06 |
| 128 | Orku | 4,349 | 57.65 | 63.88 | 0 | 23.26 |
| 128 | Laravel | 577 | 282.71 | 284.83 | 0 | 37.84 |

The reported P95/P99 values are medians of individual batch percentiles; raw
per-batch values are preserved. Do not present them as pooled percentiles or a
general framework speed claim. This closed-loop client shares the host, and
frameworks run sequentially (Orku first). At 16+ concurrency Orku's P99 is much
higher than its median, likely reflecting queueing/tail effects; this is not a
production capacity test. CPU counters include request work and measurement
overhead. Memory is cgroup accounting, not PHP heap.

Reproduce: provision Laravel dependencies in a disposable directory; install
production packages, then run:

```sh
python3 benchmarks/compare_optimized.py \
  --laravel /path/to/laravel-dependencies \
  --output /path/to/report.json
```

The script runs `composer dump-autoload --classmap-authoritative --no-dev`, so
only use a disposable dependency directory. It creates and removes its own
containers and Docker network; FPM is not published to the host. Nginx binds an
ephemeral loopback port. Runtime artifacts never write into this repository.
Local raw data was generated 2026-11-07; host and image metadata are embedded in
the JSON. Results are evidence for this exact setup only.
