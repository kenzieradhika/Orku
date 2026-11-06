<?php

declare(strict_types=1);

namespace Orku\Web;

use Orku\Http\HttpException;

final readonly class CsrfManager
{
    public function __construct(private Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get('_orku_csrf');
        if (!is_string($token) || !preg_match('/^[0-9a-f]{64}$/D', $token)) {
            $token = bin2hex(random_bytes(32));
            $this->session->set('_orku_csrf', $token);
        }

        return $token;
    }

    public function verify(?string $token): void
    {
        $expected = $this->session->get('_orku_csrf');
        if (!is_string($expected) || $token === null || strlen($token) !== 64 || !hash_equals($expected, $token)) {
            throw new HttpException(403);
        }
    }
}
