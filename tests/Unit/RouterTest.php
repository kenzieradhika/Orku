<?php

declare(strict_types=1);

namespace Orku\Tests\Unit;

use Orku\Http\HttpException;
use Orku\Http\Request;
use Orku\Http\Response;
use Orku\Routing\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testHeadFallbackKeepsFirstOverlappingGet(): void
    {
        $router = new Router();
        $router->get('/{id}/b', fn () => Response::text('first', 202));
        $router->get('/a/{id}', fn () => Response::text('second', 203));
        self::assertSame(202, $router->dispatch(new Request('HEAD', '/a/b'))->status);
        $router->add('HEAD', '/a/{name}', fn () => new Response(status: 204));
        self::assertSame(204, $router->dispatch(new Request('HEAD', '/a/b'))->status);
    }

    public function testExcessiveParameterNameFailsAtRegistration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Router())->get('/{'.str_repeat('x', 33).'}', fn () => Response::text('ok'));
    }

    public function testStaticRoutesTakePrecedenceOverDynamicRoutes(): void
    {
        $router = new Router();
        $router->get('/users/{id}', fn (Request $r) => Response::text($r->routeParameters['id']));
        $router->get('/users/me', fn () => Response::text('profile'));
        self::assertSame('profile', $router->dispatch(new Request('GET', '/users/me'))->body);
        self::assertSame('42', $router->dispatch(new Request('GET', '/users/42'))->body);
    }

    public function testMultipleParametersAreDecodedExactlyOnce(): void
    {
        $router = new Router();
        $router->get('/teams/{team}/users/{user}', fn (Request $r) => Response::json($r->routeParameters));
        self::assertSame('{"team":"R&D","user":"%2F"}', $router->dispatch(new Request('GET', '/teams/R%26D/users/%252F'))->body);
    }

    public static function methods(): iterable
    {
        foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
            yield [$method];
        }
    }

    #[DataProvider('methods')]
    public function testSupportedMethodsDispatch(string $method): void
    {
        $router = new Router();
        $router->$method('/', fn () => Response::text($method));
        self::assertSame($method, $router->dispatch(new Request($method, '/'))->body);
    }

    public function testUnknownRouteIs404(): void
    {
        $this->expectExceptionCode(404);
        (new Router())->dispatch(new Request('GET', '/missing'));
    }

    public function testWrongMethodProvidesAllowHeader(): void
    {
        $router = new Router();
        $router->get('/items', fn () => Response::text('ok'));
        $router->post('/items', fn () => Response::text('ok'));
        try {
            $router->dispatch(new Request('DELETE', '/items'));
            self::fail('Expected 405.');
        } catch (HttpException $error) {
            self::assertSame(405, $error->status);
            self::assertSame('GET, HEAD, POST', $error->headers['allow']);
        }
    }

    public function testHeadFallsBackToGetAndExplicitHeadWins(): void
    {
        $router = new Router();
        $router->get('/', fn () => Response::text('get'));
        self::assertSame('get', $router->dispatch(new Request('HEAD', '/'))->body);
        $router->add('HEAD', '/', fn () => Response::text('head'));
        self::assertSame('head', $router->dispatch(new Request('HEAD', '/'))->body);
    }

    public function testTrailingSlashIsSignificant(): void
    {
        $router = new Router();
        $router->get('/items', fn () => Response::text('ok'));
        $this->expectExceptionCode(404);
        $router->dispatch(new Request('GET', '/items/'));
    }

    public static function invalidRoutes(): iterable
    {
        foreach (['items', '/{id}/{id}', '/{bad-name}', '/user-{id}', '/{id', '/a?b', '/a%20b', '/a/../b'] as $path) {
            yield [$path];
        }
    }

    #[DataProvider('invalidRoutes')]
    public function testMalformedRoutesFailAtRegistration(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Router())->get($path, fn () => Response::text('ok'));
    }

    public function testEquivalentRouteShapesAreRejected(): void
    {
        $router = new Router();
        $router->get('/{id}', fn () => Response::text('one'));
        $this->expectException(\LogicException::class);
        $router->get('/{name}', fn () => Response::text('two'));
    }

    public function testLiteralDotsAreNotRegexWildcards(): void
    {
        $router = new Router();
        $router->get('/v1.0/{id}', fn () => Response::text('ok'));
        $this->expectExceptionCode(404);
        $router->dispatch(new Request('GET', '/v1x0/42'));
    }
}
