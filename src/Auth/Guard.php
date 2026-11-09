<?php

declare(strict_types=1);

namespace Orku\Auth;

use Orku\Http\HttpException;
use Orku\Http\Request;
use Orku\Http\Response;
use Orku\Web\Session;
use Orku\Web\CsrfManager;

final readonly class Guard
{
    public function __construct(private Session $session, private CredentialProvider $provider, private Clock $clock)
    {
    }

    public function identity(): ?Identity
    {
        $id = $this->session->get('_orku_auth_id');
        $created = $this->session->get('_orku_auth_created');
        $last = $this->session->get('_orku_auth_last');
        $now = $this->clock->now();
        if (!is_string($id) || !is_int($created) || !is_int($last) || $created > $now || $last > $now || $now - $last >= 1800 || $now - $created >= 43200) {
            $this->clear();

            return null;
        }
        $identity = $this->provider->byId($id);
        if ($identity === null) {
            $this->clear();

            return null;
        }
        $this->session->set('_orku_auth_last', $now);

        return $identity;
    }

    public function logout(?string $csrfToken, CsrfManager $csrf): void
    {
        $csrf->verify($csrfToken);
        $this->session->invalidate();
    }

    /** Existing Kernel middleware contract. */
    public function middleware(): callable
    {
        return function (Request $request, callable $next): Response {
            if ($this->identity() === null) {
                throw new HttpException(401);
            }

            $response = $next($request);
            if (!$response instanceof Response) {
                throw new \UnexpectedValueException('Authentication middleware requires a Response.');
            }

            return $response;
        };
    }

    private function clear(): void
    {
        $this->session->remove('_orku_auth_id');
        $this->session->remove('_orku_auth_created');
        $this->session->remove('_orku_auth_last');
    }
}
