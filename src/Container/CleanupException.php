<?php

declare(strict_types=1);

namespace Orku\Container;

final class CleanupException extends \RuntimeException
{
    /** @param non-empty-list<\Throwable> $causes */
    public function __construct(public readonly array $causes)
    {
        parent::__construct('Scope cleanup failed.', previous: $causes[0]);
    }
}
