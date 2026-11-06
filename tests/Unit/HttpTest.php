<?php

declare(strict_types=1);

namespace Orku\Tests\Unit;

use Orku\Http\HttpException;
use Orku\Http\Request;
use Orku\Http\Response;
use PHPUnit\Framework\TestCase;

final class HttpTest extends TestCase
{
    public function testRequestNormalizesMethodHeadersAndEncodedPath(): void
    {
        $request = new Request('post', '/users/Jos%C3%A9+Doe', ['Content-Type' => 'application/json; charset=utf-8'], ['page' => '2'], '{"ok":true}', ['theme' => 'dark']);
        self::assertSame('POST', $request->method);
        self::assertSame('/users/José+Doe', $request->path);
        self::assertSame('application/json; charset=utf-8', $request->header('CONTENT-TYPE'));
        self::assertSame(['ok' => true], $request->json());
        self::assertSame(['page' => '2'], $request->query);
        self::assertSame(['theme' => 'dark'], $request->cookies);
        self::assertNull($request->header('missing'));
    }

    public function testRouteParametersAreCopiedNotMutated(): void
    {
        $original = new Request('GET', '/users/42');
        $routed = $original->withRouteParameters(['id' => '42']);
        self::assertSame([], $original->routeParameters);
        self::assertSame(['id' => '42'], $routed->routeParameters);
    }

    public function testJsonNullIsValid(): void
    {
        self::assertNull((new Request('POST', '/', ['content-type' => 'application/problem+json'], body: 'null'))->json());
    }

    public function testMalformedJsonIsBadRequest(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(400);
        (new Request('POST', '/', ['content-type' => 'application/json'], body: '{'))->json();
    }

    public function testJsonRequiresAppropriateMediaType(): void
    {
        $this->expectExceptionCode(415);
        (new Request('POST', '/', body: '{}'))->json();
    }

    public function testResponseFactoriesSetTypesStatusAndLocation(): void
    {
        $json = Response::json(['name' => 'Orku'], 201);
        self::assertSame('{"name":"Orku"}', $json->body);
        self::assertSame(201, $json->status);
        self::assertSame('application/json; charset=utf-8', $json->header('Content-Type'));
        self::assertSame('text/plain; charset=utf-8', Response::text('hello')->header('content-type'));
        self::assertSame('text/html; charset=utf-8', Response::html('<h1>Orku</h1>')->header('content-type'));
        self::assertSame('/login', Response::redirect('/login')->header('location'));
        self::assertSame(302, Response::redirect('/login')->status);
    }

    public function testJsonEncodingFailuresAreNotSilentlyIgnored(): void
    {
        $this->expectException(\JsonException::class);
        Response::json(INF);
    }
}
