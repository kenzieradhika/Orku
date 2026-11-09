# Authentication

Orku AuthCore is optional. It provides password verification, a session-backed
guard and callable authentication middleware. Applications supply their
credential provider and an atomic shared throttle store. No database or
process-local throttling implementation is included.

Login and logout require a verified `CsrfManager` token. Validate the token
before calling `LoginService::login()` or `Guard::logout()`. The login service
also verifies it, so callers cannot bypass the check accidentally.

The guard enforces a 30-minute idle lifetime and a 12-hour absolute lifetime.
Successful login rotates the session ID. Logout clears all session data,
invalidates the previous ID and expires the session cookie. Configure the
existing session cookie as Secure under HTTPS; do not enable insecure cookies
in production.

`ThrottleStore::consume()` must atomically enforce its limit across workers.
Use a database transaction, atomic cache primitive or gateway integration.
The account identifier and source context form the throttling key. Source
context must come from a trusted boundary. Do not trust forwarded IP headers
unless trusted proxies are explicitly configured.

`CredentialProvider` owns identity lookup and password-hash storage. Use
`PasswordHasher` to create hashes; it selects Argon2id when available and
otherwise uses PHP's `PASSWORD_DEFAULT`. Never store plaintext passwords or
log credentials, session IDs, or submitted secrets. Authentication alone does
not authorize access to a resource. Add explicit policy and ownership checks
before serving protected data.
