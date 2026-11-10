<?php

declare(strict_types=1);

namespace Orku\Web;

use Orku\Http\Request;
use Orku\Http\Response;

final class Session
{
    private bool $started = false;
    private bool $invalidated = false;

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

    public function remove(string $key): void
    {
        $this->start();
        unset($_SESSION[$key]);
    }

    public function invalidate(): void
    {
        $this->start();
        $_SESSION = [];
        if (!session_regenerate_id(true)) {
            throw new \RuntimeException('Cannot invalidate session.');
        }
        $this->invalidated = true;
    }

    public function regenerate(): void
    {
        $this->start();
        if (!session_regenerate_id(true)) {
            throw new \RuntimeException('Cannot rotate session ID.');
        }
        $this->invalidated = false;
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
        if ($this->invalidated) {
            $cookies[] = 'orku_session=; Path='.$this->path.($this->secure ? '; Secure' : '').'; HttpOnly; SameSite=Lax; Max-Age=0';
        }

        $headers = ['cache-control' => 'no-store'] + $response->headers;
        if (!$this->invalidated) {
            $headers['set-cookie'] = $cookie;
        }

        return new Response($response->body, $response->status, $headers, $cookies);
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
