<?php

declare(strict_types=1);

namespace Orku\Auth;

interface ThrottleStore
{
    /** Atomically allows up to limit attempts during a fixed window. */
    public function consume(string $key, int $now, int $windowSeconds, int $limit): bool;
}
