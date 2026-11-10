<?php

declare(strict_types=1);

namespace Orku\Auth;

final class PasswordHasher
{
    private static ?string $dummyHash = null;

    public function hash(string $password): string
    {
        return password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function verifyDummy(string $password): bool
    {
        self::$dummyHash ??= $this->hash(bin2hex(random_bytes(32)));

        return $this->verify($password, self::$dummyHash);
    }
}
