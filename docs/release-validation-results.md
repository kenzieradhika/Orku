# Release validation results

Status: **not release-ready**. This index records bounded harness validation,
not proof that every P0/P1 workload gate passed. The live checklist is
`docs/stable-release-checklist.md`.

| Area | Commit | Result | Remaining limitation |
| --- | --- | --- | --- |
| Loopback API runner | `ffd9c46` | Unit/contract tests; CI passed PHP 8.4/8.5. | No representative Orku/Laravel API comparison. |
| Disposable SQLite/PDO | `52f1f45` | Five-iteration synthetic sample; commit/rollback/cleanup; zero errors. | No app migration, Laravel comparison, other DB driver, or resource-limited run. |
| Soak rehearsal harness | `6b1e4a4` | Bounds/stop/error-window tests; CI passed. | Sequential only; no server resource/session instrumentation; no 24h run. |
| HTTP fuzz corpus | `21781c1` | Deterministic bounded corpus tests; CI passed. | Parser execution not run; no enforced offline resource sandbox. |
| Stress ramp | `55ee0ad` | Bounded runner and threshold tests; CI passed. | No client calibration or server resource metrics; no workload run. |
| Spike/recovery | `7b55517` | Phase/threshold tests; CI passed. | No disposable deployment run or process/resource metrics. |
| SQLite concurrency | `cb45f93` | 320 operations at 1/4/16/32 workers; exact state and lock reporting passed. | No concurrent HTTP/session/CSRF validation. |
| Cold start | `06ce1e2` | Bounded micro-app runner/tests; CI passed. | No Orku/Laravel FPM/Nginx comparison; no run evidence. |
| SSR/template safety | Current branch changes | Real Orku view output validated for static, UTF-8, null/empty, nested layout, escaped marker; raw `TrustedHtml` marked unsafe. Five repeats × 1,000 renders completed; P50 7.81 µs, P95/P99 8.80 µs per render on this host; PHP peak-memory delta 0 bytes at allocator granularity. | Laravel fixture unavailable; no HTTP comparison. Not comparative evidence; host-specific microbenchmark only. |
| ThinkCore fixtures | `4d03ffa` | PHPUnit and `composer check` green on PHP 8.4/8.5 by `4d03ffa`. | Live cgroup/Docker and `memory.current` not verified. |
| Hardware matrix | `4d03ffa` + current branch changes | Local Linux x86_64/PHP 8.3.6 metadata captured; classified unsupported. | ARM64 and constrained native runtime unavailable; behavioral checks pending. |

Branch `release-validation-native`; PR #1 remains open and unmerged. CI for
`cb45f93` passed. CI failure for `4e3d39e`/`cb27023` was formatter-only; latest
run on `4d03ffa` passed PHP 8.4 and 8.5. Follow-up documentation and Task 11
metadata tests require CI after commit.
