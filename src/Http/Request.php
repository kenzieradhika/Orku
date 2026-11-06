<?php

declare(strict_types=1);

namespace Orku\Http;

final readonly class Request
{
    public string $method;
    public string $path;
    /** @var array<string, string> */
    public array $headers;

    /** @param array<string, string> $headers
     *  @param array<array-key, mixed> $query
     *  @param array<string, string> $cookies
     *  @param array<string, string> $routeParameters
     */
    public function __construct(
        string $method,
        private string $rawPath,
        array $headers = [],
        public array $query = [],
        public string $body = '',
        public array $cookies = [],
        public array $routeParameters = [],
        private int $maxBodyBytes = 1048576,
    ) {
        if (!preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D', $method)) {
            throw new HttpException(400);
        }
        $this->method = strtoupper($method);
        if ($rawPath === '' || $rawPath[0] !== '/' || str_starts_with($rawPath, '//')
            || preg_match('/[?#\\\\\x00-\x20\x7F]|%(?![0-9a-f]{2})|%2f|%5c/i', $rawPath)) {
            throw new HttpException(400);
        }
        $path = rawurldecode($rawPath);
        if (!preg_match('//u', $path) || preg_match('/[\x00-\x1F\x7F]/', $path)
            || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path)) {
            throw new HttpException(400);
        }
        $this->path = $path;
        if ($maxBodyBytes < 0) {
            throw new \InvalidArgumentException('Body limit must be nonnegative.');
        }
        if (strlen($body) > $maxBodyBytes) {
            throw new HttpException(413);
        }
        try {
            $this->headers = Headers::normalize($headers);
        } catch (\InvalidArgumentException) {
            throw new HttpException(400);
        }
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function json(): mixed
    {
        $type = strtolower(trim(explode(';', $this->header('content-type') ?? '')[0]));
        if ($type !== 'application/json' && !preg_match('~^application/[a-z0-9!#$&^_.+-]+\+json$~D', $type)) {
            throw new HttpException(415);
        }
        try {
            return json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException(400);
        }
    }

    /** @param array<string, string> $parameters */
    public function withRouteParameters(array $parameters): self
    {
        return new self($this->method, $this->rawPath, $this->headers, $this->query, $this->body, $this->cookies, $parameters, $this->maxBodyBytes);
    }
}
