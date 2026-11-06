<?php

declare(strict_types=1);

namespace Orku\Core;

use Orku\Http\HttpException;
use Orku\Http\Request;
use Orku\Http\Response;
use Orku\Routing\Router;

final class Kernel
{
    /** @var null|\Closure(\Throwable): void */
    private readonly ?\Closure $report;
    /** @var null|\Closure(\Throwable, Response): Response */
    private readonly ?\Closure $renderError;

    /**
     * @param null|callable(\Throwable): void $report
     * @param null|callable(\Throwable, Response): Response $renderError
     */
    public function __construct(private readonly Router $router, private readonly bool $debug = false, ?callable $report = null, ?callable $renderError = null)
    {
        $this->report = $report === null ? null : \Closure::fromCallable($report);
        $this->renderError = $renderError === null ? null : \Closure::fromCallable($renderError);
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->router->dispatch($request);
        } catch (\Throwable $error) {
            return $this->error($error);
        }
    }

    public function error(\Throwable $error): Response
    {
        $status = $error instanceof HttpException ? $error->status : 500;
        if ($status >= 500) {
            try {
                if ($this->report !== null) {
                    ($this->report)($error);
                } else {
                    error_log((string) $error);
                }
            } catch (\Throwable) {
                error_log('Orku: exception reporter failed.');
            }
        }
        $message = match ($status) {
            400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden',
            404 => 'Not Found', 405 => 'Method Not Allowed', 409 => 'Conflict',
            413 => 'Content Too Large', 415 => 'Unsupported Media Type',
            422 => 'Unprocessable Content', 429 => 'Too Many Requests',
            500 => 'Internal Server Error', 503 => 'Service Unavailable',
            default => $status >= 500 ? 'Server Error' : 'Request Error',
        };
        $payload = ['status' => $status, 'message' => $message];
        if ($this->debug) {
            $payload['debug'] = ['type' => $error::class, 'message' => $error->getMessage(), 'file' => $error->getFile(), 'line' => $error->getLine(), 'trace' => $error->getTraceAsString()];
        }
        $headers = $error instanceof HttpException ? $error->headers : [];
        unset($headers['content-length'], $headers['transfer-encoding']);

        $safe = new Response(
            json_encode(['error' => $payload], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            $status,
            ['content-type' => 'application/json; charset=utf-8', 'cache-control' => 'no-store'] + $headers,
        );
        if ($this->renderError !== null) {
            try {
                return ($this->renderError)($error, $safe);
            } catch (\Throwable) {
                error_log('Orku: error renderer failed.');
            }
        }

        return $safe;
    }
}
