# Bounded resource experiment

Run `python3 tests/resource_check.py` on Linux with Docker and cgroup v2.
Requires readable `cpu.stat`, `memory.current`, `memory.peak`, `memory.events`.
Missing counters fail the check, never produce fabricated zero measurements.

The existing isolated Nginx/FPM runner creates disposable containers with
read-only source mounts and an ephemeral loopback HTTP port. Each container is
limited to one CPU, 256 MiB memory (no extra swap) and 128 processes. No existing
services are modified. The experiment performs three rounds of 1,000 GETs to
`/api/status`, concurrency four, after the existing deployment smoke warmup.
Each round has a 120-second client deadline. Request errors or new OOM kills
fail the run. Cleanup executes even on failure.

JSON includes each round's HTTP errors/latencies, CPU seconds, average CPU percent
relative to one core, current memory, lifetime container memory peak and OOM
counter. CPU percent is not host CPU percentage. Memory is cgroup accounting,
not PHP heap or process RSS; cache and all container processes count. Container
exec probes add small CPU/memory overhead. Snapshots are taken sequentially;
the memory peak includes startup/warmup, not only the measured round. The
client's resources are excluded. CPU usage here uses container CPU time divided
by client wall time, not the framework's own execution time.

Local run (2026-11-07, PHP 8.4.26 FPM, Nginx 1.28 Alpine): all 3,000 measured
requests succeeded; no OOM kills. FPM final memory: 18.77 MiB, lifetime peak:
19.59 MiB. Nginx final memory: 19.23 MiB, lifetime peak: 20.41 MiB. Per 1,000
requests FPM CPU: 0.276–0.282 seconds; Nginx CPU: 0.256–0.264 seconds. Full
local JSON: `/tmp/opencode/orku-resource-results.jsonl` (not a durable release
artifact). These are machine-specific observations, not capacity guarantees.

This short experiment is not a sustained soak, memory-leak proof, authentication
brute-force test, rate limiter or DDoS defense. There is no login/password
endpoint in this workload. Do not mark the sustained-load release gate complete.
CI tests the measurement arithmetic; the load experiment remains opt-in.
