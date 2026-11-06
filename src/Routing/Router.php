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

    /** @param callable(Request): Response $handler */
    public function add(string $method, string $path, callable $handler): self
    {
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
                $name = $matches[1];
                if (strlen($name) > 32) {
                    throw new \InvalidArgumentException('Route parameter names must be at most 32 bytes.');
                }
                if (isset($names[$name])) {
                    throw new \InvalidArgumentException('Duplicate route parameter: '.$name);
                }
                $names[$name] = true;
                $parts[] = '(?P<'.$name.'>[^/]+)';
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
        $this->routes[] = ['method' => $method, 'path' => $path, 'pattern' => '~^'.implode('/', $parts).'$~Du', 'dynamic' => $names !== [], 'handler' => $handler];

        return $this;
    }

    /** @param callable(Request): Response $handler */
    public function get(string $path, callable $handler): self
    {
        return $this->add('GET', $path, $handler);
    }

    /** @param callable(Request): Response $handler */
    public function post(string $path, callable $handler): self
    {
        return $this->add('POST', $path, $handler);
    }

    /** @param callable(Request): Response $handler */
    public function put(string $path, callable $handler): self
    {
        return $this->add('PUT', $path, $handler);
    }

    /** @param callable(Request): Response $handler */
    public function patch(string $path, callable $handler): self
    {
        return $this->add('PATCH', $path, $handler);
    }

    /** @param callable(Request): Response $handler */
    public function delete(string $path, callable $handler): self
    {
        return $this->add('DELETE', $path, $handler);
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
