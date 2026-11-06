<?php

declare(strict_types=1);

namespace Orku\Runtime;

use Orku\Core\Kernel;
use Orku\Http\HttpException;
use Orku\Http\Request;
use Orku\Http\Response;

final class SapiRuntime
{
    public static function capture(int $maxBodyBytes = 1048576): Request
    {
        if ($maxBodyBytes < 0 || $maxBodyBytes === PHP_INT_MAX) {
            throw new \InvalidArgumentException('Body limit must be 0..PHP_INT_MAX-1.');
        }
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        if (!is_string($method) || !is_string($uri)) {
            throw new HttpException(400);
        }
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (!is_string($value)) {
                continue;
            }
            if (str_starts_with($name, 'HTTP_')) {
                $headers[str_replace('_', '-', substr($name, 5))] = $value;
            } elseif ($name === 'CONTENT_TYPE' || $name === 'CONTENT_LENGTH') {
                $headers[str_replace('_', '-', $name)] = $value;
            }
        }
        $length = $headers['CONTENT-LENGTH'] ?? null;
        if ($length !== null) {
            if (!preg_match('/^[0-9]+$/D', $length)) {
                throw new HttpException(400);
            }
            if ((float) $length > $maxBodyBytes) {
                throw new HttpException(413);
            }
        }
        $input = fopen('php://input', 'rb');
        if ($input === false) {
            throw new \RuntimeException('Cannot open request body.');
        }
        try {
            $body = stream_get_contents($input, $maxBodyBytes + 1);
        } finally {
            fclose($input);
        }
        if ($body === false) {
            throw new \RuntimeException('Cannot read request body.');
        }
        $cookies = [];
        foreach ($_COOKIE as $name => $value) {
            if (is_string($value)) {
                $cookies[(string) $name] = $value;
            }
        }

        return new Request($method, explode('?', $uri, 2)[0], $headers, $_GET, $body, $cookies, maxBodyBytes: $maxBodyBytes);
    }

    /** @param array<string, string> $securityHeaders */
    public static function run(Kernel $kernel, int $maxBodyBytes = 1048576, array $securityHeaders = []): void
    {
        $level = ob_get_level();
        ob_start();
        try {
            try {
                $request = self::capture($maxBodyBytes);
                $response = $kernel->handle($request);
            } catch (\Throwable $error) {
                $response = $kernel->error($error);
            }
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
        self::emit($response, ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD', $securityHeaders);
    }

    /** @param array<string, string> $securityHeaders */
    public static function emit(Response $response, bool $head = false, array $securityHeaders = []): void
    {
        if (headers_sent()) {
            throw new \RuntimeException('Cannot emit response: headers already sent.');
        }
        $defaults = \Orku\Http\Headers::normalize($securityHeaders) + [
            'x-content-type-options' => 'nosniff',
            'referrer-policy' => 'no-referrer',
            'content-security-policy' => "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'",
            'content-type' => 'text/plain; charset=utf-8',
        ];
        header_remove('X-Powered-By');
        foreach ($response->headers + $defaults as $name => $value) {
            header($name.': '.$value, $name !== 'set-cookie');
        }
        foreach ($response->cookies as $cookie) {
            header('set-cookie: '.$cookie, false);
        }
        http_response_code($response->status);
        if (!in_array($response->status, [204, 304], true)) {
            header('Content-Length: '.strlen($response->body));
        }
        if (!$head) {
            echo $response->body;
        }
    }
}
