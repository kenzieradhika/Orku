<?php

declare(strict_types=1);

use Orku\Core\Kernel;
use Orku\Http\Request;
use Orku\Http\Response;
use Orku\Routing\Router;

require dirname(__DIR__).'/vendor/autoload.php';

$count = $argv[1] ?? '10000';
if (!ctype_digit($count) || (int) $count < 1 || (int) $count > 1000000) {
    fwrite(STDERR, "Usage: php benchmarks/kernel.php [iterations: 1..1000000]\n");
    exit(1);
}
$iterations = (int) $count;
$router = new Router();
$router->get('/text', fn () => Response::text('Hello, Orku!'));
$router->get('/json', fn () => Response::json(['framework' => 'Orku', 'status' => 'running']));
$router->get('/users/{id}', fn (Request $r) => Response::json(['id' => $r->routeParameters['id']]));
$kernel = new Kernel($router);
$results = [];
foreach (['/text', '/json', '/users/42'] as $path) {
    for ($i = 0; $i < 1000; ++$i) {
        $kernel->handle(new Request('GET', $path));
    }
    $latencies = [];
    $errors = 0;
    $start = hrtime(true);
    for ($i = 0; $i < $iterations; ++$i) {
        $tick = hrtime(true);
        $response = $kernel->handle(new Request('GET', $path));
        $latencies[] = (hrtime(true) - $tick) / 1000;
        $errors += $response->status === 200 ? 0 : 1;
    }
    $elapsed = (hrtime(true) - $start) / 1000000000;
    sort($latencies);
    $results[] = [
        'path' => $path,
        'iterations' => $iterations,
        'elapsed_seconds' => $elapsed,
        'dispatches_per_second' => $iterations / $elapsed,
        'mean_us' => array_sum($latencies) / $iterations,
        'p95_us' => $latencies[(int) ceil($iterations * 0.95) - 1],
        'p99_us' => $latencies[(int) ceil($iterations * 0.99) - 1],
        'errors' => $errors,
    ];
}
echo json_encode([
    'kind' => 'in-process sequential kernel microbenchmark, not HTTP throughput',
    'php' => PHP_VERSION,
    'os' => PHP_OS_FAMILY,
    'architecture' => php_uname('m'),
    'opcache_enable_cli' => ini_get('opcache.enable_cli'),
    'peak_memory_bytes_including_harness' => memory_get_peak_usage(true),
    'results' => $results,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
