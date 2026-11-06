<?php

declare(strict_types=1);

use Orku\Http\HttpException;
use Orku\Http\Request;
use Orku\Http\Response;
use Orku\Routing\Router;

$router = new Router();
$router->get('/', fn () => Response::json(['framework' => 'Orku', 'example' => 'REST API']));
$router->get('/api/status', fn () => Response::json(['framework' => 'Orku', 'status' => 'running']));
$router->get('/api/v1/users/{id}', fn (Request $request) => Response::json(['id' => $request->routeParameters['id']]));
$router->post('/api/v1/greetings', function (Request $request): Response {
    $data = $request->json();
    $name = is_array($data) ? ($data['name'] ?? null) : null;
    if (!is_string($name) || trim($name) === '' || strlen($name) > 100 || preg_match('/[\x00-\x1F\x7F]/', $name)) {
        throw new HttpException(422);
    }

    return Response::json(['message' => 'Hello, '.trim($name).'!'], 201);
});

return $router;
