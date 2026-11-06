# ThinkCore Sense read-only contract

Status: implemented bounded Linux cgroup v2 observe-only baseline. `php bin/orku thinkcore:observe` reports
platform, PHP and parsed PHP memory_limit. On Linux, the additional
`cgroup_visible_memory_limit_bytes` reports the minimum finite `memory.max` across
the visible cgroup v2 ancestry, using process membership and mount mapping.
Null means unavailable, unsupported, malformed, overflowed or entirely unlimited.
`measurement_status` distinguishes each measurement: `available`, `unlimited`,
`unreadable` (missing/denied files), `invalid` (including overflow), `unsupported`
(non-Linux or no unified cgroup v2 membership), and `unmapped` (no matching mount).
`effective_capacity` remains `unknown`, never an invented host total.
Missing ancestor files invalidate the whole measurement, not just that ancestor.
Hidden ancestors outside the mount/namespace cannot be measured. This is not free
RAM or an effective machine memory guarantee; `effective_memory_bytes` and CPU
remain null. `cgroup_visible_cpu_quota` reports the minimum finite quota/period
across visible ancestors, preserving fractional CPU units. `cpu_affinity_count`
counts `/proc/self/status` CPU ranges without allocating an array of CPU IDs.
Malformed, overlapping, unordered or overflowing affinity ranges return null.
CPU quota and affinity are separate snapshots, not guaranteed available capacity;
hidden ancestor quotas and concurrent runtime changes remain unknown.
No auto-tuning,
recommendations, outbound telemetry, persistence or production mutation.

The observe-only baseline is complete within this scope, not the complete
ThinkCore proposal. cgroup v1 and non-Linux resource sensing are explicitly
unsupported. Measurements are best-effort, non-atomic snapshots; hidden ancestors
cannot be discovered from a restricted namespace. The CLI accepts no reader paths.
Only fixed proc metadata and resource-limit files are read; no environment dump,
command lines, credentials, user files or network access. Null is a valid result,
not permission to tune. Configuration is never changed. Worker sizing and tuning
require later workload/RSS/latency measurements and separate approval.

Initial output: JSON with platform, PHP version, configured PHP memory limit,
effective CPU quota, effective memory ceiling and explicit availability/reason
fields. Unknown is null, never guessed from host totals. Do not read environment
variables wholesale, request data, process command lines or credentials.

Read Linux cgroup v2 membership from `/proc/self/cgroup` and mount mapping from
`/proc/self/mountinfo`; do not assume a host mount path. Effective limits are the
minimum finite constraints on the ancestor hierarchy, accounting for affinity
and quota/period. Keep fractional CPU quotas. `max` and `-1` mean unlimited only
where that specific format defines them. Reject malformed/overflowing values.

Other platforms and unsupported cgroup v1 return explicit unknown fields, not
an unsupported claim of accurate detection. Resource readers accept fixture
directories in tests, not arbitrary user-supplied paths through the public CLI.

Tests must cover nested limits, unlimited values, fractional quota, absent or
denied files, malformed content, mount escaping and namespace-relative paths.
No worker-count recommendations until actual worker RSS/latency observations
exist. No root requirement, outbound telemetry, persistent storage or config writes.
