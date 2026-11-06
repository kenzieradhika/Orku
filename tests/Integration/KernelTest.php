<?php

declare(strict_types=1);

namespace Orku\Tests\Integration;

use Orku\Core\Kernel;
use Orku\Http\HttpException;
use Orku\Http\Request;
use Orku\Http\Response;
use Orku\Routing\Router;
use PHPUnit\Framework\TestCase;

final class KernelTest extends TestCase
{
    public function testOptionalErrorRendererPreservesStatusAndSafeFallback(): void
    {
        $kernel = new Kernel(new Router(), renderError: static fn (\Throwable $error, Response $safe): Response => new Response('<h1>Page not found</h1>', $safe->status, ['content-type' => 'text/html; charset=utf-8'] + $safe->headers));
        self::assertSame(404, $kernel->handle(new Request('GET', '/missing'))->status);
        self::assertSame('<h1>Page not found</h1>', $kernel->handle(new Request('GET', '/missing'))->body);
        $broken = new Kernel(new Router(), renderError: static fn () => throw new \RuntimeException('private'));
        self::assertSame(404, $broken->handle(new Request('GET', '/missing'))->status);
        self::assertStringNotContainsString('private', $broken->handle(new Request('GET', '/missing'))->body);
    }

    public function testErrorIgnoresExceptionFramingHeaders(): void
    {
        $router = new Router();
        $router->get('/', fn () => throw new HttpException(429, headers: ['Content-Length' => '0', 'Transfer-Encoding' => 'chunked', 'Retry-After' => '30']));
        $response = (new Kernel($router))->handle(new Request('GET', '/'));
        self::assertSame(429, $response->status);
        self::assertSame('30', $response->header('retry-after'));
        self::assertNull($response->header('content-length'));
        self::assertNull($response->header('transfer-encoding'));
    }

    public function testDebugHandlesInvalidUtf8ExceptionText(): void
    {
        $kernel = new Kernel(new Router(), debug: true, report: static function (): void {
        });
        $response = $kernel->error(new \RuntimeException("bad\xff"));
        self::assertSame(500, $response->status);
        self::assertSame("bad\u{FFFD}", json_decode($response->body, true, flags: JSON_THROW_ON_ERROR)['error']['debug']['message']);
    }

    public function testKernelDispatchesAndKeepsRequestsIsolated(): void
    {
        $router = new Router();
        $router->get('/users/{id}', fn (Request $r) => Response::json($r->routeParameters));
        $router->get('/status', fn (Request $r) => Response::json($r->routeParameters));
        $kernel = new Kernel($router);
        self::assertSame('{"id":"alice"}', $kernel->handle(new Request('GET', '/users/alice'))->body);
        self::assertSame('[]', $kernel->handle(new Request('GET', '/status'))->body);
        self::assertSame('{"id":"bob"}', $kernel->handle(new Request('GET', '/users/bob'))->body);
    }

    public function testProductionErrorDoesNotDiscloseExceptionDetails(): void
    {
        $router = new Router();
        $router->get('/', fn () => throw new \RuntimeException('password=secret'));
        $reported = [];
        $kernel = new Kernel($router, report: function (\Throwable $error) use (&$reported): void {
            $reported[] = $error;
        });
        $response = $kernel->handle(new Request('GET', '/'));
        self::assertSame(500, $response->status);
        self::assertSame(['error' => ['status' => 500, 'message' => 'Internal Server Error']], json_decode($response->body, true));
        self::assertSame('password=secret', $reported[0]->getMessage());
    }

    public function testDebugModeAddsDiagnostics(): void
    {
        $response = (new Kernel(new Router(), debug: true))->error(new \RuntimeException('broken'));
        $body = json_decode($response->body, true);
        self::assertSame('broken', $body['error']['debug']['message']);
        self::assertSame(\RuntimeException::class, $body['error']['debug']['type']);
        self::assertArrayHasKey('trace', $body['error']['debug']);
    }

    public function testHttpErrorsUseSafeStandardMessages(): void
    {
        $kernel = new Kernel(new Router());
        $response = $kernel->error(new HttpException(405, 'private data', ['Allow' => 'GET, HEAD']));
        self::assertSame('GET, HEAD', $response->header('allow'));
        self::assertSame('{"error":{"status":405,"message":"Method Not Allowed"}}', $response->body);
        self::assertSame(404, $kernel->handle(new Request('GET', '/missing'))->status);
    }

    public function testBrokenReporterDoesNotBreakSafeResponse(): void
    {
        $kernel = new Kernel(new Router(), report: fn () => throw new \RuntimeException('logger broken'));
        self::assertSame(500, $kernel->error(new \RuntimeException('secret'))->status);
    }

    public function testUnexpectedHandlerReturnBecomes500(): void
    {
        $router = new Router();
        $router->get('/', fn () => 'not a Response');
        self::assertSame(500, (new Kernel($router, report: static function (): void {
        }))->handle(new Request('GET', '/'))->status);
    }
}
