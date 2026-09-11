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

use Milpa\Live\Components\CodeBlockComponent;
use Milpa\Live\Rendering\CodeBlockHtmlRenderer;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\RenderTarget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A COMMAND IS A COMPONENT NOW — measured absent from the catalogue before it was written.
 *
 * `components:catalogue` listed seventeen components and not one for code, so every page that showed a
 * command wrote its own `<pre>` — and the framework's welcome page picked six hex values doing it, two
 * of them invisible (greenhouse decisions/0298, 0299).
 */
#[CoversClass(CodeBlockComponent::class)]
#[CoversClass(CodeBlockHtmlRenderer::class)]
final class ACodeBlockIsAComponentTest extends TestCase
{
    /** @param array<string, mixed> $props */
    private static function render(array $props): string
    {
        $component = new CodeBlockComponent();
        $context = new ComponentContext(componentId: 'cb1');

        return (new CodeBlockHtmlRenderer())->render($component, new RenderRequest(
            context: $context,
            props: $props,
            state: $component->mount($props, $context),
            target: RenderTarget::HTML,
        ))->output;
    }

    /** The chrome, the prompt and the line — the three things that say «terminal» without a colour. */
    public function testItPaintsChromePromptedLinesAndTheCommand(): void
    {
        $html = self::render(['command' => 'php bin/coa house:start', 'label' => 'terminal']);

        self::assertStringContainsString('data-milpa-component="code-block"', $html);
        self::assertStringContainsString('milpa-code__chrome', $html);
        self::assertStringContainsString('>terminal<', $html, 'the block says what it is');
        self::assertStringContainsString('milpa-code__prompt', $html);
        self::assertStringContainsString('php bin/coa house:start', $html);
    }

    /** Each line gets its own prompt, and blank lines are not rows. */
    public function testEachLineIsItsOwnRowAndBlanksAreDropped(): void
    {
        $html = self::render(['command' => "php bin/coa list\n\nphp bin/coa plugins:list\n"]);

        self::assertSame(2, substr_count($html, 'milpa-code__line'), 'two commands, two rows');
        self::assertSame(2, substr_count($html, 'milpa-code__prompt'), 'each with its own prompt');
    }

    /**
     * 🚨 THE CLIPBOARD CARRIES THE COMMANDS AND NEVER THE PROMPT.
     *
     * A `$` pasted into a shell is the one error this affordance exists to prevent, and it is the error
     * every hand-rolled copy button makes: reading `textContent` off the block takes the prompt with
     * it. The renderer puts the payload in the attribute, so the script never has to look at the DOM.
     */
    public function testTheCopyPayloadIsTheCommandsWithoutThePrompt(): void
    {
        $html = self::render(['command' => "php bin/coa list\nphp bin/coa serve", 'prompt' => '$']);

        preg_match('/data-milpa-copy="([^"]*)"/', $html, $found);

        self::assertNotEmpty($found);
        $payload = html_entity_decode($found[1], \ENT_QUOTES);
        self::assertSame("php bin/coa list\nphp bin/coa serve", $payload);
        self::assertStringNotContainsString('$', $payload, 'the prompt is decoration, not content');
    }

    /** A prompt can be turned off, because `$` on a URL is a lie about what the reader sees. */
    public function testAnEmptyPromptPaintsNoPrompt(): void
    {
        $html = self::render(['command' => 'https://milpa.lat', 'prompt' => '']);

        self::assertStringNotContainsString('milpa-code__prompt', $html);
        self::assertStringContainsString('https://milpa.lat', $html);
    }

    /** Output nobody would paste can say so, and then there is no button at all. */
    public function testCopyCanBeDeclinedAndThenNoButtonIsPrinted(): void
    {
        $html = self::render(['command' => 'ok: sí', 'copy' => false]);

        self::assertStringNotContainsString('milpa-code__copy', $html);
        self::assertStringNotContainsString('data-milpa-copy', $html);
    }

    /** An empty command mounts zero lines rather than one blank prompt. */
    public function testAnEmptyCommandIsNotAnEmptyTerminalRow(): void
    {
        $component = new CodeBlockComponent();
        $state = $component->mount(['command' => ''], new ComponentContext(componentId: 'cb1'));

        self::assertSame(0, $state->data['lines']);
        self::assertStringNotContainsString('milpa-code__line', self::render(['command' => '']));
        self::assertStringNotContainsString('milpa-code__copy', self::render(['command' => '']), 'nothing to take');
    }

    /**
     * 🚨 THE BUTTON IS IN THE SERVER'S MARKUP, and its script only teaches it.
     *
     * A button a script injects does not exist until the script runs, and a page whose script failed
     * then shows chrome with nothing behind it. This house has measured that twice — a Save that was
     * never wired, and an enrol ceremony that lived in a heredoc nobody parsed.
     */
    public function testTheButtonAndItsAssetsAreDeclaredNotInjected(): void
    {
        $html = self::render(['command' => 'php bin/coa serve']);

        self::assertStringContainsString('<button type="button" class="milpa-code__copy"', $html);
        self::assertStringContainsString('aria-label="Copy terminal"', $html, 'reachable without sight of the icon');

        $presentation = CodeBlockComponent::contract()->presentation;
        self::assertNotNull($presentation);
        self::assertIsString($presentation->styles);
        self::assertIsString($presentation->script);
        self::assertFileExists($presentation->styles);
        self::assertFileExists($presentation->script);

        $css = (string) file_get_contents($presentation->styles);
        self::assertSame(0, preg_match('/#[0-9a-fA-F]{3,6}\b/', $css), 'not one colour of its own: every value is a design token');
        self::assertStringContainsString(':focus-visible', $css, 'the copy affordance is reachable by keyboard, not only on hover');
        self::assertStringContainsString('prefers-reduced-motion', $css);
    }

    /** HTML only, and it refuses to paint a component that is not its own. */
    public function testItRendersHtmlAndOnlyItsOwnContract(): void
    {
        $renderer = new CodeBlockHtmlRenderer();

        self::assertTrue($renderer->supportsTarget(RenderTarget::HTML));
        self::assertFalse($renderer->supportsTarget(RenderTarget::TUI));

        $this->expectException(\InvalidArgumentException::class);
        $renderer->render(new \Milpa\Live\Components\BrandMarkComponent(), new RenderRequest(
            context: new ComponentContext(componentId: 'x'),
            props: [],
            target: RenderTarget::HTML,
        ));
    }
}
