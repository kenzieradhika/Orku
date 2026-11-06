# Scoped dependency container RFC

Status: initial explicit-factory implementation available on the scoped-container
branch. Run `php examples/container.php`. Not integrated into the HTTP kernel.

## Scope

Provide explicit object factories and lifetimes without changing existing router,
handler or kernel contracts. No reflection/autowiring, service discovery,
configuration files, worker runtime or mandatory container bootstrap in this increment.
Constructor injection is ordinary application PHP inside registered factories.

## Proposed API

```php
$container = new Orku\Container\Container();
$container->bind(Logger::class, fn ($services) => new Logger(), lifetime: 'singleton');
$container->bind(CurrentUser::class, fn ($services) => new CurrentUser(), lifetime: 'request');
$container->bind(Handler::class, fn ($services) => new Handler(
    $services->get(Logger::class),
    $services->get(CurrentUser::class),
), lifetime: 'transient');

$scope = $container->scope();
try {
    $handler = $scope->get(Handler::class);
    // Application invokes the handler here.
} finally {
    $scope->close();
}
```

Factories receive a resolver exposing `get(string $id): object`. Service IDs are
nonempty strings; factories return objects. Unknown IDs, duplicate registrations,
invalid lifetime names and circular dependencies fail explicitly. Registrations
freeze when resolution or scope creation starts. Failed factories are not cached.

## Lifetimes and ownership

- `singleton`: one object per container; may depend only on singleton services.
- `request`: one object per explicit scope; may depend on singleton, request or transient services.
- `transient`: new object per resolution; may depend on request services only inside a scope.
- `worker`: reserved, rejected until a worker adapter defines its ownership boundary.

A root `get()` cannot resolve a request service. A singleton must not resolve
request or transient services, even indirectly through another factory. Factories
receive a resolver restricted to the lifetime currently being constructed, not an
unrestricted captured scope. Reject dependency cycles with a service-ID chain;
never include factory arguments, object dumps or secrets in diagnostics.

These checks cover dependencies resolved through the container, not objects
captured manually by application closures or globally stored by application code.
No claim of comprehensive state-leak detection is made.

## Cleanup

`bind()` optionally accepts a release callback for request and transient services.
Each successfully created scope-owned object with a callback is released once in
reverse creation order. Singletons and root-created transients cannot register
request cleanup. Singleton shutdown is outside this increment.

`close()` is idempotent, rejects later resolution, attempts every release callback
even when one fails, and clears cached objects/callbacks in a `finally` path.
Cleanup failures are collected in a typed exception, preserving individual causes
without formatting their messages into HTTP responses. Application integration
must report cleanup errors without masking an earlier handler exception. There is
no destructor-based cleanup guarantee; callers must use `try/finally`.

Closing a scope during factory construction is rejected. Retained resolvers keep
lifetime restrictions but use the currently active construction stack for cycle
detection. Factories are synchronous; concurrent Fiber resolution on one scope
is not supported. Cleanup exceptions can retain application objects through PHP
exception traces until the exceptions themselves are released.

## Acceptance tests

1. Singleton identity persists across two scopes; request identity differs; transient identity always differs.
2. User A, user B, then user A in separate scopes retain no request object or container-managed state across scopes.
3. Root request resolution, direct/indirect singleton lifetime capture, cycles, unknown IDs and duplicate IDs fail clearly.
4. Throwing factories are retried rather than cached; construction stacks unwind after failure.
5. Cleanup runs once in reverse order, including exception paths; one failing release does not skip others.
6. Closed scopes reject resolution and release all container-owned references; verify with `WeakReference`.
7. Two simultaneously open scopes do not share request services.
8. Existing 129 PHP tests, analysis, formatting and dependency audit remain green on supported PHP versions.

## Compatibility and rollout

Keep all implementation under optional `Orku\Container`. No kernel integration
until lifecycle/error handling is covered separately. Publish a runnable example
with explicit construction and cleanup. Local PHP 8.4+ checks precede push;
CI validates PHP 8.4/8.5 afterwards. Update implementation-status docs only when
the implementation and tests ship.

Rollback: remove the optional module and its example; existing applications have
no new bootstrap requirement. Middleware and automatic constructor resolution
remain separate proposals.
