<?php

declare(strict_types=1);

namespace Orku\Auth;

interface CredentialProvider
{
    public function byIdentifier(string $identifier): ?Credential;

    public function byId(string $id): ?Identity;
}
