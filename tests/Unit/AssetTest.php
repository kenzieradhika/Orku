<?php

declare(strict_types=1);

namespace Orku\Tests\Unit;

use Orku\Http\HttpException;
use Orku\Http\Request;
use Orku\Web\AssetManager;
use PHPUnit\Framework\TestCase;

final class AssetTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/orku-assets-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0700);
        file_put_contents($this->root.'/app.css', 'body {color: black}');
        file_put_contents($this->root.'/secret.php', 'secret');
        symlink(__FILE__, $this->root.'/outside.css');
        symlink($this->root.'/secret.php', $this->root.'/disguised.css');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->root);
    }

    public function testPrefixMimeCacheAndConditionalRequests(): void
    {
        $assets = new AssetManager($this->root, '/demo/assets');
        self::assertSame('/demo/assets/app.css', $assets->url('app.css'));
        $response = $assets->serve(new Request('GET', '/demo/assets/app.css'));
        self::assertSame('body {color: black}', $response->body);
        self::assertSame('text/css; charset=utf-8', $response->header('content-type'));
        self::assertSame('public, max-age=300', $response->header('cache-control'));
        $cached = $assets->serve(new Request('GET', '/demo/assets/app.css', ['If-None-Match' => 'W/'.$response->header('etag').', "other"']));
        self::assertSame(304, $cached->status);
        self::assertSame('', $cached->body);
    }

    public function testRejectsUnsafeNamesAndUnsupportedFiles(): void
    {
        $assets = new AssetManager($this->root);
        foreach (['../app.css', '/app.css', 'https://evil/app.css', 'x?y', 'a\\b', 'secret.php', 'outside.css', 'disguised.css', 'missing.css', '%2e%2e/app.css', '.env'] as $path) {
            try {
                $assets->url($path);
                self::fail($path);
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testStaticServingRejectsNonReadMethods(): void
    {
        $this->expectException(HttpException::class);
        (new AssetManager($this->root))->serve(new Request('POST', '/assets/app.css'));
    }
}
