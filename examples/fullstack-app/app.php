<?php

declare(strict_types=1);

use Orku\Http\Request;
use Orku\Http\Response;
use Orku\Routing\Router;
use Orku\Web\AssetManager;
use Orku\Web\CsrfManager;
use Orku\Web\FormRequest;
use Orku\Web\Session;
use Orku\Web\ViewRenderer;

$prefix = getenv('ORKU_BASE_PATH') ?: '';
if ($prefix !== '' && !preg_match('~^/[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*$~D', $prefix)) {
    throw new InvalidArgumentException('Invalid ORKU_BASE_PATH.');
}
$router = new Router();
$assets = new AssetManager(__DIR__.'/public/assets', $prefix.'/assets');
$csp = "default-src 'none'; style-src 'self'; script-src 'self'; img-src 'self'; font-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'";
$page = static function (string $name, string $title, array $data = [], int $status = 200) use ($assets, $prefix, $csp): Response {
    $views = new ViewRenderer([__DIR__.'/resources/views']);
    $props = ['title' => $title, 'prefix' => $prefix, 'css' => $assets->url('css/app.css')];
    foreach ($data as $key => $value) {
        if (!is_string($key) || array_key_exists($key, $props)) {
            throw new InvalidArgumentException('Invalid page prop.');
        }
        $props[$key] = $value;
    }
    return new Response($views->layout('layouts.main', 'pages.'.$name, $props), $status, ['content-type' => 'text/html; charset=utf-8', 'content-security-policy' => $csp]);
};
$router->get($prefix.'/', static fn (): Response => $page('home', 'HTML first. PHP throughout.'));
$router->get($prefix.'/about', static fn (): Response => $page('about', 'Small pieces. Explicit boundaries.'));
$router->get($prefix.'/components', static fn (): Response => $page('components', 'Components, without a compiler.', ['image' => $assets->url('images/example.svg')]));
$router->get($prefix.'/contact/success', static fn (): Response => $page('success', 'Demo submission validated.'));
$contact = static function (Request $request) use ($page, $prefix): Response {
    // CLI server is local development only; production requires explicit TLS policy.
    $secure = PHP_SAPI !== 'cli-server' && getenv('ORKU_HTTP_DEV') !== '1';
    $session = new Session($request, secure: $secure, path: $prefix === '' ? '/' : $prefix.'/');
    $csrf = new CsrfManager($session);
    if ($request->method !== 'POST') {
        return $session->commit($page('contact', 'A form that stays on the server.', ['token' => $csrf->token(), 'fields' => [], 'errors' => []]));
    }
    $form = FormRequest::parse($request);
    $csrf->verify($form->fields['_csrf'] ?? null);
    $result = $form->validate([
        'name' => ['type' => 'string', 'required' => true, 'min' => 2, 'max' => 80],
        'email' => ['type' => 'email', 'required' => true, 'max' => 254],
        'message' => ['type' => 'string', 'required' => true, 'min' => 10, 'max' => 2000],
    ]);
    if (!$result->valid()) {
        return $session->commit($page('contact', 'Check your feedback.', ['token' => $csrf->token(), 'fields' => $form->fields, 'errors' => $result->errors], 422));
    }

    return $session->commit(Response::redirect($prefix.'/contact/success', 303));
};
$router->get($prefix.'/contact', $contact);
$router->post($prefix.'/contact', $contact);
$router->get($prefix.'/api/status', static fn (): Response => Response::json(['framework' => 'Orku', 'status' => 'running']));
foreach (['css/app.css', 'images/example.svg'] as $path) {
    $router->get($assets->url($path), $assets->serve(...));
}

return $router;
