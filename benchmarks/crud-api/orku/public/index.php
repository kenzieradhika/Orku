<?php

declare(strict_types=1);

$root = getenv('ORKU_ROOT') ?: dirname(__DIR__, 4);
require $root.'/vendor/autoload.php';
require dirname(__DIR__, 2).'/CrudStore.php';

use Orku\Core\Kernel;
use Orku\CrudBench\CrudError;
use Orku\CrudBench\CrudStore;
use Orku\Http\Request;
use Orku\Http\Response;
use Orku\Routing\Router;
use Orku\Runtime\SapiRuntime;

$dbPath = getenv('CRUD_DB_PATH') ?: sys_get_temp_dir().'/orku-crud.sqlite';
$store = CrudStore::connect($dbPath);
$maintenance = getenv('CRUD_BENCH_MAINTENANCE') === '1';

$error = static fn (CrudError $failure): Response => new Response(
    json_encode(CrudError::envelope($failure->status), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
    $failure->status,
    ['content-type' => 'application/json'],
);

$json = static fn (mixed $data, int $status = 200): Response => new Response(
    json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
    $status,
    ['content-type' => 'application/json'],
);

$wrap = static function (callable $handler) use ($error): callable {
    return static function (Request $request) use ($handler, $error): Response {
        try {
            return $handler($request);
        } catch (CrudError $failure) {
            return $error($failure);
        }
    };
};

$router = new Router();
$router->get('/api/items', $wrap(static fn (Request $request): Response => $json($store->list($request->query))));
$router->get('/api/items/{id}', $wrap(static fn (Request $request): Response => $json(
    $store->get((string) $request->routeParameters['id']),
)));
$router->post('/api/items', $wrap(static fn (Request $request): Response => $json(
    $store->create($request->json(), ($request->query['simulate_failure'] ?? null) === '1'),
    201,
)));
$router->put('/api/items/{id}', $wrap(static fn (Request $request): Response => $json(
    $store->replace((string) $request->routeParameters['id'], $request->json()),
)));
$router->delete('/api/items/{id}', $wrap(static function (Request $request) use ($store): Response {
    $store->delete((string) $request->routeParameters['id'], (string) ($request->query['version'] ?? ''));

    return new Response('', 204);
}));

if ($maintenance) {
    $router->post('/api/bench/reset', $wrap(static fn (): Response => $json($store->reset())));
    $router->get('/api/bench/state', $wrap(static fn (): Response => $json($store->state())));
}

$kernel = new Kernel($router);
SapiRuntime::run($kernel, CrudStore::MAX_BODY_BYTES);
