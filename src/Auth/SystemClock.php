<?php

declare(strict_types=1);

namespace Orku\Auth;

final class SystemClock implements Clock
{
    public function now(): int
    {
        return time();
    }
}
