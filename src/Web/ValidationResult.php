<?php

declare(strict_types=1);

namespace Orku\Web;

final readonly class ValidationResult
{
    /**
     * @param array<string, string|float> $values
     * @param array<string, list<string>> $errors
     */
    public function __construct(public array $values, public array $errors)
    {
    }

    public function valid(): bool
    {
        return $this->errors === [];
    }
}
