<?php

declare(strict_types=1);

namespace Orku\Auth;

interface Clock
{
    public function now(): int;
}
