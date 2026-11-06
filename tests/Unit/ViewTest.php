<?php

declare(strict_types=1);

namespace Orku\Tests\Unit;

use Orku\Web\TrustedHtml;
use Orku\Web\ViewRenderer;
use Orku\Web\WebException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ViewTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/orku-view-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/components', 0700, true);
        file_put_contents($this->root.'/page.php', '<h1><?= $view->escape($view->get("title")) ?></h1><?= $view->escape($view->get("nested")["name"]) ?>');
        file_put_contents($this->root.'/layout.php', '<main><?= $view->raw($view->slot("content")) ?></main><?= $view->raw($view->slot("footer")) ?>');
        file_put_contents($this->root.'/components/card.php', '<article title="<?= $view->escape($view->get("title")) ?>"><?= $view->raw($view->slot("content")) ?></article>');
        file_put_contents($this->root.'/composed.php', '<?= $view->component("card", ["title" => $view->get("title")], ["content" => Orku\Web\TrustedHtml::fromTrustedString($view->partial("page", ["title" => "inner", "nested" => ["name" => "ok"]]))]) ?>');
        file_put_contents($this->root.'/broken.php', 'partial secret<?php ob_start(); echo "nested secret"; throw new RuntimeException("private path");');
        file_put_contents($this->root.'/nested.php', 'outer secret<?= $view->partial("broken") ?>');
        file_put_contents($this->root.'/link.php', '<a href="<?= $view->url($view->get("url")) ?>">link</a>');
        file_put_contents($this->root.'/missing-prop.php', '<?= $view->escape($view->get("absent")) ?>');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/components/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->root.'/components');
        foreach (glob($this->root.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->root);
    }

    public function testEscapingNestedDataUnicodeAndInvalidUtf8(): void
    {
        $views = new ViewRenderer([$this->root]);
        self::assertSame('<h1>&lt;script&gt;&quot;&#039;&amp;</h1>José�', $views->render('page', ['title' => '<script>"\'&', 'nested' => ['name' => "José\xFF"]]));
    }

    public function testLayoutsComponentsNamedSlotsAndRepeatedIsolation(): void
    {
        $views = new ViewRenderer([$this->root]);
        for ($i = 0; $i < 5; ++$i) {
            self::assertSame('<main><article title="&quot;"><h1>inner</h1>ok</article></main><footer>safe</footer>', $views->layout('layout', 'composed', ['title' => '"'], ['footer' => TrustedHtml::fromTrustedString('<footer>safe</footer>')]));
        }
        self::assertSame('<main><h1>next</h1>ok</main>', $views->layout('layout', 'page', ['title' => 'next', 'nested' => ['name' => 'ok']]));
    }

    #[DataProvider('invalidNames')]
    public function testUnsafeOrMissingNamesFailWithoutPaths(string $name): void
    {
        try {
            (new ViewRenderer([$this->root]))->render($name);
            self::fail('Template should fail.');
        } catch (WebException $error) {
            self::assertStringNotContainsString($this->root, $error->getMessage());
        }
    }

    public static function invalidNames(): iterable
    {
        foreach (['../page', '/page', 'page.php', 'a..b', "page\0", 'a/b', 'a\\b', 'missing', '', 'x'.str_repeat('y', 256)] as $name) {
            yield [$name];
        }
    }

    public function testSymlinkCannotEscapeConfiguredRoot(): void
    {
        symlink(__FILE__, $this->root.'/outside.php');
        $this->expectException(WebException::class);
        (new ViewRenderer([$this->root]))->render('outside');
    }

    public function testNestedExceptionsRestoreBuffersAndDoNotLeakOutput(): void
    {
        $level = ob_get_level();
        ob_start();
        try {
            $views = new ViewRenderer([$this->root]);
            try {
                $views->render('nested');
                self::fail('Must throw.');
            } catch (WebException $error) {
                self::assertNotNull($error->getPrevious());
                self::assertStringNotContainsString('private path', $error->getMessage());
            }
            self::assertSame($level + 1, ob_get_level());
            self::assertSame('', ob_get_contents());
            self::assertSame('<h1>fresh</h1>ok', $views->render('page', ['title' => 'fresh', 'nested' => ['name' => 'ok']]));
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }

    #[DataProvider('unsafeUrls')]
    public function testUnsafeUrlSchemesRejected(string $url): void
    {
        $this->expectException(WebException::class);
        (new ViewRenderer([$this->root]))->render('link', ['url' => $url]);
    }

    public static function unsafeUrls(): iterable
    {
        foreach (['javascript:alert(1)', 'data:text/html,x', '//evil.test', '/\\evil', "https://x.test/\n", 'relative', '/%0aevil', '/%5cevil'] as $url) {
            yield [$url];
        }
    }

    public function testUrlEscapesAttributes(): void
    {
        self::assertSame('<a href="/about?q=&quot;&amp;x=1">link</a>', (new ViewRenderer([$this->root]))->render('link', ['url' => '/about?q="&x=1']));
    }

    public function testMissingPropFailsRatherThanLeakingWarning(): void
    {
        $this->expectException(WebException::class);
        (new ViewRenderer([$this->root]))->render('missing-prop');
    }

    public function testTypedStringPropRejectsNonString(): void
    {
        file_put_contents($this->root.'/typed.php', '<?= $view->escape($view->string("title")) ?>');
        self::assertSame('typed', (new ViewRenderer([$this->root]))->render('typed', ['title' => 'typed']));
        $this->expectException(WebException::class);
        (new ViewRenderer([$this->root]))->render('typed', ['title' => []]);
    }

    public function testNestedLayoutsAndTrustedPartialOutput(): void
    {
        file_put_contents($this->root.'/shell.php', '<body><?= $view->raw($view->slot("content")) ?></body>');
        file_put_contents($this->root.'/inner.php', '<?= $view->partial("layout", ["unused" => "value"]) ?>');
        $views = new ViewRenderer([$this->root]);
        $inner = $views->layout('layout', 'page', ['title' => 'nested', 'nested' => ['name' => 'ok']]);
        self::assertSame('<body><main><h1>nested</h1>ok</main></body>', $views->render('shell', slots: ['content' => TrustedHtml::fromTrustedString($inner)]));
        self::assertSame('<main></main>', $views->render('inner'));
    }
}
