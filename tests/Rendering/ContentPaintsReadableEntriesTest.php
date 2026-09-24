<?php

/**
 * This file is part of milpa/live-web.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/live-web
 */

declare(strict_types=1);

namespace Milpa\Live\Tests\Rendering;

use Milpa\Live\Components\ContentComponent;
use Milpa\Live\Components\InvalidComponentProps;
use Milpa\Live\Rendering\ContentHtmlRenderer;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\RenderTarget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The smallest declaration that paints narrative content (greenhouse decisions/0464): rows and the
 * ROLES that read them. The component never learns what kind of row it is painting, and the proof is a
 * second kind of row, with different field names, painted by the same code.
 *
 * @guards the article structure, escaping, paragraphs, the catalogue's empty state, and named refusals
 *
 * @refuses roles without title or body, an unknown role, and a role naming a field the rows lack
 *
 * @subject-in milpa/live-web
 */
#[CoversClass(ContentComponent::class)]
#[CoversClass(ContentHtmlRenderer::class)]
final class ContentPaintsReadableEntriesTest extends TestCase
{
    /** @param array<string, mixed> $props */
    private static function render(array $props, string $locale = 'en'): string
    {
        $component = new ContentComponent();
        $context = new ComponentContext(componentId: 'c1');

        return (new ContentHtmlRenderer($locale))->render($component, new RenderRequest(
            context: $context,
            props: $props,
            state: $component->mount($props, $context),
            target: RenderTarget::HTML,
        ))->output;
    }

    public function testEachRowIsAnArticleReadThroughItsRoles(): void
    {
        $html = self::render([
            'roles' => ['title' => 'title', 'lead' => 'summary', 'body' => 'text', 'meta' => ['author']],
            'rows' => [[
                'title' => 'First light',
                'summary' => 'A short lead.',
                'text' => "One paragraph\nwith a kept line.\n\nA second paragraph.",
                'author' => 'Ana',
                'secret' => 'never named, never painted',
            ]],
        ]);

        self::assertStringContainsString('<article class="entry"><h2 class="title">First light</h2>', $html);
        self::assertStringContainsString('<p class="lead">A short lead.</p>', $html);
        self::assertStringContainsString('<p>One paragraph<br>with a kept line.</p><p>A second paragraph.</p>', $html);
        self::assertStringContainsString('<dt>Author</dt><dd>Ana</dd>', $html);
        self::assertStringNotContainsString('never named', $html, 'a field no role names is not painted');
    }

    public function testATitleOrBodyAStrangerTypedIsOnlyText(): void
    {
        $html = self::render([
            'roles' => ['title' => 'title', 'body' => 'body'],
            'rows' => [['title' => "<script>alert('x')</script>", 'body' => '<img src=x onerror=alert(1)>']],
        ]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
    }

    public function testASecondKindOfRowNeedsNoNewCode(): void
    {
        // THE FALSIFIER OF 0464: different fields, different meaning, the same component.
        $html = self::render([
            'heading' => 'Notices',
            'roles' => ['title' => 'headline', 'body' => 'message', 'meta' => ['audience']],
            'rows' => [['headline' => 'Maintenance tonight', 'message' => 'The service pauses at 22:00.', 'audience' => 'everyone']],
        ]);

        self::assertStringContainsString('<h2 class="heading">Notices</h2>', $html);
        self::assertStringContainsString('<h3 class="title">Maintenance tonight</h3>', $html, 'entries sit one level under a heading');
        self::assertStringContainsString('<p>The service pauses at 22:00.</p>', $html);
        self::assertStringContainsString('<dt>Audience</dt><dd>everyone</dd>', $html);
    }

    public function testNothingToReadSaysSoInThePagesLanguage(): void
    {
        $roles = ['title' => 'title', 'body' => 'body'];

        self::assertStringContainsString('<p class="empty">Nothing to read yet.</p>', self::render(['roles' => $roles, 'rows' => []]));
        self::assertStringContainsString('<p class="empty">Todavía no hay nada que leer.</p>', self::render(['roles' => $roles, 'rows' => []], 'es'));
    }

    public function testADeclarationThatCannotBeReadIsRefusedByName(): void
    {
        $cases = [
            'props.roles' => ['roles' => ['title', 'body']],
            'props.roles.body' => ['roles' => ['title' => 'title']],
            'props.roles.date' => ['roles' => ['title' => 'title', 'body' => 'body', 'date' => 'at']],
            'props.rows.0' => ['roles' => ['title' => 'title', 'body' => 'content'], 'rows' => [['title' => 'x', 'body' => 'y']]],
        ];
        foreach ($cases as $path => $props) {
            try {
                self::render($props);
                self::fail("{$path} should have been refused");
            } catch (InvalidComponentProps $refused) {
                self::assertSame($path, $refused->path);
            }
        }

        try {
            self::render($cases['props.rows.0']);
        } catch (InvalidComponentProps $refused) {
            self::assertStringContainsString('«content»', $refused->getMessage(), 'it names the missing field');
            self::assertStringContainsString('title, body', $refused->getMessage(), 'and what the entry does carry');
        }
    }

    public function testTheStylesheetIsTokensOnAHostRoot(): void
    {
        $css = (string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents((string) ContentComponent::contract()->presentation?->styles));

        self::assertStringContainsString(':host {', $css);
        self::assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b/', $css, 'every colour is a token');
        self::assertStringStartsWith(
            '<section data-milpa-component="content" data-milpa-component-id="c1">',
            self::render(['roles' => ['title' => 't', 'body' => 'b'], 'rows' => []]),
            'no class on the root: the stylesheet reaches it through :host',
        );
    }

    public function testThePrimitiveNeverLearnsWhatItIsPainting(): void
    {
        // «If supporting a second entity needs `if ($entity instanceof Post)` — red» (Rod, 0464). The
        // cheapest form of that defect is a word: a blog noun in the primitive is the first step to one.
        $files = [
            \dirname(__DIR__, 2) . '/src/Components/ContentComponent.php',
            \dirname(__DIR__, 2) . '/src/Rendering/ContentHtmlRenderer.php',
            \dirname(__DIR__, 2) . '/resources/components/content.css',
            \dirname(__DIR__, 2) . '/resources/messages/content.php',
        ];
        foreach ($files as $file) {
            self::assertDoesNotMatchRegularExpression('/\b(posts?|blog|published)\b/i', (string) file_get_contents($file), basename($file));
        }
    }
}
