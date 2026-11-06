<?php

declare(strict_types=1);

namespace Orku\Web;

use Orku\Http\HttpException;
use Orku\Http\Request;
use Orku\Http\Response;

final readonly class AssetManager
{
    private string $root;
    private const array MIME = ['css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'ico' => 'image/vnd.microsoft.icon', 'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'avif' => 'image/avif'];

    public function __construct(string $directory, private string $prefix = '/assets')
    {
        $root = realpath($directory);
        if ($root === false || !is_dir($root) || !preg_match('~^/[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*$~D', $prefix)) {
            throw new \InvalidArgumentException('Invalid asset configuration.');
        }
        $this->root = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
    }

    public function url(string $path): string
    {
        $this->resolve($path);

        return $this->prefix.'/'.implode('/', array_map(rawurlencode(...), explode('/', $path)));
    }

    public function serve(Request $request): Response
    {
        if (!in_array($request->method, ['GET', 'HEAD'], true)) {
            throw new HttpException(405, headers: ['allow' => 'GET, HEAD']);
        }
        if (!str_starts_with($request->path, $this->prefix.'/')) {
            throw new HttpException(404);
        }
        try {
            $path = $this->resolve(substr($request->path, strlen($this->prefix) + 1));
        } catch (\InvalidArgumentException) {
            throw new HttpException(404);
        }
        $body = file_get_contents($path);
        if ($body === false) {
            throw new \RuntimeException('Cannot read static asset.');
        }
        $etag = '"'.hash('sha256', $body).'"';
        $headers = ['content-type' => self::MIME[strtolower(pathinfo($path, PATHINFO_EXTENSION))], 'cache-control' => 'public, max-age=300', 'etag' => $etag, 'x-content-type-options' => 'nosniff'];
        foreach (explode(',', $request->header('if-none-match') ?? '') as $candidate) {
            if (in_array(trim($candidate), [$etag, 'W/'.$etag, '*'], true)) {
                return new Response('', 304, $headers);
            }
        }

        return new Response($body, 200, $headers);
    }

    private function resolve(string $path): string
    {
        if (strlen($path) > 240 || !preg_match('~^[a-zA-Z0-9_-][a-zA-Z0-9_./-]*$~D', $path) || in_array('..', explode('/', $path), true) || in_array('.', explode('/', $path), true) || !isset(self::MIME[strtolower(pathinfo($path, PATHINFO_EXTENSION))])) {
            throw new \InvalidArgumentException('Invalid asset path.');
        }
        $file = realpath($this->root.$path);
        if ($file === false || !str_starts_with($file, $this->root) || !is_file($file) || !is_readable($file) || !isset(self::MIME[strtolower(pathinfo($file, PATHINFO_EXTENSION))])) {
            throw new \InvalidArgumentException('Asset not found.');
        }

        return $file;
    }
}
