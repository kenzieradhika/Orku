# Release validation results

Status: **not release-ready**. This index records bounded harness validation,
not proof that every P0/P1 workload gate passed. The live checklist is
`docs/stable-release-checklist.md`.

| Area | Commit | Result | Remaining limitation |
| --- | --- | --- | --- |
| Loopback API runner | `ffd9c46` | Unit/contract tests; CI passed PHP 8.4/8.5. | No representative Orku/Laravel API comparison. |
| Disposable SQLite/PDO | `52f1f45` | Five-iteration synthetic sample; commit/rollback/cleanup; zero errors. | No app migration, Laravel comparison, other DB driver, or resource-limited run. |
| SQLite wrapper reliability | `perf/database-reliability-evidence` | `Orku\Data\Database` point lookup/list/transaction coverage plus raw-PDO control; 100-iteration sample (PHP 8.4.27, SQLite 3.40.1, 2 CPU/512 MiB): 0 errors, P50 0.044 ms lookup vs 0.030 ms PDO control, 7.09 ms transaction cycle; 320-operation concurrency at 1/4/16/32 workers exact state, 0 lock errors. | Wrapper overhead is single-host microbenchmark evidence; no Laravel or alternate-driver parity. |
| Soak rehearsal harness | `6b1e4a4` | Bounds/stop/error-window tests; CI passed. | Sequential only; no server resource/session instrumentation; no 24h run. |
| HTTP fuzz corpus | `21781c1` | Deterministic bounded corpus tests; CI passed. | Parser execution not run; no enforced offline resource sandbox. |
| Stress ramp | `55ee0ad` | Bounded runner and threshold tests; CI passed. | No client calibration or server resource metrics; no workload run. |
| Spike/recovery | `7b55517` | Phase/threshold tests; CI passed. | No disposable deployment run or process/resource metrics. |
| SQLite concurrency | `cb45f93` | 320 operations at 1/4/16/32 workers; exact state and lock reporting passed. | No concurrent HTTP/session/CSRF validation. |
| Cold start | `06ce1e2` | Bounded micro-app runner/tests; CI passed. | No Orku/Laravel FPM/Nginx comparison; no run evidence. |
| SSR/template safety | `42bd18c` | Real Orku view output validated for static, UTF-8, null/empty, nested layout, escaped marker; raw `TrustedHtml` marked unsafe. A prior five-batch run reported means as P50/P95/P99 (7.81/8.80/8.80 µs); those are batch-mean percentiles, **not individual-render percentiles**. | Per-render methodology is being corrected; no new measurement yet. Laravel fixture unavailable; no HTTP comparison. Prior figures are not comparative evidence. |
| ThinkCore fixtures | `4d03ffa` | PHPUnit and `composer check` green on PHP 8.4/8.5 by `4d03ffa`. | Live cgroup/Docker and `memory.current` not verified. |
| Hardware matrix | `4d03ffa` + current branch changes | Local Linux x86_64/PHP 8.3.6 metadata captured; classified unsupported. | ARM64 and constrained native runtime unavailable; behavioral checks pending. |

Branch `release-validation-native`; PR #1 remains open and unmerged. CI for
`42bd18c` passed PHP 8.4 and 8.5; that CI predates the local SSR corrections and
does not verify them. No corrected SSR benchmark result has been executed or
published. The local correction distinguishes individual render samples from
batch means, reports timing overhead, and marks renderer memory, PHP allocator
peak, and RSS `UNMEASURED`; PHP allocator counters are not process RSS.
