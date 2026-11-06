<?php

declare(strict_types=1);

namespace Orku\Http;

final class HttpException extends \RuntimeException
{
    /** @var array<string, string> */
    public readonly array $headers;

    /** @param array<string, string> $headers */
    public function __construct(public readonly int $status, string $message = '', array $headers = [])
    {
        if ($status < 400 || $status > 599) {
            throw new \InvalidArgumentException('HTTP exception status must be 400..599.');
        }
        $this->headers = Headers::normalize($headers);
        parent::__construct($message, $status);
    }
}
