<?php

declare(strict_types=1);

use Orku\Core\Kernel;
use Orku\Http\Request;
use Orku\Http\Response;
use Orku\Routing\Router;
use Orku\Web\FormRequest;
use Orku\Web\ViewRenderer;

require dirname(__DIR__).'/vendor/autoload.php';

$count = $argv[1] ?? '10000';
if (!ctype_digit($count) || (int) $count < 1 || (int) $count > 1000000) {
    fwrite(STDERR, "Usage: php benchmarks/web.php [iterations: 1..1000000]\n");
    exit(1);
}
$iterations = (int) $count;
$views = new ViewRenderer([__DIR__.'/views']);
$data = ['title' => 'Welcome <Orku>', 'items' => ['first', 'second', 'third', 'fourth', 'fifth']];
$router = new Router();
$router->get('/api/status', static fn (): Response => Response::json(['framework' => 'Orku', 'status' => 'running']));
$core = new Kernel($router);
$demoRouter = require dirname(__DIR__).'/examples/fullstack-app/app.php';
if (!$demoRouter instanceof Router) {
    throw new RuntimeException('Expected Router.');
}
$web = new Kernel($demoRouter);
$form = new Request('POST', '/', ['content-type' => 'application/x-www-form-urlencoded'], body: 'email=bad');
$cases = [
    'core_json' => static fn (): Response => $core->handle(new Request('GET', '/api/status')),
    'web_enabled_api' => static fn (): Response => $web->handle(new Request('GET', '/api/status')),
    'plain_html' => static fn (): Response => Response::html('<h1>Welcome</h1>'),
    'template' => static fn (): string => $views->render('page', $data),
    'layout' => static fn (): string => $views->layout('layout', 'page', $data),
    'components' => static fn (): string => $views->layout('layout', 'components', $data),
    'validation_failure' => static function () use ($form, $views): Response {
        $result = FormRequest::parse($form)->validate(['email' => ['type' => 'email', 'required' => true]]);
        return Response::html($views->render('errors', ['errors' => $result->errors]), 422);
    },
];
$results = [];
foreach ($cases as $name => $run) {
    for ($i = 0; $i < 1000; ++$i) {
        $run();
    }
    $samples = [];
    $start = hrtime(true);
    for ($i = 0; $i < $iterations; ++$i) {
        $tick = hrtime(true);
        $run();
        $samples[] = (hrtime(true) - $tick) / 1000;
    }
    $elapsed = (hrtime(true) - $start) / 1e9;
    sort($samples);
    $results[$name] = ['mean_us' => array_sum($samples) / $iterations, 'p95_us' => $samples[(int) ceil($iterations * .95) - 1], 'elapsed_seconds' => $elapsed];
}
unset($samples);
$before = memory_get_usage(false);
for ($i = 0; $i < $iterations; ++$i) {
    $views->layout('layout', 'components', $data);
}
$after = memory_get_usage(false);
$usage = getrusage();
echo json_encode(['kind' => 'sequential in-process microbenchmark, not HTTP throughput', 'php' => PHP_VERSION, 'architecture' => php_uname('m'), 'opcache_enable_cli' => ini_get('opcache.enable_cli'), 'iterations' => $iterations, 'warmup_per_case' => 1000, 'results' => $results, 'repeat_render_memory_delta_bytes' => $after - $before, 'peak_memory_bytes_including_harness' => memory_get_peak_usage(true), 'process_max_rss_kib_linux' => $usage['ru_maxrss'] ?? null], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
