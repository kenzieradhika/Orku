<?php

declare(strict_types=1);

namespace Orku\Tests\Security;

use Orku\Http\HttpException;
use Orku\Http\Request;
use Orku\Http\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HttpSecurityTest extends TestCase
{
    public static function invalidPaths(): iterable
    {
        foreach (['', 'https://example.com/', '//host/path', '/a\\b', '/a%2fb', '/a%5Cb', '/bad%xx', '/bad%', '/a%00b', '/a/../b', '/a/%2e/b', "/a\nb", '/bad%ff', '/path?query=1', '/path#fragment'] as $path) {
            yield [$path];
        }
    }

    #[DataProvider('invalidPaths')]
    public function testUnsafePathsAreRejected(string $path): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(400);
        new Request('GET', $path);
    }

    public static function invalidHeaders(): iterable
    {
        yield ["X-Test\r\nInjected", 'value'];
        yield ['X-Test', "value\r\nInjected: yes"];
        yield ['X-Test', "bad\0value"];
        yield ['bad name', 'value'];
    }

    #[DataProvider('invalidHeaders')]
    public function testResponseHeaderInjectionIsRejected(string $name, string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Response(headers: [$name => $value]);
    }

    public function testAmbiguousHeadersAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Response(headers: ['X-Test' => 'one', 'x-test' => 'two']);
    }

    public function testInvalidStatusIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Response(status: 600);
    }

    public function testBodyForbiddenForNoContent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Response('unexpected', 204);
    }

    public function testResponseFramingCannotBeForged(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Response('hello', headers: ['Content-Length' => '1']);
    }

    public function testOversizedBodyIsRejected(): void
    {
        $this->expectExceptionCode(413);
        new Request('POST', '/', body: str_repeat('x', 1048577));
    }

    public function testInvalidMethodIsRejected(): void
    {
        $this->expectExceptionCode(400);
        new Request("GET\r\nX", '/');
    }

    public function testRedirectRejectsHeaderInjection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Response::redirect("/safe\r\nX-Evil: true");
    }
}
