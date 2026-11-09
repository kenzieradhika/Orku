<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        using: function (): void {
            require __DIR__.'/../routes/api.php';
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Intentionally empty: benchmark routes use no session, CSRF or throttle middleware.
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Handlers return their own JSON envelopes so both fixtures match byte-for-byte.
    })->create();
