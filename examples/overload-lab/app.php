<?php

declare(strict_types=1);

use Orku\Http\HttpException;
use Orku\Http\Request;
use Orku\Http\Response;
use Orku\Routing\Router;

$protected = getenv('LAB_VARIANT') === 'protected';
$router = new Router();
$router->get('/', static fn () => Response::html('<!doctype html><html lang="en"><meta charset="utf-8"><title>Orku Overload Lab</title><h1>Orku Overload Lab</h1><p>Bounded local resilience experiments. Not a DDoS benchmark.</p><p>See <a href="/api/status">status</a> and <a href="/health">health</a>.</p>'));
$router->get('/health', static fn () => Response::json(['status' => 'ok']));
$router->get('/api/status', static fn () => Response::json([
    'framework' => 'Orku', 'variant' => $protected ? 'protected' : 'baseline',
    'experiment' => 'finite deterministic CPU work; lab-only; no external targets',
    'limitations' => 'single-host synthetic workload; not representative of production or distributed denial of service',
]));
$router->get('/api/demo-work', static function (Request $request) use ($protected): Response {
    if ($protected && getenv('LAB_TEST_OVERLOAD') === '1' && ($request->query['overload'] ?? null) === '1') {
        throw new HttpException(503, headers: ['Retry-After' => '1']);
    }
    $iterations = 8000000;
    $value = 0;
    for ($i = 0; $i < $iterations; ++$i) {
        $value = ($value + $i) % 1000003;
    }

    return Response::json(['variant' => $protected ? 'protected' : 'baseline', 'work' => $value]);
});

return $router;
