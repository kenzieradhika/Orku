<?php

declare(strict_types=1);

namespace Orku\Auth;

use Orku\Web\CsrfManager;
use Orku\Web\Session;

final readonly class LoginService
{
    private string $dummyHash;

    public function __construct(private CredentialProvider $provider, private PasswordHasher $hasher, private ThrottleStore $throttle, private Clock $clock, private int $attemptLimit = 5, private int $windowSeconds = 900)
    {
        if ($attemptLimit < 1 || $windowSeconds < 1) {
            throw new \InvalidArgumentException('Throttle limits must be positive.');
        }
        $this->dummyHash = $hasher->hash(bin2hex(random_bytes(32)));
    }

    public function login(string $identifier, string $password, ?string $csrfToken, CsrfManager $csrf, Session $session, string $sourceContext): bool
    {
        $csrf->verify($csrfToken);
        $key = hash('sha256', strtolower(trim($identifier))."\0".$sourceContext);
        if (!$this->throttle->consume($key, $this->clock->now(), $this->windowSeconds, $this->attemptLimit)) {
            $this->hasher->verify($password, $this->dummyHash);

            return false;
        }
        $credential = $this->provider->byIdentifier($identifier);
        $hash = $credential === null ? $this->dummyHash : $credential->passwordHash;
        if (!$this->hasher->verify($password, $hash) || $credential === null) {
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
