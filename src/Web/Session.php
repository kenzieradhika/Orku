<?php

declare(strict_types=1);

namespace Orku\Web;

use Orku\Http\Request;
use Orku\Http\Response;

final class Session
{
    private bool $started = false;

    public function __construct(private readonly Request $request, private readonly bool $secure = true, private readonly string $path = '/')
    {
        if (!preg_match('~^/[a-zA-Z0-9_/-]*$~D', $path)) {
            throw new \InvalidArgumentException('Invalid session cookie path.');
        }
    }

    public function get(string $key): mixed
    {
        $this->start();

        return $_SESSION[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->start();
        $_SESSION[$key] = $value;
    }

    public function commit(Response $response): Response
    {
        if (!$this->started) {
            return $response;
        }
        $id = session_id();
        if (!session_write_close()) {
            throw new \RuntimeException('Cannot save session.');
        }
        $this->started = false;
        $_SESSION = [];
        $cookie = 'orku_session='.$id.'; Path='.$this->path.($this->secure ? '; Secure' : '').'; HttpOnly; SameSite=Lax';

        $cookies = $response->cookies;
        if (($existing = $response->header('set-cookie')) !== null) {
            $cookies[] = $existing;
        }

        return new Response($response->body, $response->status, ['set-cookie' => $cookie, 'cache-control' => 'no-store'] + $response->headers, $cookies);
    }

    private function start(): void
    {
        if ($this->started) {
            return;
        }
        if (!extension_loaded('session') || session_status() !== PHP_SESSION_NONE || headers_sent()) {
            throw new \RuntimeException('Session cannot start in current runtime.');
        }
        $id = $this->request->cookies['orku_session'] ?? '';
        session_id(preg_match('/^[a-zA-Z0-9,-]{16,128}$/D', $id) ? $id : '');
        if (!session_start(['name' => 'orku_session', 'use_strict_mode' => 1, 'use_cookies' => 0, 'use_only_cookies' => 1, 'use_trans_sid' => 0, 'cache_limiter' => '', 'cookie_secure' => $this->secure, 'cookie_httponly' => true, 'cookie_samesite' => 'Lax', 'cookie_path' => $this->path])) {
            throw new \RuntimeException('Cannot start session.');
        }
        $this->started = true;
    }

    public function __destruct()
    {
        if ($this->started) {
            session_write_close();
            $_SESSION = [];
        }
    }
}
