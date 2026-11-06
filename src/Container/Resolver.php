<?php

declare(strict_types=1);

namespace Orku\Container;

final class Resolver
{
    /** @param \Closure(string): object $resolve */
    public function __construct(private readonly \Closure $resolve)
    {
    }

    public function get(string $id): object
    {
        return ($this->resolve)($id);
    }
}
