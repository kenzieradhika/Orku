# Named routes and prefix groups

```php
$router->group('/api', function (Orku\Routing\Router $routes) use ($handler): void {
    $routes->get('/users/{id}', $handler, name: 'users.show');
});
$path = $router->url('users.show', ['id' => 42]); // /api/users/42
```

Every HTTP helper and `add()` accepts an optional final `name` argument. Names
are globally unique and nonempty. Existing unnamed registrations remain valid.
`routes()` preserves its existing method/path output.

Groups concatenate prefixes and may nest. Prefixes are literal absolute paths,
not placeholders; trailing prefix slashes are removed. A child `/` retains its
trailing slash (`/api/`), distinct from `/api`. The prefix always restores after
the callback, even when it throws. Routes already registered by a failing group
remain registered; groups are not transactions.

`url()` returns an absolute-path URL, never a host or scheme. Parameters must be
nonempty strings or integers. Missing/extra parameters and unknown names fail.
Each segment is raw-URL-encoded; generated paths use Request validation. Dot
segments, separators, controls and invalid UTF-8 fail instead of generating links
the runtime cannot accept. Escape URLs for HTML attribute context in templates.

Static precedence, dynamic registration order, HEAD fallback and 404/405 remain
unchanged. No automatic query strings, name prefixes, route middleware, regex
constraints or compiled cache are provided.
