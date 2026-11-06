<?php

declare(strict_types=1);

use Orku\Http\Request;
use Orku\Http\Response;
use Orku\Routing\Router;

$router = new Router();
$router->get('/', fn () => Response::text("Hello, Orku!\n"));
$router->get('/api/status', fn () => Response::json(['framework' => 'Orku', 'status' => 'running']));
$router->get('/hello/{name}', fn (Request $request) => Response::text('Hello, '.$request->routeParameters['name']."!\n"));

return $router;
