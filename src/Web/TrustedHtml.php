<?php

declare(strict_types=1);

namespace Orku\Web;

final readonly class TrustedHtml
{
    private function __construct(public string $html)
    {
    }

    public static function fromTrustedString(string $html): self
    {
        return new self($html);
    }
}
