<?php

declare(strict_types=1);

use Orku\Core\Kernel;
use Orku\Http\Request;
use Orku\Http\Response;
use Orku\Routing\Router;
use Orku\Runtime\SapiRuntime;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$router = new Router();
$router->post('/request', fn (Request $r) => Response::json(['method' => $r->method, 'query' => $r->query, 'cookie' => $r->cookies['theme'] ?? null, 'header' => $r->header('x-custom'), 'json' => $r->json()]));
$router->get('/error', function (): Response {
    echo 'private accidental output';
    throw new RuntimeException('password=secret');
});
$router->get('/redirect', fn () => Response::redirect('/target'));
$router->get('/accepted', fn () => new Response('accepted', 202, ['Location' => '/jobs/1']));
$router->get('/empty', fn () => new Response(status: 204));
$router->get('/html', fn () => Response::html('<h1>Orku</h1>'));
$router->get('/cookies', function (Request $request): Response {
    $session = new \Orku\Web\Session($request);
    $session->set('test', 'value');

    return $session->commit(new Response('cookies', headers: ['Set-Cookie' => 'legacy=1; Path=/'], cookies: ['theme=dark; Path=/', 'language=id; Expires=Wed, 09 Jun 2038 10:18:14 GMT; Path=/']));
});
SapiRuntime::run(new Kernel($router, report: static function (): void {
}));
