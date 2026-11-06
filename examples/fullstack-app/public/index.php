<?php

declare(strict_types=1);

use Orku\Core\Kernel;
use Orku\Http\Response;
use Orku\Runtime\SapiRuntime;
use Orku\Web\AssetManager;
use Orku\Web\ViewRenderer;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$router = require dirname(__DIR__).'/app.php';
if (!$router instanceof Orku\Routing\Router) {
    throw new RuntimeException('Application must return Router.');
}
$debug = getenv('ORKU_DEBUG') === '1';
$kernel = new Kernel($router, debug: $debug, renderError: static function (Throwable $error, Response $safe) use ($debug): Response {
    $prefix = getenv('ORKU_BASE_PATH') ?: '';
    $views = new ViewRenderer([dirname(__DIR__).'/resources/views']);
    $assets = new AssetManager(__DIR__.'/assets', $prefix.'/assets');
    $title = $safe->status === 404 ? 'Page not found' : ($safe->status >= 500 ? 'Something went wrong' : 'Request could not be accepted');
    $html = $views->layout('layouts.main', 'pages.error', ['title' => $title, 'prefix' => $prefix, 'css' => $assets->url('css/app.css'), 'status' => $safe->status, 'detail' => $debug ? (string) $error : '']);

    return new Response($html, $safe->status, ['content-type' => 'text/html; charset=utf-8', 'content-security-policy' => "default-src 'none'; style-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'"] + $safe->headers);
});
SapiRuntime::run($kernel);
