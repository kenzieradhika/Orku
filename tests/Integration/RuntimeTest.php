<?php

declare(strict_types=1);

namespace Orku\Tests\Integration;

use Orku\Tests\Support\Server;
use PHPUnit\Framework\TestCase;

final class RuntimeTest extends TestCase
{
    public function testSessionPreservesAllCookieHeaderLines(): void
    {
        $server = new Server('', fixture: true);
        try {
            foreach (['GET', 'HEAD'] as $method) {
                $response = $server->request('/cookies', $method);
                self::assertSame(200, $response['status']);
                $cookies = array_values(array_filter($response['header_lines'], fn (string $line) => str_starts_with(strtolower($line), 'set-cookie:')));
                self::assertCount(4, $cookies);
                self::assertContains('set-cookie: legacy=1; Path=/', $cookies);
                self::assertContains('set-cookie: theme=dark; Path=/', $cookies);
                self::assertContains('set-cookie: language=id; Expires=Wed, 09 Jun 2038 10:18:14 GMT; Path=/', $cookies);
                self::assertCount(1, array_filter($cookies, fn (string $line) => str_contains($line, 'orku_session=')));
                self::assertSame('no-store', $response['headers']['cache-control']);
                self::assertSame($method === 'HEAD' ? '' : 'cookies', $response['body']);
            }
        } finally {
            $server->close();
        }
    }

    public function testLocationHeaderDoesNotOverrideResponseStatus(): void
    {
        $server = new Server('', fixture: true);
        try {
            $response = $server->request('/accepted');
            self::assertSame(202, $response['status']);
            self::assertSame('/jobs/1', $response['headers']['location']);
            self::assertSame('accepted', $response['body']);
        } finally {
            $server->close();
        }
    }

    public function testHelloWorldRunsThroughCli(): void
    {
        $server = new Server('hello-world');
        try {
            $home = $server->request('/');
            self::assertSame(200, $home['status']);
            self::assertSame("Hello, Orku!\n", $home['body']);
            self::assertSame('nosniff', $home['headers']['x-content-type-options']);
            self::assertSame('text/plain; charset=utf-8', $home['headers']['content-type']);
            self::assertSame('{"framework":"Orku","status":"running"}', $server->request('/api/status')['body']);
            self::assertSame("Hello, José!\n", $server->request('/hello/Jos%C3%A9')['body']);
            self::assertSame(404, $server->request('/missing')['status']);
            self::assertSame(404, $server->request('/.env')['status']);
            $head = $server->request('/', 'HEAD');
            self::assertSame(200, $head['status']);
            self::assertSame('', $head['body']);
            self::assertSame('13', $head['headers']['content-length']);
            $wrong = $server->request('/', 'DELETE');
            self::assertSame(405, $wrong['status']);
            self::assertSame('GET, HEAD', $wrong['headers']['allow']);
        } finally {
            $server->close();
        }
    }

    public function testRestApiParsesAndValidatesRealRequests(): void
    {
        $server = new Server('rest-api');
        try {
            $created = $server->request('/api/v1/greetings', 'POST', '{"name":"Ada"}', ['Content-Type: application/json']);
            self::assertSame(201, $created['status']);
            self::assertSame('{"message":"Hello, Ada!"}', $created['body']);
            self::assertSame(422, $server->request('/api/v1/greetings', 'POST', '{"name":[]}', ['Content-Type: application/json'])['status']);
            self::assertSame(422, $server->request('/api/v1/greetings', 'POST', 'null', ['Content-Type: application/json'])['status']);
            self::assertSame(400, $server->request('/api/v1/greetings', 'POST', '{', ['Content-Type: application/json'])['status']);
            self::assertSame(415, $server->request('/api/v1/greetings', 'POST', '{}', ['Content-Type: text/plain'])['status']);
            self::assertSame(413, $server->request('/api/v1/greetings', 'POST', str_repeat('x', 1048577), ['Content-Type: application/json'])['status']);
            self::assertSame('{"id":"42"}', $server->request('/api/v1/users/42')['body']);
        } finally {
            $server->close();
        }
    }

    public function testCaptureAndEmitterHandleBoundaries(): void
    {
        $server = new Server('', fixture: true);
        try {
            $response = $server->request('/request?tag=a%26b', 'POST', '{"ok":true}', ['Content-Type: application/json', 'Cookie: theme=dark', 'X-Custom: value']);
            self::assertSame(['method' => 'POST', 'query' => ['tag' => 'a&b'], 'cookie' => 'dark', 'header' => 'value', 'json' => ['ok' => true]], json_decode($response['body'], true));
            $error = $server->request('/error');
            self::assertSame(500, $error['status']);
            self::assertSame('{"error":{"status":500,"message":"Internal Server Error"}}', $error['body']);
            self::assertSame('no-store', $error['headers']['cache-control']);
            self::assertSame(400, $server->request('/hello/a%2Fb')['status']);
            self::assertSame(400, $server->request('/hello/%zz')['status']);
            self::assertSame(302, $server->request('/redirect')['status']);
            self::assertSame('/target', $server->request('/redirect')['headers']['location']);
            $empty = $server->request('/empty');
            self::assertSame(204, $empty['status']);
            self::assertSame('', $empty['body']);
            self::assertArrayNotHasKey('content-length', $empty['headers']);
            self::assertSame('text/html; charset=utf-8', $server->request('/html')['headers']['content-type']);
            self::assertStringContainsString("default-src 'none'", $server->request('/html')['headers']['content-security-policy']);
        } finally {
            $server->close();
        }
    }
}
