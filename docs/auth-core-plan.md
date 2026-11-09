# AuthCore v0.1 implementation plan

Optional PHP-native authentication for PHP 8.4+, no required database or new dependency.

## Files

- `src/Auth/Identity.php`: immutable user identifier and attributes.
- `src/Auth/CredentialProvider.php`: resolve identity and password hash by submitted account identifier.
- `src/Auth/PasswordHasher.php`: native password hash/verify, Argon2id when available and `PASSWORD_DEFAULT` otherwise.
- `src/Auth/Clock.php`, `SystemClock.php`: injectable Unix-second clock.
- `src/Auth/ThrottleStore.php`: atomic `consume(key, now, window, limit): bool` contract; shared implementations must provide atomic cross-worker semantics.
- `src/Auth/LoginService.php`: validate credentials, throttle, return generic result, rotate session, establish guard state.
- `src/Auth/Guard.php`: validate session identity, idle/absolute expiry; clear authentication state and rotate on logout.
- `src/Auth/RequireAuthentication.php`: existing callable middleware contract, deny unauthenticated requests with 401.
- `src/Web/Session.php`: add explicit invalidation without changing existing methods or behavior.
- `tests/Auth/AuthCoreTest.php`: deterministic expiry, login/logout, generic errors, throttling, middleware, invalid sessions.
- `docs/authentication.md`: CSRF integration, credential storage, shared throttle semantics, configuration and limitations.
- `docs/roadmap.md`: mark auth foundation implemented and authorization policy support pending.

## Security invariants

- Account lookups and password mismatch have identical public failure results.
- Verify a fixed dummy hash when the account is absent to reduce timing-based enumeration.
- Throttle key derives from normalized account plus caller-provided source context; never trust forwarded IP without proxy configuration.
- Throttle backend operation is atomic; no process-local default backend.
- Session auth record stores identity ID and timestamps only; never credentials/password/session IDs in logs.
- Login flow requires a caller-verified CSRF token before credential processing; logout requires CSRF verification before invalidation.
- Successful login rotates the session ID before writing authentication state.
- Idle timeout is 1,800 seconds; absolute timeout is 43,200 seconds; non-positive or future timestamps are rejected.
- Logout removes all session data and expires the browser cookie; old session ID is invalidated.
- Authentication middleware denies by default; authorization/ownership policies are out of scope.

## Verification

Use existing PHPUnit, PHPStan, PHP-CS-Fixer, Composer validation in a non-root, network-disabled container with read-only source, disposable writable scratch, CPU/memory/PID/file limits. Never install dependencies in the validation container. Mark checks NOT RUN if this environment is unavailable.
