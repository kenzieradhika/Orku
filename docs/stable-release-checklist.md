# Stable release gates

Status: not release-ready. No stable tag is authorized by passing unit tests alone.
Track evidence here; do not silently redefine ORK.md as completed.

- [x] PHP 8.4/8.5 unit, integration, static analysis and formatting CI.
- [x] Dependency audit and Composer validation.
- [x] Bounded Linux cgroup v2 read-only observation, explicit unknowns.
- [x] Concurrent loopback benchmark tool and overlap regression.
- [ ] Representative API benchmark with matched endpoint contracts and raw errors; current benchmark is stateless and has not validated this gate (`benchmarks/release/api_check.py`).
- [x] Isolated Apache/mod_php routing and public-root smoke check.
- [x] Isolated Nginx/PHP-FPM routing and public-root smoke check.
- [ ] Sustained workload, memory growth and state-isolation evidence.
- [x] Session/form deployment checks under web-server identity (isolated FPM default file storage).
- [ ] TLS/proxy deployment and rollback runbook, verified on claimed runtime.
- [ ] Public API compatibility policy and migration guide.
- [ ] Security/API review with all release-blocking findings resolved.
- [x] Clean Composer consumer installation and Git distribution check (API dispatch and CLI help, PHP 8.4).
- [ ] Reconcile README, architecture, roadmap, examples and changelog.
- [ ] Release candidate soak period and explicit readiness decision.
- [ ] Publish stable tag/release only after the gates above pass.

Remaining feature proposals are not silently included in a stable API: Loom,
persistent workers, auth workflows, uploads, cross-driver migrations and tuning
need their own implementation/verification gates. Experimental namespaces must
be clearly excluded from compatibility guarantees or completed before inclusion.

Execution order: deployment checks, workload/session checks, compatibility and
distribution, review, release candidate, stable decision. Each increment requires
local checks before push, then CI. Never modify running user services to test.
