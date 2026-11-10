<?php

declare(strict_types=1);

namespace Orku\Auth;

use Orku\Web\CsrfManager;
use Orku\Web\Session;

final readonly class LoginService
{
    public function __construct(private CredentialProvider $provider, private PasswordHasher $hasher, private ThrottleStore $throttle, private Clock $clock, private int $attemptLimit = 5, private int $windowSeconds = 900)
    {
        if ($attemptLimit < 1 || $windowSeconds < 1) {
            throw new \InvalidArgumentException('Throttle limits must be positive.');
        }
    }

    public function login(string $identifier, string $password, ?string $csrfToken, CsrfManager $csrf, Session $session, string $sourceContext): bool
    {
        $csrf->verify($csrfToken);
        $accountKey = 'account:'.hash('sha256', strtolower(trim($identifier)));
        $sourceKey = 'source:'.hash('sha256', $sourceContext);
        $now = $this->clock->now();
        $accountAllowed = $this->throttle->consume($accountKey, $now, $this->windowSeconds, $this->attemptLimit);
        $sourceAllowed = $this->throttle->consume($sourceKey, $now, $this->windowSeconds, $this->attemptLimit);
        if (!$accountAllowed || !$sourceAllowed) {
            $this->hasher->verifyDummy($password);

            return false;
        }
        $credential = $this->provider->byIdentifier($identifier);
        $hash = $credential === null ? null : $credential->passwordHash;
        $verified = $hash === null ? $this->hasher->verifyDummy($password) : $this->hasher->verify($password, $hash);
        if (!$verified || $credential === null) {
            return false;
        }
        $session->regenerate();
        $now = $this->clock->now();
        $session->set('_orku_auth_id', $credential->identity->id);
        $session->set('_orku_auth_created', $now);
        $session->set('_orku_auth_last', $now);

        return true;
    }
}
