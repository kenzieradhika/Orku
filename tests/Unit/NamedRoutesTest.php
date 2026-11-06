<?php

declare(strict_types=1);

namespace Orku\Tests\Unit;

use Orku\Http\Request;
use Orku\Http\Response;
use Orku\Routing\Router;
use PHPUnit\Framework\TestCase;

final class NamedRoutesTest extends TestCase
{
    public function testNestedGroupsAndUrlRoundTrip(): void
    {
        $router = new Router();
        $router->group('/api', function (Router $routes): void {
            $routes->group('/v1', function (Router $routes): void {
                $routes->get('/users/{id}', fn (Request $r) => Response::text($r->routeParameters['id']), name: 'users.show');
            });
        });
        self::assertSame('/api/v1/users/Ada%20%26%20Bob', $router->url('users.show', ['id' => 'Ada & Bob']));
        self::assertSame('Ada & Bob', $router->dispatch(new Request('GET', $router->url('users.show', ['id' => 'Ada & Bob'])))->body);
        self::assertSame('/api/v1/users/42', $router->url('users.show', ['id' => 42]));
    }

    public function testGroupPrefixRestoresAfterException(): void
    {
        $router = new Router();
        try {
            $router->group('/api', fn () => throw new \RuntimeException('stop'));
        } catch (\RuntimeException) {
            $router->get('/outside', fn () => Response::text('ok'), name: 'outside');
        }
        self::assertSame('/outside', $router->url('outside'));
    }

    public function testInvalidUrlParametersAreRejected(): void
    {
        $router = new Router();
        $router->get('/{id}', fn () => Response::text('ok'), name: 'show');
        foreach ([[], ['id' => ''], ['id' => '/'], ['id' => '..'], ['id' => "\r\n"], ['id' => '\\'], ['id' => "\xff"], ['id' => 'ok', 'extra' => 1]] as $parameters) {
            try {
                $router->url('show', $parameters);
                self::fail('Expected parameter rejection');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testDuplicateNameDoesNotRegisterRoute(): void
    {
        $router = new Router();
        $router->get('/first', fn () => Response::text('ok'), name: 'same');
        try {
            $router->post('/second', fn () => Response::text('ok'), name: 'same');
            self::fail('Expected duplicate name rejection');
        } catch (\LogicException) {
            self::assertCount(1, $router->routes());
        }
        $router->post('/second', fn () => Response::text('ok'), name: 'second');
        self::assertSame('/second', $router->url('second'));
    }

    public function testUnknownNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Router())->url('missing');
    }
}
