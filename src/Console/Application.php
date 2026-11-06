<?php

declare(strict_types=1);

namespace Orku\Console;

use Orku\Routing\Router;

final class Application
{
    /** @param list<string> $arguments */
    public static function run(array $arguments): int
    {
        $command = $arguments[0] ?? 'help';
        if ($command === 'doctor' && count($arguments) === 1) {
            $report = Doctor::report();
            echo json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n";

            return $report['checks']['supported_php'] && $report['checks']['json'] ? 0 : 1;
        }
        if (in_array($command, ['help', '--help', '-h'], true) && count($arguments) <= 1) {
            echo "Orku Web v0.2-dev\n\nphp bin/orku serve [hello-world|rest-api|fullstack-app] [127.0.0.1:8000]\nphp bin/orku routes [hello-world|rest-api|fullstack-app]\nphp bin/orku doctor\n\nDevelopment server only. Not for production.\n";

            return 0;
        }
        if (!in_array($command, ['serve', 'routes'], true) || count($arguments) > ($command === 'serve' ? 3 : 2)) {
            fwrite(STDERR, "Unknown command or arguments. Run php bin/orku help.\n");

            return 1;
        }
        $example = $arguments[1] ?? 'hello-world';
        if (!in_array($example, ['hello-world', 'rest-api', 'fullstack-app'], true)) {
            fwrite(STDERR, "Unknown example. Choose hello-world, rest-api or fullstack-app.\n");

            return 1;
        }
        $directory = dirname(__DIR__, 2).'/examples/'.$example;
        if ($command === 'routes') {
            $router = require $directory.'/app.php';
            if (!$router instanceof Router) {
                throw new \RuntimeException('Application must return Router.');
            }
            foreach ($router->routes() as $route) {
                echo $route['method'].' '.$route['path']."\n";
            }

            return 0;
        }
        $address = $arguments[2] ?? '127.0.0.1:8000';
        if (!preg_match('/^(127\.0\.0\.1|0\.0\.0\.0|localhost|\[::1\]):([0-9]{1,5})$/D', $address, $matches) || (int) $matches[2] < 1 || (int) $matches[2] > 65535) {
            fwrite(STDERR, "Invalid bind address. Use 127.0.0.1:8000 (port 1..65535).\n");

            return 1;
        }
        $options = ['-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'expose_php=0', '-d', 'enable_post_data_reading=0', '-S', $address, '-t', $directory.'/public', $directory.'/public/index.php'];
        if (function_exists('pcntl_exec')) {
            pcntl_exec(PHP_BINARY, $options);
            throw new \RuntimeException('Cannot start PHP development server.');
        }
        $process = proc_open([PHP_BINARY, ...$options], [STDIN, STDOUT, STDERR], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Cannot start PHP development server.');
        }

        return proc_close($process);
    }
}
