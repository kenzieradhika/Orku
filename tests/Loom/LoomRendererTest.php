<?php

declare(strict_types=1);

namespace Orku\Tests\Loom;

use Orku\Loom\LoomException;
use Orku\Loom\LoomRenderer;
use PHPUnit\Framework\TestCase;

final class LoomRendererTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/orku-loom-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->root);
    }

    public function testInterpolationEscapesHtmlAndSupportsArrayPaths(): void
    {
        file_put_contents($this->root.'/page.ork', '<p>{{ user.name }}</p>');

        self::assertSame('<p>&lt;Ada &amp; Co&gt;</p>', (new LoomRenderer($this->root))->render('page', ['user' => ['name' => '<Ada & Co>']]));
    }

    public function testBasicAndNestedInterpolation(): void
    {
        file_put_contents($this->root.'/page.ork', '{{ title }} / {{ user.profile.name }}');
        self::assertSame('hello / Ada', (new LoomRenderer($this->root))->render('page', ['title' => 'hello', 'user' => ['profile' => ['name' => 'Ada']]]));
    }

    public function testEscapesXssPayloadsAndQuotes(): void
    {
        file_put_contents($this->root.'/page.ork', '<p>{{ value }}</p>');
        $value = '<script>alert("x")</script> & \'quoted\'';
        self::assertSame('<p>&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; &#039;quoted&#039;</p>', (new LoomRenderer($this->root))->render('page', ['value' => $value]));
    }

    public function testUtf8AndInvalidUtf8AreHandled(): void
    {
        file_put_contents($this->root.'/page.ork', '{{ value }}');
        $renderer = new LoomRenderer($this->root);
        self::assertSame('你好 José', $renderer->render('page', ['value' => '你好 José']));
        self::assertSame('�', $renderer->render('page', ['value' => "\xFF"]));
    }

    public function testNullIsEmptyAndMissingValueFails(): void
    {
        file_put_contents($this->root.'/page.ork', 'before{{ value }}after');
        $renderer = new LoomRenderer($this->root);
        self::assertSame('beforeafter', $renderer->render('page', ['value' => null]));
        $this->expectException(LoomException::class);
        $renderer->render('page');
    }

    public function testNonScalarValueFails(): void
    {
        file_put_contents($this->root.'/page.ork', '{{ name }}');
        $this->expectException(LoomException::class);
        (new LoomRenderer($this->root))->render('page', ['name' => ['nested']]);
    }

    public function testRepeatedRenderingIsDeterministic(): void
    {
        file_put_contents($this->root.'/page.ork', '{{ value }}');
        $renderer = new LoomRenderer($this->root);
        self::assertSame($renderer->render('page', ['value' => 'same']), $renderer->render('page', ['value' => 'same']));
    }

    public function testChildRendersIntoNamedParentContentSlot(): void
    {
        file_put_contents($this->root.'/layout.ork', '<main>{% slot content %}{% endslot %}</main>');
        file_put_contents($this->root.'/page.ork', "{% extends \"layout\" %}{% block content %}<p>{{ name }}</p>{% endblock %}");
        self::assertSame('<main><p>&lt;Ada&gt;</p></main>', (new LoomRenderer($this->root))->render('page', ['name' => '<Ada>']));
    }

    public function testCyclicLayoutsFail(): void
    {
        file_put_contents($this->root.'/one.ork', "{% extends \"two\" %}{% block content %}one{% endblock %}");
        file_put_contents($this->root.'/two.ork', "{% extends \"one\" %}{% block content %}two{% endblock %}");
        $this->expectException(LoomException::class);
        (new LoomRenderer($this->root))->render('one');
    }

    public function testLayoutPathTraversalIsRejected(): void
    {
        file_put_contents($this->root.'/page.ork', "{% extends \"../outside\" %}{% block content %}x{% endblock %}");
        $this->expectException(LoomException::class);
        (new LoomRenderer($this->root))->render('page');
    }

    public function testLayoutSlotMustExistExactlyOnce(): void
    {
        file_put_contents($this->root.'/layout.ork', '<main>no slot</main>');
        file_put_contents($this->root.'/page.ork', "{% extends \"layout\" %}{% block content %}content{% endblock %}");
        $this->expectException(LoomException::class);
        (new LoomRenderer($this->root))->render('page');
    }

    public function testInterpolationIsRejectedOutsideHtmlText(): void
    {
        file_put_contents($this->root.'/page.ork', '<a title="{{ name }}">x</a>');

        $this->expectException(LoomException::class);
        (new LoomRenderer($this->root))->render('page', ['name' => 'value']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeContexts')]
    public function testInterpolationIsRejectedInNonTextHtmlContexts(string $template): void
    {
        file_put_contents($this->root.'/page.ork', $template);
        $this->expectException(LoomException::class);
        (new LoomRenderer($this->root))->render('page', ['value' => 'javascript:alert(1)']);
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeContexts(): iterable
    {
        yield 'script' => ['<script>const x = "{{ value }}";</script>'];
        yield 'style' => ['<style>.x { color: {{ value }} }</style>'];
        yield 'url attribute' => ['<a href="{{ value }}">link</a>'];
        yield 'comment' => ['<!-- {{ value }} -->'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidTemplates')]
    public function testMalformedAndUnsupportedTemplatesFail(string $template): void
    {
        file_put_contents($this->root.'/page.ork', $template);
        $this->expectException(LoomException::class);
        (new LoomRenderer($this->root))->render('page', ['name' => 'ok']);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidTemplates(): iterable
    {
        yield 'unclosed expression' => ['{{ name'];
        yield 'unexpected close' => ['name }}'];
        yield 'call' => ['{{ system("id") }}'];
        yield 'filter' => ['{{ name | raw }}'];
        yield 'object property' => ['{{ user->name }}'];
        yield 'php tag' => ['<?php echo "bad"; ?>'];
        yield 'unclosed tag' => ['<div'];
    }

    public function testTraversalCannotEscapeTemplateDirectory(): void
    {
        $this->expectException(LoomException::class);
        (new LoomRenderer($this->root))->render('../outside');
    }

    public function testSymlinkCannotEscapeTemplateRoot(): void
    {
        $outside = $this->root.'-outside';
        file_put_contents($outside.'.ork', 'secret');
        symlink($outside.'.ork', $this->root.'/linked.ork');
        try {
            $this->expectException(LoomException::class);
            (new LoomRenderer($this->root))->render('linked');
        } finally {
            unlink($outside.'.ork');
            unlink($this->root.'/linked.ork');
        }
    }
}
