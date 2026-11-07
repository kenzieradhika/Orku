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
            if (is_dir($file)) {
                foreach (glob($file.'/*') ?: [] as $child) {
                    unlink($child);
                }
                rmdir($file);
            } else {
                unlink($file);
            }
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

    public function testPartialRendersWithCallerDataAndEscaping(): void
    {
        file_put_contents($this->root.'/page.ork', '<h1>{% include "partials.title" %}</h1>');
        mkdir($this->root.'/partials');
        file_put_contents($this->root.'/partials/title.ork', '{{ title }}');
        self::assertSame('<h1>&lt;Hello&gt;</h1>', (new LoomRenderer($this->root))->render('page', ['title' => '<Hello>']));
    }

    public function testPartialCycleFailsDeterministically(): void
    {
        file_put_contents($this->root.'/page.ork', '{% include "partials.one" %}');
        mkdir($this->root.'/partials');
        file_put_contents($this->root.'/partials/one.ork', '{% include "partials.two" %}');
        file_put_contents($this->root.'/partials/two.ork', '{% include "partials.one" %}');
        try {
            (new LoomRenderer($this->root))->render('page');
            self::fail('Expected cyclic partial error.');
        } catch (LoomException $error) {
            self::assertSame('Cyclic template reference.', $error->getMessage());
        }
    }

    public function testPartialTraversalAndUnknownDirectiveFail(): void
    {
        file_put_contents($this->root.'/page.ork', '{% include "../outside" %}');
        try {
            (new LoomRenderer($this->root))->render('page');
            self::fail('Expected invalid partial name error.');
        } catch (LoomException $error) {
            self::assertSame('Unsupported template directive.', $error->getMessage());
        }
    }

    public function testPartialCannotEscapeRootThroughSymlink(): void
    {
        $outside = $this->root.'-outside.ork';
        file_put_contents($outside, 'outside');
        symlink($outside, $this->root.'/linked.ork');
        file_put_contents($this->root.'/page.ork', '{% include "linked" %}');
        try {
            $this->expectException(LoomException::class);
            (new LoomRenderer($this->root))->render('page');
        } finally {
            unlink($this->root.'/linked.ork');
            unlink($outside);
        }
    }

    public function testPartialKeepsHtmlParserContextAndRejectsAttributeUse(): void
    {
        file_put_contents($this->root.'/page.ork', '<a title="{% include "partial" %}">x</a>');
        file_put_contents($this->root.'/partial.ork', '{{ value }}');
        $this->expectException(LoomException::class);
        (new LoomRenderer($this->root))->render('page', ['value' => 'x']);
    }

    public function testPartialDirectiveIsRejectedInsideHtmlComment(): void
    {
        file_put_contents($this->root.'/page.ork', '<!-- {% include "partial" %} -->');
        file_put_contents($this->root.'/partial.ork', 'included');
        $this->expectException(LoomException::class);
        (new LoomRenderer($this->root))->render('page');
    }

    public function testComponentBindsDeclaredPropertiesAndSlot(): void
    {
        mkdir($this->root.'/components');
        file_put_contents($this->root.'/components/card.ork', "{% props title %}<article><h2>{{ title }}</h2>{% slot content %}{% endslot %}</article>");
        file_put_contents($this->root.'/page.ork', '{% component "card" title=page.title %}<p>{{ page.body }}</p>{% endcomponent %}');
        self::assertSame('<article><h2>&lt;Hi&gt;</h2><p>&lt;body&gt;</p></article>', (new LoomRenderer($this->root))->render('page', ['page' => ['title' => '<Hi>', 'body' => '<body>']]));
    }

    public function testComponentPropertyAndSlotBlockXssPayloads(): void
    {
        mkdir($this->root.'/components');
        file_put_contents($this->root.'/components/card.ork', '{% props title %}<h2>{{ title }}</h2>{% slot content %}{% endslot %}');
        file_put_contents($this->root.'/page.ork', '{% component "card" title=payload %}{{ payload }}{% endcomponent %}');
        $payload = '<script>alert(1)</script>';
        self::assertSame('<h2>&lt;script&gt;alert(1)&lt;/script&gt;</h2>&lt;script&gt;alert(1)&lt;/script&gt;', (new LoomRenderer($this->root))->render('page', ['payload' => $payload]));
    }

    public function testPropertyCannotSpoofComponentSlotInsertionMarker(): void
    {
        mkdir($this->root.'/components');
        file_put_contents($this->root.'/components/card.ork', "{% props title %}{{ title }}{% slot content %}{% endslot %}");
        file_put_contents($this->root.'/page.ork', "{% component \"card\" title=payload %}<i>slot</i>{% endcomponent %}");
        $payload = "\0ORKU_COMPONENT_SLOT\0";
        self::assertSame($payload.'<i>slot</i>', (new LoomRenderer($this->root))->render('page', ['payload' => $payload]));
    }

    public function testComponentCanOmitOptionalSlot(): void
    {
        mkdir($this->root.'/components');
        file_put_contents($this->root.'/components/card.ork', '{% props title %}<h2>{{ title }}</h2>{% slot content %}fallback{% endslot %}');
        file_put_contents($this->root.'/page.ork', '{% component "card" title=title %}{% endcomponent %}');
        self::assertSame('<h2>ok</h2>', (new LoomRenderer($this->root))->render('page', ['title' => 'ok']));
    }

    public function testComponentRejectsCallerSlotWithoutSlotDeclaration(): void
    {
        mkdir($this->root.'/components');
        file_put_contents($this->root.'/components/card.ork', '{% props %}<hr>');
        file_put_contents($this->root.'/page.ork', '{% component "card" %}caller data{% endcomponent %}');
        $this->expectException(LoomException::class);
        $this->expectExceptionMessage('Component does not accept content.');
        (new LoomRenderer($this->root))->render('page');
    }

    public function testComponentDefinitionRequiresPropsDirective(): void
    {
        mkdir($this->root.'/components');
        file_put_contents($this->root.'/components/card.ork', '<hr>');
        file_put_contents($this->root.'/page.ork', '{% component "card" %}{% endcomponent %}');
        $this->expectException(LoomException::class);
        $this->expectExceptionMessage('Component properties declaration required.');
        (new LoomRenderer($this->root))->render('page');
    }

    public function testComponentPropertyScopeIsIsolated(): void
    {
        mkdir($this->root.'/components');
        file_put_contents($this->root.'/components/card.ork', '{% props title %}{{ title }}{{ secret }}');
        file_put_contents($this->root.'/page.ork', '{% component "card" title=title %}{% endcomponent %}');
        $this->expectException(LoomException::class);
        (new LoomRenderer($this->root))->render('page', ['title' => 'ok', 'secret' => 'leak']);
    }

    public function testComponentCannotReadCallerVariableOutsideDeclaredProperties(): void
    {
        mkdir($this->root.'/components');
        file_put_contents($this->root.'/components/card.ork', '{% props heading %}{{ heading }}{{ title }}');
        file_put_contents($this->root.'/page.ork', '{% component "card" heading=title %}{% endcomponent %}');
        $this->expectException(LoomException::class);
        $this->expectExceptionMessage('Missing template value.');
        (new LoomRenderer($this->root))->render('page', ['title' => 'caller-only']);
    }

    public function testNestedComponentsRenderAndEscape(): void
    {
        mkdir($this->root.'/components');
        file_put_contents($this->root.'/components/outer.ork', '{% props label %}<section>{{ label }}{% component "inner" value=label %}{% endcomponent %}</section>');
        file_put_contents($this->root.'/components/inner.ork', '{% props value %}<b>{{ value }}</b>');
        file_put_contents($this->root.'/page.ork', '{% component "outer" label=input %}{% endcomponent %}');
        self::assertSame('<section>&lt;x&gt;<b>&lt;x&gt;</b></section>', (new LoomRenderer($this->root))->render('page', ['input' => '<x>']));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidComponentInvocations')]
    public function testInvalidComponentInvocationFails(string $invocation, string $message): void
    {
        mkdir($this->root.'/components');
        file_put_contents($this->root.'/components/card.ork', '{% props title %}{{ title }}');
        file_put_contents($this->root.'/page.ork', $invocation);
        try {
            (new LoomRenderer($this->root))->render('page', ['title' => 'ok']);
            self::fail('Expected invalid component invocation.');
        } catch (LoomException $error) {
            self::assertSame($message, $error->getMessage());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidComponentInvocations(): iterable
    {
        yield 'missing required property' => ['{% component "card" %}{% endcomponent %}', 'Missing component property.'];
        yield 'unknown property' => ['{% component "card" title=title other=title %}{% endcomponent %}', 'Unknown component property.'];
        yield 'duplicate property' => ['{% component "card" title=title title=title %}{% endcomponent %}', 'Duplicate component property.'];
        yield 'invalid expression' => ['{% component "card" title=call() %}{% endcomponent %}', 'Invalid component property expression.'];
        yield 'empty path segment' => ['{% component "card" title=page..title %}{% endcomponent %}', 'Invalid component property expression.'];
        yield 'bad name' => ['{% component "../card" title=title %}{% endcomponent %}', 'Invalid component name.'];
        yield 'missing component file' => ['{% component "missing" %}{% endcomponent %}', 'Component not found.'];
        yield 'malformed' => ['{% component "card" title=title %}unfinished', 'Unclosed component block.'];
    }

    public function testComponentCycleFailsAndPathCannotEscapeRoot(): void
    {
        mkdir($this->root.'/components');
        file_put_contents($this->root.'/components/loop.ork', '{% props %}{% component "loop" %}{% endcomponent %}');
        file_put_contents($this->root.'/page.ork', '{% component "loop" %}{% endcomponent %}');
        try {
            (new LoomRenderer($this->root))->render('page');
            self::fail('Expected recursive component failure.');
        } catch (LoomException $error) {
            self::assertSame('Cyclic component reference.', $error->getMessage());
        }
        file_put_contents($this->root.'/page.ork', '{% component "../outside" %}{% endcomponent %}');
        $this->expectException(LoomException::class);
        (new LoomRenderer($this->root))->render('page');
    }

    public function testComponentDirectiveIsRejectedInHtmlAttribute(): void
    {
        mkdir($this->root.'/components');
        file_put_contents($this->root.'/components/card.ork', 'safe');
        file_put_contents($this->root.'/page.ork', '<p title="{% component "card" %}{% endcomponent %}">x</p>');
        $this->expectException(LoomException::class);
        (new LoomRenderer($this->root))->render('page');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeComponentContexts')]
    public function testComponentDirectiveIsRejectedInUnsafeContexts(string $template): void
    {
        mkdir($this->root.'/components');
        file_put_contents($this->root.'/components/card.ork', '{% props %}safe');
        file_put_contents($this->root.'/page.ork', $template);
        $this->expectException(LoomException::class);
        (new LoomRenderer($this->root))->render('page');
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeComponentContexts(): iterable
    {
        yield 'comment' => ['<!-- {% component "card" %}{% endcomponent %} -->'];
        yield 'script' => ['<script>{% component "card" %}{% endcomponent %}</script>'];
        yield 'style' => ['<style>{% component "card" %}{% endcomponent %}</style>'];
    }

    public function testComponentSlotMustBeDeclaredAtMostOnce(): void
    {
        mkdir($this->root.'/components');
        file_put_contents($this->root.'/components/card.ork', '{% props %}{% slot content %}a{% endslot %}{% slot content %}b{% endslot %}');
        file_put_contents($this->root.'/page.ork', '{% component "card" %}text{% endcomponent %}');
        $this->expectException(LoomException::class);
        $this->expectExceptionMessage('Invalid component content slot.');
        (new LoomRenderer($this->root))->render('page');
    }

    public function testComponentPropertiesRejectDuplicateDeclarationsAndNonScalarValues(): void
    {
        mkdir($this->root.'/components');
        file_put_contents($this->root.'/components/card.ork', '{% props title title %}{{ title }}');
        file_put_contents($this->root.'/page.ork', '{% component "card" title=input %}{% endcomponent %}');
        try {
            (new LoomRenderer($this->root))->render('page', ['input' => 'ok']);
            self::fail('Expected duplicate declaration error.');
        } catch (LoomException $error) {
            self::assertSame('Duplicate component property declaration.', $error->getMessage());
        }
        file_put_contents($this->root.'/components/card.ork', '{% props title %}{{ title }}');
        $this->expectException(LoomException::class);
        (new LoomRenderer($this->root))->render('page', ['input' => ['bad']]);
    }

    public function testComponentNestingDepthIsBounded(): void
    {
        mkdir($this->root.'/components');
        for ($index = 0; $index <= 32; ++$index) {
            $source = $index === 32 ? '{% props %}end' : '{% props %}{% component "deep'.($index + 1).'" %}{% endcomponent %}';
            file_put_contents($this->root.'/components/deep'.$index.'.ork', $source);
        }
        file_put_contents($this->root.'/page.ork', '{% component "deep0" %}{% endcomponent %}');
        $this->expectException(LoomException::class);
        $this->expectExceptionMessage('Maximum component depth exceeded.');
        (new LoomRenderer($this->root))->render('page');
    }

    public function testComponentCannotEscapeRootThroughSymlink(): void
    {
        mkdir($this->root.'/components');
        $outside = $this->root.'-outside.ork';
        file_put_contents($outside, '{% props %}outside');
        symlink($outside, $this->root.'/components/linked.ork');
        file_put_contents($this->root.'/page.ork', '{% component "linked" %}{% endcomponent %}');
        try {
            $this->expectException(LoomException::class);
            (new LoomRenderer($this->root))->render('page');
        } finally {
            unlink($this->root.'/components/linked.ork');
            unlink($outside);
        }
    }

    public function testComponentWorksInsidePartialAndLayout(): void
    {
        mkdir($this->root.'/components');
        mkdir($this->root.'/partials');
        file_put_contents($this->root.'/components/label.ork', '{% props text %}<b>{{ text }}</b>');
        file_put_contents($this->root.'/partials/part.ork', '{% component "label" text=page.title %}{% endcomponent %}');
        file_put_contents($this->root.'/layout.ork', '<main>{% slot content %}{% endslot %}</main>');
        file_put_contents($this->root.'/page.ork', "{% extends \"layout\" %}{% block content %}{% include \"partials.part\" %}{% endblock %}");
        self::assertSame('<main><b>&lt;Title&gt;</b></main>', (new LoomRenderer($this->root))->render('page', ['page' => ['title' => '<Title>']]));
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

    public function testUnsupportedDirectiveInsideChildBlockFails(): void
    {
        file_put_contents($this->root.'/layout.ork', '{% slot content %}{% endslot %}');
        file_put_contents($this->root.'/page.ork', "{% extends \"layout\" %}{% block content %}{% include \"secret\" %}{% endblock %}");
        $this->expectException(LoomException::class);
        (new LoomRenderer($this->root))->render('page');
    }

    public function testUnsupportedDirectiveInParentLayoutFails(): void
    {
        file_put_contents($this->root.'/layout.ork', '{% slot content %}{% endslot %}{% include "secret" %}');
        file_put_contents($this->root.'/page.ork', "{% extends \"layout\" %}{% block content %}ok{% endblock %}");
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
