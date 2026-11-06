<?php

declare(strict_types=1);

namespace Orku\Tests\Integration;

use Orku\Tests\Support\Server;
use Orku\Core\Kernel;
use Orku\Http\Request;
use Orku\Routing\Router;
use PHPUnit\Framework\TestCase;

final class WebTest extends TestCase
{
    private Server $server;

    protected function setUp(): void
    {
        $this->server = new Server('fullstack-app');
    }

    protected function tearDown(): void
    {
        $this->server->close();
    }

    public function testPagesAssetsAndStatelessApi(): void
    {
        foreach (['/', '/about', '/components', '/contact/success'] as $path) {
            $response = $this->server->request($path);
            self::assertSame(200, $response['status'], $path);
            self::assertStringContainsString('Orku Web Demo', $response['body']);
            self::assertSame('text/html; charset=utf-8', $response['headers']['content-type']);
            self::assertArrayNotHasKey('set-cookie', $response['headers']);
        }
        $api = $this->server->request('/api/status');
        self::assertSame(['framework' => 'Orku', 'status' => 'running'], json_decode($api['body'], true));
        self::assertArrayNotHasKey('set-cookie', $api['headers']);
        self::assertStringNotContainsString('style-src', $api['headers']['content-security-policy']);
        $css = $this->server->request('/assets/css/app.css');
        self::assertSame(200, $css['status']);
        self::assertSame('text/css; charset=utf-8', $css['headers']['content-type']);
        self::assertSame(200, $this->server->request('/assets/images/example.svg')['status']);
        self::assertSame(404, $this->server->request('/assets/private.php')['status']);
        self::assertSame(404, $this->server->request('/composer.json')['status']);
        $head = $this->server->request('/', 'HEAD');
        self::assertSame('', $head['body']);
        self::assertSame(200, $head['status']);
        self::assertStringContainsString("style-src 'self'", $head['headers']['content-security-policy']);
        self::assertStringNotContainsString('unsafe-inline', $head['headers']['content-security-policy']);
    }

    public function testFormCsrfValidationEscapingAndRedirect(): void
    {
        $get = $this->server->request('/contact');
        self::assertSame(200, $get['status']);
        self::assertStringContainsString('HttpOnly; SameSite=Lax', $get['headers']['set-cookie']);
        self::assertStringNotContainsString('; Secure', $get['headers']['set-cookie']);
        self::assertSame('no-store', $get['headers']['cache-control']);
        preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $get['body'], $matches);
        self::assertArrayHasKey(1, $matches);
        $headers = ['Content-Type: application/x-www-form-urlencoded', 'Cookie: '.explode(';', $get['headers']['set-cookie'])[0]];
        self::assertSame(403, $this->server->request('/contact', 'POST', 'name=Ada', $headers)['status']);
        self::assertSame(403, $this->server->request('/contact', 'POST', '_csrf='.str_repeat('0', 64), $headers)['status']);
        $invalid = $this->server->request('/contact', 'POST', http_build_query(['_csrf' => $matches[1], 'name' => '<script>alert(1)</script>', 'email' => 'bad', 'message' => '']), $headers);
        self::assertSame(422, $invalid['status']);
        self::assertStringContainsString('&lt;script&gt;', $invalid['body']);
        self::assertStringNotContainsString('<script>alert', $invalid['body']);
        self::assertStringContainsString('Enter a valid email address.', $invalid['body']);
        $success = $this->server->request('/contact', 'POST', http_build_query(['_csrf' => $matches[1], 'name' => 'Ada', 'email' => 'ada@example.com', 'message' => 'A useful demonstration.']), $headers);
        self::assertSame(303, $success['status']);
        self::assertSame('/contact/success', $success['headers']['location']);
        self::assertStringContainsString('not stored or sent', $this->server->request('/contact/success')['body']);
        self::assertSame(415, $this->server->request('/contact', 'POST', '{}', ['Content-Type: application/json'])['status']);
        self::assertSame(413, $this->server->request('/contact', 'POST', str_repeat('x', 65537), $headers)['status']);
    }

    public function testSafeHtml404AndMethodErrors(): void
    {
        $missing = $this->server->request('/missing');
        self::assertSame(404, $missing['status']);
        self::assertStringContainsString('Page not found', $missing['body']);
        self::assertStringNotContainsString(dirname(__DIR__, 2), $missing['body']);
        $method = $this->server->request('/about', 'POST');
        self::assertSame(405, $method['status']);
        self::assertStringContainsString('GET', $method['headers']['allow']);
    }

    public function testSubdirectoryDeploymentRoutesAndAssetUrls(): void
    {
        $old = getenv('ORKU_BASE_PATH');
        putenv('ORKU_BASE_PATH=/demo');
        try {
            $router = require dirname(__DIR__, 2).'/examples/fullstack-app/app.php';
            self::assertInstanceOf(Router::class, $router);
            $kernel = new Kernel($router);
            $home = $kernel->handle(new Request('GET', '/demo/'));
            self::assertSame(200, $home->status);
            self::assertStringContainsString('href="/demo/assets/css/app.css"', $home->body);
            self::assertStringContainsString('href="/demo/contact"', $home->body);
            self::assertSame(200, $kernel->handle(new Request('GET', '/demo/assets/css/app.css'))->status);
            self::assertSame(404, $kernel->handle(new Request('GET', '/'))->status);
        } finally {
            putenv($old === false ? 'ORKU_BASE_PATH' : 'ORKU_BASE_PATH='.$old);
        }
    }
}
