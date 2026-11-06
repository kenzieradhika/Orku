<?php

declare(strict_types=1);

namespace Orku\Tests\Integration;

use Orku\Core\Kernel;
use Orku\Http\HttpException;
use Orku\Http\Request;
use Orku\Http\Response;
use Orku\Routing\Router;
use PHPUnit\Framework\TestCase;

final class MiddlewareTest extends TestCase
{
    public function testOrderAndRequestIsolation(): void
    {
        $events = [];
        $router = new Router();
        $router->get('/{name}', function (Request $request) use (&$events): Response {
            $events[] = $request->path;

            return Response::text($request->routeParameters['name']);
        });
        $middleware = [];
        foreach (['first', 'second'] as $id) {
            $middleware[] = function (Request $request, callable $next) use (&$events, $id): Response {
                $events[] = $id.' in';
                $response = $next($request);
                $events[] = $id.' out';

                return Response::text($response->body.' '.$id);
            };
        }
        $kernel = new Kernel($router, middleware: $middleware);
        foreach (['Ada', 'Bob', 'Ada'] as $name) {
            $events = [];
            self::assertSame($name.' second first', $kernel->handle(new Request('GET', '/'.$name))->body);
            self::assertSame(['first in', 'second in', '/'.$name, 'second out', 'first out'], $events);
        }
    }

    public function testShortCircuitSkipsRouterAndLaterMiddleware(): void
    {
        $kernel = new Kernel(new Router(), middleware: [
            fn (Request $request, callable $next) => Response::text('blocked', 403),
            fn () => throw new \RuntimeException('Must not execute'),
        ]);
        self::assertSame(403, $kernel->handle(new Request('GET', '/'))->status);
    }

    public function testMiddlewareExceptionsUseKernelErrorHandling(): void
    {
        $kernel = new Kernel(new Router(), middleware: [
            fn () => throw new HttpException(429),
        ]);
        self::assertSame(429, $kernel->handle(new Request('GET', '/'))->status);
    }

    public function testInvalidMiddlewareFailsAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        /** @var list<callable(Request, callable(Request): Response): Response> $invalid */
        $invalid = [42];
        new Kernel(new Router(), middleware: $invalid);
    }
}
