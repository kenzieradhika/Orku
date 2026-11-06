# Orku Web getting started

Requires PHP 8.4+, ext-json and Composer 2. Form routes additionally require the
native session extension and writable private session storage. Development tools
need DOM/XML/XMLWriter/mbstring. No Node.js, database or runtime Composer package.

```bash
composer install
php bin/orku serve fullstack-app 127.0.0.1:8000
```

Open `/`, `/about`, `/components`, `/contact`, `/api/status`, and `/missing`.
Submit empty feedback for HTTP 422; valid feedback redirects with 303. This demo
does not send or persist messages. Do not enter sensitive information.

Production/source-only installation: `composer install --no-dev --prefer-dist`.
Run these commands from the repository root after cloning the source.

## Render a page

```php
use Orku\Http\Response;
use Orku\Web\ViewRenderer;

$views = new ViewRenderer([__DIR__.'/resources/views']);
return Response::html($views->render('pages.home', ['title' => 'Hello <Orku>']));
```

`resources/views/pages/home.php`:

```php
<?php declare(strict_types=1); /** @var Orku\Web\ViewContext $view */ ?>
<h1><?= $view->escape($view->get('title')) ?></h1>
```

`get()` requires the prop to exist. `string()` additionally requires a string.
Nested data is ordinary PHP arrays. Use PHP loops/conditionals, escape every
dynamic text/attribute value, and validate link URLs with `url()`.

## Layouts, partials and slots

```php
return Response::html($views->layout('layouts.main', 'pages.home', [
    'title' => 'Hello',
]));
```

Layout template:

```php
<main><?= $view->raw($view->slot('content')) ?></main>
```

Components live under `components/`, receive only supplied props and slots:

```php
<?= $view->component('card', ['title' => 'Example'], [
    'content' => Orku\Web\TrustedHtml::fromTrustedString('<p>Application-owned HTML</p>'),
]) ?>
<?= $view->partial('pages.note', ['message' => 'Explicit data']) ?>
```

Component template:

```php
<article><h2><?= $view->escape($view->get('title')) ?></h2>
<?= $view->raw($view->slot('content')) ?></article>
```

Nested layouts use rendered inner HTML as an explicit `TrustedHtml` content slot
of the outer layout via `render()`. Slots do not inherit implicitly.
`TrustedHtml` is a trust assertion, never an HTML sanitizer.

## Assets

```php
$assets = new Orku\Web\AssetManager(__DIR__.'/public/assets', '/assets');
$css = $assets->url('css/app.css');
```

Pass URL as a prop, then use `<?= $view->url($view->string('css')) ?>` in a quoted
`href`. The demo registers exact static asset routes using `$assets->serve(...)`.
Register each new asset route or have Apache/Nginx serve the public assets folder.
AssetManager supports CSS, JS, common images and fonts without a build step.
The shipped demo needs no JS and uses installed system fonts, not a font CDN.

## Forms

```php
$session = new Orku\Web\Session($request); // Secure cookies by default
$csrf = new Orku\Web\CsrfManager($session);
// GET: render $csrf->token() as an escaped hidden _csrf value.
// POST:
$form = Orku\Web\FormRequest::parse($request);
$csrf->verify($form->fields['_csrf'] ?? null);
$result = $form->validate([
    'email' => ['type' => 'email', 'required' => true, 'max' => 254],
    'age' => ['type' => 'number', 'min' => 0, 'max' => 120],
    'kind' => ['type' => 'string', 'allowed' => ['bug', 'idea']],
]);
// $result->valid(), $result->values, $result->errors (field => list<string>)
// Return $session->commit($response) to save session and attach cookie/no-store.
```

`values` contains only successful fields; never use it to mutate state unless
`valid()` is true. Optional empty fields are omitted. Strings are trimmed, lengths
count Unicode code points, numbers become finite floats. Email checks syntax, not
deliverability. Render old fields and errors with escaping. See working route in
`examples/fullstack-app/app.php` for complete GET/POST lifecycle.

## Deployment

Set document root to `examples/fullstack-app/public`, never repository root.
Apache `FallbackResource /index.php` or Nginx `try_files $uri /index.php?$query_string`
can front PHP-FPM; deny dotfiles and never execute uploaded files. For assets use
correct MIME, `nosniff`, ETag and explicit cache policy. Dynamic CSP comes from app.
The CLI demo routes only known assets; it never returns `false` to expose files.

Subdirectory: `ORKU_BASE_PATH=/demo php bin/orku serve fullstack-app`, then open
`/demo/`. Production web-server fallback and script location must match this prefix.
HTTPS sessions default to Secure cookies. `ORKU_HTTP_DEV=1` disables Secure only
for explicitly configured local HTTP; CLI development server also does this.
Never use either development server or HTTP override for public production traffic.
`ORKU_DEBUG=1` exposes development diagnostics: keep disabled outside trusted local
development. Forwarded headers never decide cookie security.

Apache/FPM, Nginx/FPM, cPanel, ARM and Raspberry Pi remain deployment targets, not
environments verified in this workspace. PHP CLI server on x86_64 Linux was tested.
