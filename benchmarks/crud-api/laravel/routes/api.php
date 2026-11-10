<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Orku\CrudBench\CrudError;
use Orku\CrudBench\CrudStore;

$store = CrudStore::connect(
    (string) (getenv('CRUD_DB_PATH') ?: sys_get_temp_dir().'/laravel-crud.sqlite'),
);
$maintenance = getenv('CRUD_BENCH_MAINTENANCE') === '1';
$maxBodyBytes = CrudStore::MAX_BODY_BYTES;

$json = static fn (mixed $data, int $status = 200): JsonResponse => new JsonResponse($data, $status);
$error = static fn (int $status): JsonResponse => new JsonResponse(CrudError::envelope($status), $status);

$payload = static function (Request $request) use ($maxBodyBytes, $error): array {
    $type = strtolower(trim(explode(';', (string) $request->header('content-type', ''))[0]));
    if ($type !== 'application/json' && !preg_match('~^application/[a-z0-9!#$&^_.+-]+\+json$~D', $type)) {
        throw new CrudError(415);
    }
    if (strlen((string) $request->getContent()) > $maxBodyBytes) {
        throw new CrudError(413);
    }
    try {
        $data = json_decode((string) $request->getContent(), true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
        throw new CrudError(400);
    }
    if (!is_array($data)) {
        throw new CrudError(422);
    }

    return $data;
};

$wrap = static fn (callable $handler): callable => static function (Request $request, mixed ...$arguments) use ($handler, $error): JsonResponse|Response {
    try {
        return $handler($request, ...$arguments);
    } catch (CrudError $failure) {
        return $error($failure->status);
    } catch (\Throwable $failure) {
        error_log('LARAVEL_FIXTURE '.$failure->getMessage().' @ '.$failure->getFile().':'.$failure->getLine());
        return $error(500);
    }
};

Route::get('/api/items', $wrap(static fn (Request $request): JsonResponse => $json($store->list($request->query->all()))));
Route::get('/api/items/{id}', $wrap(static fn (Request $request, string $id): JsonResponse => $json($store->get($id))));
Route::post('/api/items', $wrap(static fn (Request $request): JsonResponse => $json(
    $store->create($payload($request), $request->query('simulate_failure') === '1'),
    201,
)));
Route::put('/api/items/{id}', $wrap(static fn (Request $request, string $id): JsonResponse => $json(
    $store->replace($id, $payload($request)),
)));
Route::delete('/api/items/{id}', $wrap(static function (Request $request, string $id) use ($store): Response {
    $store->delete($id, (string) $request->query('version', ''));

    return new Response('', 204);
}));

if ($maintenance) {
    Route::post('/api/bench/reset', $wrap(static fn (): JsonResponse => $json($store->reset())));
    Route::get('/api/bench/state', $wrap(static fn (): JsonResponse => $json($store->state())));
}
