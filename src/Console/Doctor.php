<?php

declare(strict_types=1);

namespace Orku\Console;

final class Doctor
{
    /** @return array{php: string, sapi: string, checks: array<string, bool>, warnings: list<string>} */
    public static function report(): array
    {
        $checks = [
            'supported_php' => version_compare(phpversion(), '8.4.0', '>=') && version_compare(phpversion(), '9.0.0', '<'),
            'json' => extension_loaded('json'),
            'session' => extension_loaded('session'),
            'debug_disabled' => getenv('ORKU_DEBUG') !== '1',
            'secure_cookie_override_disabled' => getenv('ORKU_HTTP_DEV') !== '1',
            'display_errors_disabled' => in_array(strtolower((string) ini_get('display_errors')), ['', '0', 'off'], true),
            'opcache_enabled' => extension_loaded('Zend OPcache') && filter_var(ini_get(PHP_SAPI === 'cli' ? 'opcache.enable_cli' : 'opcache.enable'), FILTER_VALIDATE_BOOL),
        ];
        $warnings = [];
        foreach ($checks as $name => $passed) {
            if (!$passed) {
                $warnings[] = $name;
            }
        }
        $warnings[] = 'CLI diagnostics do not verify FPM configuration, public document root, TLS, or session storage permissions.';

        return ['php' => PHP_VERSION, 'sapi' => PHP_SAPI, 'checks' => $checks, 'warnings' => $warnings];
    }
}
