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
