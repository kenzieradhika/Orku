# HTTP middleware

Optional middleware is the last `Kernel` constructor argument; existing calls
remain valid. No new packages or container integration are required.

```php
$kernel = new Orku\Core\Kernel($router, middleware: [
    function (Orku\Http\Request $request, callable $next): Orku\Http\Response {
        if ($request->header('x-maintenance') === '1') {
            return Orku\Http\Response::text('Unavailable', 503);
        }

        return $next($request);
    },
]);
```

The example demonstrates short-circuiting, not an authorization policy. Do not
trust request headers for authorization or production maintenance controls.

Middleware receives `(Request $request, callable $next): Response`;
`$next(Request): Response` invokes the remaining pipeline or router. Incoming
execution follows registration order; responses unwind in reverse order. Return
a response without calling next to skip later middleware and routing. Call next
at most once synchronously; retries and deferred execution are not provided.

Exceptions propagate through outer middleware, then use the kernel's existing
error/report/renderError handling. Kernel-generated errors are outside the
pipeline: outer middleware does not automatically receive or decorate them unless
it explicitly catches the exception itself. Normal 404/405 behavior is preserved.
Invalid callable registrations fail during construction; invalid response types
are handled as server errors, without exposing internal details by default.

The pipeline is built once and holds middleware callables, not a current request.
Application-owned captured state remains the application's responsibility. No
request-scope creation, DI resolution, PSR-15 compatibility, route middleware,
authentication, rate limiting or worker-isolation guarantee is implied.
