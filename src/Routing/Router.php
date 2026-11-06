<?php

declare(strict_types=1);

namespace Orku\Routing;

use Orku\Http\HttpException;
use Orku\Http\Request;
use Orku\Http\Response;

final class Router
{
    /** @var list<array{method: string, path: string, pattern: string, dynamic: bool, handler: callable(Request): Response}> */
    private array $routes = [];
    /** @var array<string, true> */
    private array $registered = [];
    /** @var array<string, string> */
    private array $named = [];
    private string $prefix = '';

    /** @param callable(Request): Response $handler */
    public function add(string $method, string $path, callable $handler, ?string $name = null): self
    {
        if ($name !== null && ($name === '' || isset($this->named[$name]))) {
            throw new \LogicException('Route name must be nonempty and unique.');
        }
        if (!str_starts_with($path, '/')) {
            throw new \InvalidArgumentException('Route path must start with /.');
        }
        $path = $this->prefix.$path;
        if (!preg_match('/^[A-Z]+$/D', $method) || str_contains($path, '%')) {
            throw new \InvalidArgumentException('Invalid route method or path.');
        }
        try {
            new Request($method, $path);
        } catch (HttpException $error) {
            throw new \InvalidArgumentException('Invalid route path: '.$path, previous: $error);
        }
        $names = [];
        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/D', $segment, $matches)) {
                $parameter = $matches[1];
                if (strlen($parameter) > 32) {
                    throw new \InvalidArgumentException('Route parameter names must be at most 32 bytes.');
                }
                if (isset($names[$parameter])) {
                    throw new \InvalidArgumentException('Duplicate route parameter: '.$parameter);
                }
                $names[$parameter] = true;
                $parts[] = '(?P<'.$parameter.'>[^/]+)';
            } else {
                if (str_contains($segment, '{') || str_contains($segment, '}')) {
                    throw new \InvalidArgumentException('Parameters must occupy a full path segment.');
                }
                $parts[] = preg_quote($segment, '~');
            }
        }
        $shape = $method.' '.preg_replace('/\{[A-Za-z_][A-Za-z0-9_]*\}/', '{}', $path);
        if (isset($this->registered[$shape])) {
            throw new \LogicException('Duplicate or equivalent route: '.$method.' '.$path);
        }
        $this->registered[$shape] = true;
        if ($name !== null) {
            $this->named[$name] = $path;
        }
        $this->routes[] = ['method' => $method, 'path' => $path, 'pattern' => '~^'.implode('/', $parts).'$~Du', 'dynamic' => $names !== [], 'handler' => $handler];

        return $this;
    }

    /** @param callable(Request): Response $handler */
    public function get(string $path, callable $handler, ?string $name = null): self
    {
        return $this->add('GET', $path, $handler, $name);
    }

    /** @param callable(Request): Response $handler */
    public function post(string $path, callable $handler, ?string $name = null): self
    {
        return $this->add('POST', $path, $handler, $name);
    }

    /** @param callable(Request): Response $handler */
    public function put(string $path, callable $handler, ?string $name = null): self
    {
        return $this->add('PUT', $path, $handler, $name);
    }

    /** @param callable(Request): Response $handler */
    public function patch(string $path, callable $handler, ?string $name = null): self
    {
        return $this->add('PATCH', $path, $handler, $name);
    }

    /** @param callable(Request): Response $handler */
    public function delete(string $path, callable $handler, ?string $name = null): self
    {
        return $this->add('DELETE', $path, $handler, $name);
    }

    /** @param callable(self): void $register */
    public function group(string $prefix, callable $register): self
    {
        if (!str_starts_with($prefix, '/') || str_contains($prefix, '%') || str_contains($prefix, '{') || str_contains($prefix, '}')) {
            throw new \InvalidArgumentException('Invalid group prefix.');
        }
        try {
            new Request('GET', $prefix);
        } catch (HttpException $error) {
            throw new \InvalidArgumentException('Invalid group prefix.', previous: $error);
        }
        $previous = $this->prefix;
        $this->prefix .= rtrim($prefix, '/');
        try {
            $register($this);
        } finally {
            $this->prefix = $previous;
        }

        return $this;
    }

    /** @param array<string, string|int> $parameters */
    public function url(string $name, array $parameters = []): string
    {
        $path = $this->named[$name] ?? throw new \InvalidArgumentException('Unknown route name.');
        $segments = explode('/', $path);
        foreach ($segments as &$segment) {
            if (str_starts_with($segment, '{')) {
                $key = substr($segment, 1, -1);
                $value = $parameters[$key] ?? null;
                if ((!is_string($value) && !is_int($value)) || (string) $value === '') {
                    throw new \InvalidArgumentException('Missing or invalid route parameter: '.$key);
                }
                $segment = rawurlencode((string) $value);
                unset($parameters[$key]);
            } else {
                $segment = rawurlencode($segment);
            }
        }
        unset($segment);
        if ($parameters !== []) {
            throw new \InvalidArgumentException('Unexpected route parameters.');
        }
        $url = implode('/', $segments);
        try {
            new Request('GET', $url);
        } catch (HttpException $error) {
            throw new \InvalidArgumentException('Invalid generated route URL.', previous: $error);
        }

        return $url;
    }

    public function dispatch(Request $request): Response
    {
        $allowed = [];
        $fallback = null;
        foreach ([false, true] as $dynamic) {
            foreach ($this->routes as $route) {
                if ($route['dynamic'] !== $dynamic) {
                    continue;
                }
                $matches = [];
                if ($dynamic ? preg_match($route['pattern'], $request->path, $matches) !== 1 : $route['path'] !== $request->path) {
                    continue;
                }
                $parameters = array_filter($matches, is_string(...), ARRAY_FILTER_USE_KEY);
                $allowed[$route['method']] = true;
                if ($route['method'] === 'GET') {
                    $allowed['HEAD'] = true;
                    $fallback ??= [$route['handler'], $parameters];
                }
                if ($route['method'] === $request->method) {
                    return ($route['handler'])($request->withRouteParameters($parameters));
                }
            }
            if ($allowed !== []) {
                break;
            }
        }
        if ($request->method === 'HEAD' && $fallback !== null) {
            return ($fallback[0])($request->withRouteParameters($fallback[1]));
        }
        if ($allowed !== []) {
            throw new HttpException(405, headers: ['Allow' => implode(', ', array_keys($allowed))]);
        }

        throw new HttpException(404);
    }

    /** @return list<array{method: string, path: string}> */
    public function routes(): array
    {
        return array_map(static fn (array $route): array => ['method' => $route['method'], 'path' => $route['path']], $this->routes);
    }
}
