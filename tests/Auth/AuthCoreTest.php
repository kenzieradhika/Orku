<?php

declare(strict_types=1);

namespace Orku\Tests\Auth;

use Orku\Auth\Clock;
use Orku\Auth\Credential;
use Orku\Auth\CredentialProvider;
use Orku\Auth\Guard;
use Orku\Auth\Identity;
use Orku\Auth\LoginService;
use Orku\Auth\PasswordHasher;
use Orku\Auth\ThrottleStore;
use Orku\Core\Kernel;
use Orku\Http\Request;
use Orku\Http\Response;
use Orku\Routing\Router;
use Orku\Web\CsrfManager;
use Orku\Web\Session;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class AuthCoreTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testLoginRotatesSessionAndGuardExpiresByIdleAndAbsoluteLifetime(): void
    {
        $now = 1000;
        $clock = new class ($now) implements Clock {
            public function __construct(public int $now)
            {
            }

            public function now(): int
            {
                return $this->now;
            }
        };
        $provider = new TestProvider();
        $session = new Session(new Request('POST', '/login'));
        $csrf = new CsrfManager($session);
        $token = $csrf->token();
        $oldId = session_id();
        $service = new LoginService($provider, new PasswordHasher(), new TestThrottle(), $clock);
        self::assertTrue($service->login('ada', 'secret', $token, $csrf, $session, 'local'));
        $newId = session_id();
        self::assertNotSame($oldId, $newId);
        self::assertSame('user-1', (new Guard($session, $provider, $clock))->identity()?->id);
        $session->commit(Response::text('ok'));

        $clock->now += 1799;
        $idle = new Session(new Request('GET', '/', cookies: ['orku_session' => $newId]));
        self::assertSame('user-1', (new Guard($idle, $provider, $clock))->identity()?->id);
        $idle->commit(Response::text('ok'));

        $clock->now += 1800;
        $expiredIdle = new Session(new Request('GET', '/', cookies: ['orku_session' => $newId]));
        self::assertNull((new Guard($expiredIdle, $provider, $clock))->identity());
        $expiredIdle->commit(Response::text('ok'));
    }

    #[RunInSeparateProcess]
    public function testLogoutInvalidatesSessionAndAuthentication(): void
    {
        $clock = new TestClock(1000);
        $provider = new TestProvider();
        $session = new Session(new Request('POST', '/login'));
        $csrf = new CsrfManager($session);
        $service = new LoginService($provider, new PasswordHasher(), new TestThrottle(), $clock);
        self::assertTrue($service->login('ada', 'secret', $csrf->token(), $csrf, $session, 'local'));
        $id = session_id();
        $csrf->verify($csrf->token());
        (new Guard($session, $provider, $clock))->logout($csrf->token(), $csrf);
        self::assertNull((new Guard($session, $provider, $clock))->identity());
        $response = $session->commit(Response::text('ok'));
        self::assertStringContainsString('Max-Age=0', implode(';', $response->cookies));
        self::assertStringEndsWith('Max-Age=0', $response->cookies[array_key_last($response->cookies)]);
        $stale = new Session(new Request('GET', '/', cookies: ['orku_session' => $id]));
        self::assertNull((new Guard($stale, $provider, $clock))->identity());
        $stale->commit(Response::text('ok'));
    }

    #[RunInSeparateProcess]
    public function testLoginFailuresAreGenericAndThrottleConsumesAttempts(): void
    {
        $provider = new TestProvider();
        $throttle = new TestThrottle();
        $service = new LoginService($provider, new PasswordHasher(), $throttle, new TestClock(1000));
        foreach ([['ada', 'wrong'], ['missing', 'wrong']] as [$name, $password]) {
            $session = new Session(new Request('POST', '/login'));
            $csrf = new CsrfManager($session);
            self::assertFalse($service->login($name, $password, $csrf->token(), $csrf, $session, 'local'));
            $session->commit(Response::text('ok'));
        }
        self::assertSame(4, $throttle->attempts);
        $throttle->blocked = true;
        $session = new Session(new Request('POST', '/login'));
        $csrf = new CsrfManager($session);
        self::assertFalse($service->login('ada', 'secret', $csrf->token(), $csrf, $session, 'local'));
        $session->commit(Response::text('ok'));
    }

    #[RunInSeparateProcess]
    public function testThrottleUsesIndependentAccountAndSourceBuckets(): void
    {
        $provider = new TestProvider();
        $throttle = new TestThrottle();
        $service = new LoginService($provider, new PasswordHasher(), $throttle, new TestClock(1000));
        foreach ([['ada', 'source-a'], ['bob', 'source-a'], ['ada', 'source-b']] as [$identifier, $source]) {
            $session = new Session(new Request('POST', '/login'));
            $csrf = new CsrfManager($session);
            self::assertFalse($service->login($identifier, 'bad', $csrf->token(), $csrf, $session, $source));
            $session->commit(Response::text('ok'));
        }
        self::assertSame(6, $throttle->attempts);
        self::assertSame(['account:', 'source:', 'account:', 'source:', 'account:', 'source:'], array_map(static fn (string $key): string => substr($key, 0, strpos($key, ':') + 1), $throttle->keys));
        self::assertSame($throttle->keys[1], $throttle->keys[3]);
        self::assertSame($throttle->keys[0], $throttle->keys[4]);
    }

    #[RunInSeparateProcess]
    public function testThrottleStoreFailureDoesNotAuthenticate(): void
    {
        $provider = new TestProvider();
        $throttle = new TestThrottle();
        $throttle->fail = true;
        $service = new LoginService($provider, new PasswordHasher(), $throttle, new TestClock(1000));
        $session = new Session(new Request('POST', '/login'));
        $csrf = new CsrfManager($session);
        try {
            $service->login('ada', 'secret', $csrf->token(), $csrf, $session, 'source');
            self::fail('Throttle backend failure must propagate.');
        } catch (\RuntimeException $error) {
            self::assertSame('throttle unavailable', $error->getMessage());
            self::assertNull((new Guard($session, $provider, new TestClock(1000)))->identity());
        } finally {
            $session->commit(Response::text('ok'));
        }
    }

    #[RunInSeparateProcess]
    public function testUnknownAccountVerificationCreatesDummyHashOnlyOnce(): void
    {
        $hasher = new PasswordHasher();
        self::assertFalse($hasher->verifyDummy('first'));
        self::assertFalse($hasher->verifyDummy('second'));
    }

    #[RunInSeparateProcess]
    public function testLoginAfterLogoutReissuesRotatedSessionCookie(): void
    {
        $clock = new TestClock(1000);
        $provider = new TestProvider();
        $session = new Session(new Request('POST', '/session'));
        $csrf = new CsrfManager($session);
        $service = new LoginService($provider, new PasswordHasher(), new TestThrottle(), $clock);
        self::assertTrue($service->login('ada', 'secret', $csrf->token(), $csrf, $session, 'source'));
        $oldId = session_id();
        (new Guard($session, $provider, $clock))->logout($csrf->token(), $csrf);
        self::assertTrue($service->login('ada', 'secret', $csrf->token(), $csrf, $session, 'source'));
        $newId = session_id();
        self::assertNotSame($oldId, $newId);
        $response = $session->commit(Response::text('ok'));
        self::assertCount(0, $response->cookies);
        self::assertStringContainsString('orku_session='.$newId.';', $response->header('set-cookie'));
        $fresh = new Session(new Request('GET', '/', cookies: ['orku_session' => $newId]));
        self::assertSame('user-1', (new Guard($fresh, $provider, $clock))->identity()?->id);
        $fresh->commit(Response::text('ok'));
    }

    #[RunInSeparateProcess]
    public function testMalformedSessionIdStartsUnauthenticatedWithFreshId(): void
    {
        $session = new Session(new Request('GET', '/', cookies: ['orku_session' => 'not a valid session id']));
        self::assertNull((new Guard($session, new TestProvider(), new TestClock(1000)))->identity());
        self::assertMatchesRegularExpression('/^[a-zA-Z0-9,-]{16,128}$/D', session_id());
        $session->commit(Response::text('ok'));
    }

    #[RunInSeparateProcess]
    public function testInvalidCsrfCannotAuthenticateOrLogout(): void
    {
        $provider = new TestProvider();
        $session = new Session(new Request('POST', '/login'));
        $csrf = new CsrfManager($session);
        $service = new LoginService($provider, new PasswordHasher(), new TestThrottle(), new TestClock(1000));
        $this->expectException(\Orku\Http\HttpException::class);
        try {
            $service->login('ada', 'secret', 'invalid', $csrf, $session, 'local');
        } finally {
            $session->commit(Response::text('ok'));
        }
    }

    #[RunInSeparateProcess]
    public function testInvalidCsrfCannotLogout(): void
    {
        $clock = new TestClock(1000);
        $provider = new TestProvider();
        $session = new Session(new Request('POST', '/login'));
        $csrf = new CsrfManager($session);
        $service = new LoginService($provider, new PasswordHasher(), new TestThrottle(), $clock);
        self::assertTrue($service->login('ada', 'secret', $csrf->token(), $csrf, $session, 'local'));
        $this->expectException(\Orku\Http\HttpException::class);
        try {
            (new Guard($session, $provider, $clock))->logout('invalid', $csrf);
        } finally {
            self::assertSame('user-1', (new Guard($session, $provider, $clock))->identity()?->id);
            $session->commit(Response::text('ok'));
        }
    }

    #[RunInSeparateProcess]
    public function testAbsoluteExpirationDoesNotRefreshOnActivity(): void
    {
        ini_set('session.gc_maxlifetime', '86400');
        ini_set('session.save_path', '/tmp');
        $clock = new TestClock(1000);
        $provider = new TestProvider();
        $session = new Session(new Request('GET', '/'));
        $session->set('_orku_auth_id', 'user-1');
        $session->set('_orku_auth_created', 1000);
        $session->set('_orku_auth_last', 1000);
        $sessionId = session_id();
        $session->commit(Response::text('ok'));
        for ($elapsed = 1700; $elapsed < 43200; $elapsed += 1700) {
            $clock->time = 1000 + $elapsed;
            $active = new Session(new Request('GET', '/', cookies: ['orku_session' => $sessionId]));
            self::assertSame('user-1', (new Guard($active, $provider, $clock))->identity()?->id);
            $active->commit(Response::text('ok'));
        }
        $clock->time = 1000 + 43200;
        $expired = new Session(new Request('GET', '/', cookies: ['orku_session' => $sessionId]));
        self::assertNull((new Guard($expired, $provider, $clock))->identity());
        $expired->commit(Response::text('ok'));
    }

    public function testAuthenticationMiddlewareDeniesByDefault(): void
    {
        $provider = new TestProvider();
        $clock = new TestClock(1000);
        $session = new Session(new Request('GET', '/'));
        $guard = new Guard($session, $provider, $clock);
        $router = new Router();
        $router->get('/', static fn (): Response => Response::text('private'));
        $kernel = new Kernel($router, middleware: [$guard->middleware()]);
        self::assertSame(401, $kernel->handle(new Request('GET', '/'))->status);
    }
}

final class TestClock implements Clock
{
    public function __construct(public int $time)
    {
    }

    public function now(): int
    {
        return $this->time;
    }
}

final class TestProvider implements CredentialProvider
{
    private readonly string $hash;

    public function __construct()
    {
        $this->hash = password_hash('secret', PASSWORD_DEFAULT);
    }

    public function byIdentifier(string $identifier): ?Credential
    {
        return $identifier === 'ada' ? new Credential(new Identity('user-1', ['name' => 'Ada']), $this->hash) : null;
    }

    public function byId(string $id): ?Identity
    {
        return $id === 'user-1' ? new Identity('user-1', ['name' => 'Ada']) : null;
    }
}

final class TestThrottle implements ThrottleStore
{
    public int $attempts = 0;
    public bool $blocked = false;
    public bool $fail = false;
    /** @var list<string> */
    public array $keys = [];

    public function consume(string $key, int $now, int $windowSeconds, int $limit): bool
    {
        ++$this->attempts;
        $this->keys[] = $key;
        if ($this->fail) {
            throw new \RuntimeException('throttle unavailable');
        }

        return !$this->blocked;
    }
}
