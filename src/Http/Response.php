<?php

declare(strict_types=1);

namespace Orku\Http;

final readonly class Response
{
    /** @var array<string, string> */
    public array $headers;

    /** @param array<string, string> $headers */
    public function __construct(public string $body = '', public int $status = 200, array $headers = [])
    {
        if ($status < 200 || $status > 599) {
            throw new \InvalidArgumentException('Response status must be 200..599.');
        }
        if (in_array($status, [204, 205, 304], true) && $body !== '') {
            throw new \InvalidArgumentException('This response status cannot carry a body.');
        }
        $this->headers = Headers::normalize($headers);
        foreach (['content-length', 'transfer-encoding'] as $name) {
            if (isset($this->headers[$name])) {
                throw new \InvalidArgumentException('Response framing is owned by runtime adapter.');
            }
        }
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($body, $status, ['content-type' => 'text/plain; charset=utf-8']);
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['content-type' => 'text/html; charset=utf-8']);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $status, ['content-type' => 'application/json; charset=utf-8']);
    }

    public static function redirect(string $location, int $status = 302): self
    {
        if (!in_array($status, [301, 302, 303, 307, 308], true) || $location === '') {
            throw new \InvalidArgumentException('Redirect requires a location and redirect status.');
        }

        return new self('', $status, ['location' => $location]);
    }
}
