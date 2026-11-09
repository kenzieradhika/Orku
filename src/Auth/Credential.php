<?php

declare(strict_types=1);

namespace Orku\Auth;

final readonly class Credential
{
    public function __construct(public Identity $identity, public string $passwordHash)
    {
    }
}
