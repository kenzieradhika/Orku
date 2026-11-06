# Orku Framework

**Optimized Runtime Kernel Utility**

Framework HTTP kecil untuk PHP 8.4+, dengan routing, request/response, penanganan
error, serta rendering HTML di server menggunakan template PHP. Tidak membutuhkan
Node.js, database, atau paket pihak ketiga sebagai dependensi runtime.

**Status: v0.2-dev.** Masih dalam pengembangan, belum rilis stabil atau siap
produksi. Autentikasi, migrasi database, dan autowiring belum tersedia.

## Mulai cepat

Persyaratan: PHP 8.4+ (batas Composer `^8.4`), Composer 2, ekstensi JSON.
Tooling pengembangan membutuhkan DOM, XML, XMLWriter, dan mbstring.
Form membutuhkan ekstensi session dan penyimpanan session privat yang dapat ditulis.

```bash
git clone https://github.com/kenzieradhika/Orku.git
cd Orku
composer install
php bin/orku serve fullstack-app 127.0.0.1:8000
```

Buka **http://127.0.0.1:8000**. Hentikan server dengan `Ctrl+C`.
Server ini hanya untuk pengembangan lokal, bukan produksi.

| URL demo | Kegunaan |
| --- | --- |
| `/` | Halaman utama |
| `/about` | Informasi demo |
| `/components` | Contoh komponen template |
| `/contact` | Form feedback dengan validasi dan CSRF |
| `/api/status` | Status aplikasi dalam JSON |
| `/missing` | Contoh error 404 |

Halaman demo bekerja tanpa JavaScript. Form feedback **tidak menyimpan atau
mengirim pesan**. Jangan masukkan data sensitif.

## Pilihan contoh dan CLI

```bash
php bin/orku help
php bin/orku serve hello-world 127.0.0.1:8000
php bin/orku serve rest-api 127.0.0.1:8001
php bin/orku routes fullstack-app
```

Jalankan satu perintah `serve` per terminal. `serve` dan `routes` menerima nama
`hello-world`, `rest-api`, atau `fullstack-app`. Tanpa nama, CLI menggunakan
`hello-world`; alamat default server adalah `127.0.0.1:8000`.

## Struktur proyek

```text
bin/orku       CLI pengembangan
src/           Implementasi framework
examples/      hello-world, rest-api, fullstack-app
tests/         Pengujian unit, keamanan, HTTP, dan CLI
docs/          Panduan, arsitektur, keamanan, roadmap
benchmarks/    Microbenchmark dan penjelasan metodenya
.github/       Konfigurasi GitHub, termasuk CI
```

## Konfigurasi dan batasan

- `ORKU_BASE_PATH=/demo`: prefix URL contoh web; konfigurasi server harus sesuai.
- `ORKU_DEBUG=1`: diagnostik pengembangan pada contoh web. Jangan aktifkan di
  lingkungan publik karena detail exception dapat terekspos.
- `ORKU_HTTP_DEV=1`: cookie tanpa flag Secure untuk HTTP lokal saja. Jangan
  gunakan di produksi. Server CLI menangani kebutuhan HTTP lokal secara otomatis.
- Template PHP adalah kode tepercaya. Output tidak otomatis di-escape; gunakan
  `escape()` untuk teks/atribut dinamis dan `url()` untuk URL.
- Untuk deployment, document root harus menunjuk direktori `public` aplikasi,
  **bukan root repositori**. Baca [panduan keamanan](docs/security.md).

## Pemeriksaan sebelum kontribusi

```bash
composer validate --strict
composer check
```

`composer check` menjalankan PHPUnit, PHPStan, dan pemeriksaan format tanpa
mengubah file. Pengujian integrasi membutuhkan pembuatan proses dan socket TCP
lokal. Sertakan pengujian untuk perubahan perilaku sebelum membuat pull request.

Bagian berikut menyediakan referensi API dan contoh HTTP dalam Bahasa Inggris.

Web v0.2 development milestone. Small HTTP kernel and optional PHP-native SSR for PHP 8.4+ with no third-party
runtime packages. Not a stable release or a claim of production readiness.

## Runtime overview

Frontend demo: `php bin/orku serve fullstack-app 127.0.0.1:8000` after install.
Home, About, Components, Feedback and safe HTML errors work without JavaScript.
Feedback demonstrates validation and CSRF, but is not stored or sent. Native
`ext-session` and private writable session storage are needed only for form routes.
Read-only pages/API stay stateless. No Node.js, database, hydration or compiler.

```php
$views = new Orku\Web\ViewRenderer([__DIR__.'/resources/views']);
return Orku\Http\Response::html($views->render('pages.home', ['title' => 'Welcome']));
```

Templates use `<?= $view->escape($view->get('title')) ?>`. PHP templates are
trusted code; arbitrary echo is not auto-escaped. See
[Web getting started](docs/web-getting-started.md),
[architecture](docs/web-architecture.md), [security](docs/web-security.md),
[measurements](docs/web-performance.md) and [verification](docs/verification.md).

Requires PHP 8.4+ and Composer 2. Development tools also require DOM, XML,
XMLWriter and mbstring extensions. Tests need process creation and local TCP
sockets. PHP's CLI server is for local development only.

```bash
git clone https://github.com/kenzieradhika/Orku.git
cd Orku
composer install
php bin/orku serve hello-world 127.0.0.1:8000
```

In another terminal:

```bash
curl -i http://127.0.0.1:8000/
curl -i http://127.0.0.1:8000/api/status
curl -i http://127.0.0.1:8000/hello/Ada
curl -i http://127.0.0.1:8000/missing
curl -I http://127.0.0.1:8000/
```

Expected: text `Hello, Orku!`, JSON `{"framework":"Orku","status":"running"}`,
text `Hello, Ada!`, HTTP 404, and a bodyless HTTP 200 HEAD response.

No CLI wrapper required:

```bash
php -d display_errors=0 -d enable_post_data_reading=0 -S 127.0.0.1:8000 -t examples/hello-world/public examples/hello-world/public/index.php
```

## JSON API

```bash
php bin/orku serve rest-api 127.0.0.1:8001
```

```bash
curl -i http://127.0.0.1:8001/api/v1/users/42
curl -i -X POST http://127.0.0.1:8001/api/v1/greetings \
  -H 'Content-Type: application/json' -d '{"name":"Ada"}'
php bin/orku routes rest-api
```

POST returns HTTP 201 and `{"message":"Hello, Ada!"}`. Blank, non-string or
over-100-byte names return 422. Malformed JSON returns 400, wrong content type
415, bodies over 1 MiB 413. This API example validates inline. Web adds small
form validation helpers; authentication, storage, rate limiting and middleware
remain unimplemented.

## Application API

`examples/hello-world/app.php` returns a real `Orku\Routing\Router`:

```php
use Orku\Http\Request;
use Orku\Http\Response;
use Orku\Routing\Router;

$router = new Router();
$router->get('/api/status', function (): Response {
    return Response::json(['framework' => 'Orku', 'status' => 'running']);
});
$router->get('/users/{id}', function (Request $request): Response {
    return Response::json(['id' => $request->routeParameters['id']]);
});
return $router;
```

Handlers receive Request and must return Response. Zero-argument closures work
too. Request exposes `method`, decoded `path`, case-insensitive `header($name)`,
`query`, raw `body`, `cookies`, `routeParameters` and `json()`. JSON decoding is
explicit and accepts `application/json` or `application/*+json`; JSON `null` is valid.

Router supports `get`, `post`, `put`, `patch`, `delete` and `add('HEAD', ...)`.
Parameters occupy whole segments: `/teams/{team}/users/{user}`. Static routes
take precedence; dynamic matches use registration order. Wrong method gives 405
with `Allow`; unknown path gives 404. HEAD falls back to GET; SAPI suppresses its
body. No automatic OPTIONS or CORS policy. Trailing slashes and path case matter.

Response supports `text`, `json`, `html`, `redirect`, or explicit
`new Response($body, $status, $headers)`. Headers use one string per field;
multiple Set-Cookie headers are not yet supported. Runtime owns HTTP framing.
`Response::html()` accepts **trusted HTML only**, it does not escape or template
data. Use optional `Orku\Web\ViewRenderer` for templates, not a magic response helper.

## Errors and runtime

`new Kernel($router)` defaults to production-safe JSON errors and logs 5xx
exceptions with `error_log`. To integrate logging, pass
`report: function (Throwable $error): void { /* your logger */ }`.
For trusted local development only, use `debug: true`; this exposes exception
details and stack traces. Do not enable it in public environments.

`SapiRuntime::run($kernel, maxBodyBytes: 1048576)` captures PHP globals, buffers
and discards accidental handler output, and emits Response. The body limit is
configurable; set equal or lower upstream limits too. Additional security header
defaults can be passed as `securityHeaders`; explicit Response headers override
defaults. See [security considerations](docs/security.md) before deployment.

## Checks

```bash
composer validate --strict
composer check
php benchmarks/kernel.php 10000
```

`composer check` runs PHPUnit, PHPStan at maximum level and formatting check.
Tests include live HTTP requests to all three examples, CLI validation and security
regressions. CI targets PHP 8.4 and 8.5. Benchmark is an in-process microbenchmark,
not HTTP throughput or a comparison with another framework.

## Optional dependency container

Explicit-factory scoped DI is available without changing the HTTP kernel.
Run `php examples/container.php`; see [container contracts and limitations](docs/scoped-di-rfc.md).
Supported lifetimes: singleton, request, transient. Autowiring and worker scopes
are not implemented. Always close request scopes in `finally`.

## Status

Optional ordered [HTTP middleware](docs/middleware.md) is supported through the
last `Kernel` constructor argument, with short-circuit responses and safe errors.

The [canonical implementation-status table](docs/roadmap.md) separates shipped
features from proposals. [ORK.md](ORK.md) records the staged modernization plan,
including optional Loom `.ork` templates and ThinkCore. Neither is implemented yet.

| Capability | Status |
| --- | --- |
| Immutable HTTP values, kernel, SAPI adapter, JSON errors | Implemented |
| Static/dynamic routing, GET/POST/PUT/PATCH/DELETE, HEAD | Implemented |
| Local `serve`, `routes`, `help`; three runnable examples | Implemented |
| Unit/security/live HTTP tests, analysis, formatting, CI config | Implemented |
| SSR layouts/components, explicit escaping, static assets | Implemented v0.2 |
| URL-encoded forms, typed validation, lazy session-backed CSRF | Implemented v0.2 |
| Explicit-factory scoped DI and deterministic cleanup | Implemented, optional |
| Ordered callable middleware, short-circuit responses | Implemented, optional |
| Named routes, nested prefix groups, safe URL generation | Implemented |
| Read-only CLI Doctor runtime diagnostics | Implemented, limited to current process |
| Autowiring, generators | Planned v0.3 |
| Optional PDO prepared-query/transaction foundation | Implemented, SQLite tested; see [data](docs/data.md) |
| Standalone database package, migrations, worker adapter, compiled routing | Planned later |
| Framework comparisons and performance guarantees | Not available |
| Running full PHP framework on ESP32/ESP8266 | Unsupported |

Full-stack example ships with v0.2. PHP-FPM/Apache deployment targets SAPI,
but only Linux x86_64 CLI-server HTTP behavior is currently integration-tested.

[Architecture](docs/architecture.md) · [Security](docs/security.md) ·
[Roadmap and limitations](docs/roadmap.md) · [Benchmark method](benchmarks/README.md) ·
[Local verification](docs/verification.md)

MIT licensed. No stable 1.0 compatibility promise yet.
