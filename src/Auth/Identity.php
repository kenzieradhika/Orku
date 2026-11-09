<?php

declare(strict_types=1);

namespace Orku\Auth;

final readonly class Identity
{
    /** @param array<string, bool|float|int|string|null> $attributes */
    public function __construct(public string $id, public array $attributes = [])
    {
        if ($id === '' || strlen($id) > 255) {
            throw new \InvalidArgumentException('Identity ID must contain 1 to 255 bytes.');
        }
    }
}
