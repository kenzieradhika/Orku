<?php

declare(strict_types=1);

namespace Orku\Tests\Unit;

use Orku\Http\HttpException;
use Orku\Http\Request;
use Orku\Http\Response;
use Orku\Web\CsrfManager;
use Orku\Web\Session;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    public function testConstructionDoesNotStartSession(): void
    {
        $status = session_status();
        new CsrfManager(new Session(new Request('GET', '/')));
        self::assertSame($status, session_status());
    }

    #[RunInSeparateProcess]
    public function testSecureCookieAndStableTokenAcrossRequests(): void
    {
        $session = new Session(new Request('GET', '/'), path: '/demo');
        $csrf = new CsrfManager($session);
        $token = $csrf->token();
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $token);
        self::assertSame($token, $csrf->token());
        $csrf->verify($token);
        $response = $session->commit(Response::html('ok'));
        $cookie = $response->header('set-cookie');
        self::assertStringContainsString('Path=/demo; Secure; HttpOnly; SameSite=Lax', $cookie);
        self::assertSame(PHP_SESSION_NONE, session_status());
        preg_match('/^orku_session=([^;]+)/', $cookie, $matches);
        $next = new Session(new Request('POST', '/', cookies: ['orku_session' => $matches[1]]), secure: false);
        $nextCsrf = new CsrfManager($next);
        self::assertSame($token, $nextCsrf->token());
        $nextCsrf->verify($token);
        self::assertStringNotContainsString('; Secure', $next->commit(Response::text('ok'))->header('set-cookie'));
    }

    #[RunInSeparateProcess]
    public function testInvalidTokensFailWith403AndUnknownSessionIdIsRejected(): void
    {
        $id = str_repeat('a', 32);
        $session = new Session(new Request('POST', '/', cookies: ['orku_session' => $id]));
        $csrf = new CsrfManager($session);
        foreach ([null, '', 'bad', str_repeat('0', 64)] as $token) {
            try {
                $csrf->verify($token);
                self::fail('Invalid CSRF must fail.');
            } catch (HttpException $error) {
                self::assertSame(403, $error->status);
                self::assertStringNotContainsString('bad', $error->getMessage());
            }
        }
        self::assertStringNotContainsString('orku_session='.$id.';', $session->commit(Response::text('denied'))->header('set-cookie'));
    }

    public function testStatelessCommitHasNoCookie(): void
    {
        $response = (new Session(new Request('GET', '/')))->commit(Response::text('ok'));
        self::assertNull($response->header('set-cookie'));
    }

    public function testCookiePathRejectsInjection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Session(new Request('GET', '/'), path: '/; Secure');
    }
}
