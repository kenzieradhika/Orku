<?php

declare(strict_types=1);

namespace Orku\Auth;

final class PasswordHasher
{
    public function hash(string $password): string
    {
        return password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }
}
