# Focused source security review

Reviewed source: Orku `ad42b29`, authentication/session/routing/API boundaries.
Mode: focused read-only review, not a comprehensive audit or release approval.
No attack against deployed/shared services. No new dynamic security validation
was executed for this review. Earlier regression/CI results are historical
supporting context, not independent verification of all security properties.

## Result

No concrete exploitable boundary violation confirmed in the inspected paths.
This does not establish that the framework is secure or ready for authentication
workloads. No severity is assigned to missing functionality or unproven risks.

| Area | Source inspected | Evidence and limits |
| --- | --- | --- |
| Authentication | `examples/rest-api/app.php`, `examples/fullstack-app/app.php`, `src/Core/Kernel.php` | No credential verifier, login/logout, bearer token validation or authorization policy exists. Public example user route echoes the supplied ID; it does not fetch another user's record. No account takeover/IDOR claim is justified. |
| Session | `src/Web/Session.php:34-84` | Strict-mode native sessions, bounded ID syntax, explicit ID rotation deleting the old record, Secure/HttpOnly/SameSite=Lax defaults, no-store commit and request-local cleanup. Backend is PHP's configured session handler. No explicit absolute/idle expiry or logout invalidation API. |
| CSRF | `src/Web/CsrfManager.php:15-30`, contact handler | 32 random bytes, 64-character hex token, constant-time comparison. Contact POST checks CSRF before form validation. Not globally installed; applications must protect each state-changing cookie-authenticated route. |
| Routing | `src/Routing/Router.php:22-67,125-190`, `src/Http/Request.php:29-40` | Literal route escaping, whole-segment parameters, duplicate-shape rejection, static precedence, explicit method matching and HEAD fallback. Encoded slash/backslash, malformed percent encoding, control bytes and dot segments rejected. No built-in resource authorization or route-level auth middleware. |
| API/input | `src/Http/Request.php:43-70`, `src/Runtime/SapiRuntime.php:14-65`, `src/Web/FormRequest.php:17-46` | Bounded body reads/default 1 MiB, decimal length validation, content-type-gated JSON, parse-error responses, smaller bounded form parser and duplicate-field rejection. Upstream buffering/timeouts/duplicate HTTP header behavior depend on the web server. |
| API/output | `src/Core/Kernel.php:51-89`, `src/Http/Headers.php:12-25`, SAPI emitter | Production exceptions return generic errors; debug explicitly discloses diagnostics. Header controls and duplicate normalized names rejected. Default CSP/nosniff provided. Applications can intentionally override policy. |

## Release blockers / deployment validation

1. Do not advertise built-in authentication, logout, token revocation or brute-force
   resistance. Implement and review an application-level identity/authorization
   policy before exposing protected data. Add ID rotation after login, old-session
   rejection after logout, expiry and bounded login throttling when auth is added.
2. Validate session storage ownership, cleanup, handler strict-mode support,
   isolation between apps, expiry and backup/restore behavior in the real deployment.
   Those properties cannot be inferred from PHP defaults or this source alone.
3. Validate TLS/Secure-cookie delivery, proxy trust, upstream body/header limits,
   slow-request timeouts and production debug-off configuration independently.
4. Cover authorization on every route/method including HEAD and dynamic/static
   overlaps. Router matching is not an authorization mechanism. Do not decode
   route parameters a second time in an application filesystem lookup.
5. A fresh reviewer and sandboxed negative tests are still required before closing
   the release security/API gate. This review excludes database, view rendering,
   supply chain, CI and unrelated runtime/ThinkCore code.

No authentication security guarantee or full-coverage claim follows from this
review. The stable-release security gate remains open.

## Bounded fuzz preparation

`tests/release/fuzz_http_inputs.py` generates a deterministic, capped corpus for
HTTP path/header/body/form/route/CSRF input classes. CI tests only reproducibility
and bounds; corpus generation does not execute target parsers and is not security
validation. Parser execution is not claimed: this environment lacks an enforced
offline sandbox with resource limits. Authorization coverage remains not
applicable until authentication/authorization features exist. Run target code
only after an isolated, no-network sandbox and a disposable loopback fixture are
available; preserve only minimal synthetic reproducers.
