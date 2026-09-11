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

use Milpa\Live\Assets\ComponentAssetOrchestrator;
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
    /** The stylesheet the contract names, with comments stripped — prose cannot answer for a selector. */
    private static function styles(): string
    {
        $path = CodeBlockComponent::contract()->presentation?->styles;

        return (string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents((string) $path));
    }

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

    /** The prompt and the line — what says «terminal» without a colour and without a word. */
    public function testItPaintsPromptedLinesAndTheCommand(): void
    {
        $html = self::render(['command' => 'php bin/coa house:start']);

        self::assertStringContainsString('data-milpa-component="code-block"', $html);
        self::assertStringContainsString('class="prompt"', $html);
        self::assertStringContainsString('php bin/coa house:start', $html);
    }

    /**
     * 🚨 A LABEL IS OPT-IN, AND WITHOUT ONE THE STRIP COSTS NOTHING.
     *
     * Measured on the framework's welcome page: eight blocks all labelled the identical word
     * «terminal», 43px of a 91px block — 47% — and 344px of a 2263px scroll spent on a word that
     * teaches nothing after the first one. A label is for a block that is something OTHER than a shell
     * command, which is when it earns that height. With none, the strip holds only the copy button and
     * is floated into the block's own corner, so it costs zero.
     */
    public function testALabelIsOptInAndABareStripCostsNoHeight(): void
    {
        $bare = self::render(['command' => 'php bin/coa serve']);

        self::assertStringNotContainsString('class="label"', $bare, 'no word nobody asked for');
        self::assertStringContainsString('data-bare="true"', $bare, 'the strip says it holds only the button');

        $named = self::render(['command' => 'ok: sí', 'label' => 'output', 'prompt' => '']);

        self::assertStringContainsString('>output<', $named, 'a block that is NOT a command says so');
        self::assertStringNotContainsString('data-bare', $named);
    }

    /** With neither a label nor a button there is no strip at all, rather than an empty one. */
    public function testWithNeitherLabelNorButtonThereIsNoStrip(): void
    {
        $html = self::render(['command' => 'ok: sí', 'copy' => false]);

        self::assertStringNotContainsString('class="chrome"', $html);
        self::assertStringNotContainsString('data-bare', $html);
    }

    /** Each line gets its own prompt, and blank lines are not rows. */
    public function testEachLineIsItsOwnRowAndBlanksAreDropped(): void
    {
        $html = self::render(['command' => "php bin/coa list\n\nphp bin/coa plugins:list\n"]);

        self::assertSame(2, substr_count($html, 'line'), 'two commands, two rows');
        self::assertSame(2, substr_count($html, 'prompt'), 'each with its own prompt');
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

        self::assertStringNotContainsString('prompt', $html);
        self::assertStringContainsString('https://milpa.lat', $html);
    }

    /** Output nobody would paste can say so, and then there is no button at all. */
    public function testCopyCanBeDeclinedAndThenNoButtonIsPrinted(): void
    {
        $html = self::render(['command' => 'ok: sí', 'copy' => false]);

        self::assertStringNotContainsString('copy', $html);
        self::assertStringNotContainsString('data-milpa-copy', $html);
    }

    /** An empty command mounts zero lines rather than one blank prompt. */
    public function testAnEmptyCommandIsNotAnEmptyTerminalRow(): void
    {
        $component = new CodeBlockComponent();
        $state = $component->mount(['command' => ''], new ComponentContext(componentId: 'cb1'));

        self::assertSame(0, $state->data['lines']);
        self::assertStringNotContainsString('line', self::render(['command' => '']));
        self::assertStringNotContainsString('copy', self::render(['command' => '']), 'nothing to take');
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

        self::assertStringContainsString('<button type="button" class="copy"', $html);
        self::assertStringContainsString('aria-label="Copy php bin/coa serve"', $html, 'reachable without sight of the icon');

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

    /**
     * 🚨 THE ROOT RULE SURVIVES THE SCOPER — the control for a bug that shipped.
     *
     * `CssScoper` prefixes every top-level selector with the component root, so the first version of
     * this stylesheet, which styled the block as `.milpa-code`, came out as
     * `[data-milpa-component="code-block"] .milpa-code`: a descendant of a root that carried both, so
     * the block's border, radius, ground and monospaced font applied to nothing. It rendered, it read
     * as declared, and it was inert. Reading the file could not see it; running the scoper could.
     */
    public function testTheBlocksOwnRuleStillMatchesTheBlockAfterScoping(): void
    {
        // COMMENTS OUT FIRST, and that is not tidiness: the scoper copies them verbatim, and the note
        // at the top of the stylesheet QUOTES the broken selector to explain it. The first run of this
        // test failed on its own prose — the claim here is about selectors, so prose cannot answer it.
        $css = (string) preg_replace(
            '~/\*.*?\*/~s',
            '',
            (new ComponentAssetOrchestrator())->collect([CodeBlockComponent::contract()])->styles,
        );
        $root = '[data-milpa-component="code-block"]';

        self::assertStringContainsString($root . ' {', $css, 'the block itself is styled, not only its parts');
        self::assertStringNotContainsString($root . ' .milpa-code', $css, 'and never as a descendant of itself');

        // The renderer must not put a class on the root either: `:host` IS the root, and a class
        // there is a second name for one element that the stylesheet cannot reach.
        self::assertStringNotContainsString('class="milpa-code"', self::render(['command' => 'php bin/coa serve']));
    }

    /**
     * 🚨 THE `<code>` IS A BLOCK — the control for a defect no assertion about markup could see.
     *
     * `<code>` is `display: inline` by default and it holds `.line`, which is a block. An inline box
     * wrapping a block gets anonymous block boxes before and after, so every block rendered two EMPTY
     * ROWS — one above the command, one below. Nine green assertions about this renderer's markup had
     * nothing to say about it; a browser did, measuring the `<code>` at 69.97px where its single line
     * is 22.4px. Three line boxes for one line.
     *
     * This asserts the DECLARATION, which is all PHP can reach. The measurement is in the acta.
     */
    public function testTheCodeElementIsABlockSoThereAreNoEmptyRows(): void
    {
        $styles = CodeBlockComponent::contract()->presentation?->styles;
        self::assertIsString($styles);

        $css = (string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents($styles));

        self::assertMatchesRegularExpression(
            '/\bcode\s*\{[^}]*display:\s*block/',
            $css,
            'an inline <code> around a block .line renders an empty row above and below the command',
        );

        // And it declares the paint it depends on, because a host page's `code { background: … }`
        // reaches inside a component: scoping keeps two COMPONENTS apart, not a document's element
        // selectors. Measured on the welcome page as a chip behind the command that nothing here drew.
        foreach (['background: none', 'padding: 0', 'border-radius: 0'] as $declared) {
            self::assertStringContainsString($declared, $css, 'a host rule must not be able to repaint the block');
        }
    }

    /**
     * 🚨 THE `<pre>` RE-DECLARES THE MONO FACE, BECAUSE THE UA STYLESHEET BEATS INHERITANCE.
     *
     * `:host` sets `var(--font-mono)`, but the body is a `<pre>` and `pre { font-family: monospace }`
     * in the browser's own stylesheet is a real declaration — which wins over an INHERITED value no
     * matter how specific the ancestor's selector was. Measured in a browser: the command computed to
     * `monospace`, the browser's generic mono, while the block's root computed to Space Mono. Every
     * command this component ever rendered was in the wrong typeface, and it is the component whose
     * whole job is typesetting commands.
     *
     * Third time this component failed to defend itself, and the first time the attacker was the
     * BROWSER rather than the scoper or a host page. A component inherits nothing it has not declared.
     */
    public function testTheBodyDeclaresTheMonoFaceItCannotInherit(): void
    {
        $css = self::styles();

        self::assertMatchesRegularExpression(
            '/\.body\s*\{[^}]*font-family:\s*var\(--font-mono\)/',
            $css,
            'a <pre> that only inherits its font renders in the browser generic mono',
        );

        // 🚨 AND ON THE `<code>` INSIDE IT, which is the half the first fix missed: `code
        // { font-family: monospace }` is a UA rule too, so the child re-reset what the `<pre>` had
        // just been given. That went unnoticed because the measurement read `.body` — the element
        // that had been edited — while the `.text` inside it stayed generic mono. Measuring the
        // element you edited confirms your edit, not its effect.
        self::assertMatchesRegularExpression(
            '/\bcode\s*\{[^}]*font-family:\s*inherit/',
            $css,
            'the <code> inside the <pre> has its own UA declaration to overcome',
        );
    }

    /**
     * 🚨 THE EDGES ARE DRAWN WITH A TOKEN THAT CAN CARRY THEM ON A HOST'S OWN SURFACE.
     *
     * Measured on a host painting `--surface`: the chrome strip came out at 1.000:1 against the panel
     * behind it — the identical colour — and the block's border reached 1.469:1. The top 47% of every
     * block dissolved into the card it sat on. Fills cannot fix it: no pair in the `--tierra-900` /
     * `--tierra-950` range exceeds 1.416:1, so a 1px line is the only device in the dark half of this
     * palette that reaches the 3:1 non-text floor. `--border-strong` measures 3.13:1 across the strip.
     */
    public function testTheBlockKeepsAnEdgeOnAHostsOwnSurface(): void
    {
        $css = self::styles();

        self::assertStringNotContainsString('var(--border-subtle)', $css, '1.469:1 is not an edge');
        self::assertSame(2, substr_count($css, 'var(--border-strong)'), 'the root border and the strip divider');
    }

    /**
     * 🚨 THE LIVE REGION IS NEVER TAKEN OUT OF THE ACCESSIBILITY TREE.
     *
     * It was `.copy-said:empty { display: none }`, which kept the row from jumping and cost the whole
     * announcement: `display: none` removes an element from the accessibility tree, and a
     * `role="status"` absent from the tree when its text arrives announces nothing. Every «copied» was
     * silent — and so was `press ⌘C`, the one message a user who cannot reach the clipboard needs.
     */
    public function testTheStatusRegionIsNeverDisplayNone(): void
    {
        $css = self::styles();

        self::assertStringNotContainsString('.copy-said:empty', $css);
        self::assertDoesNotMatchRegularExpression('/\.copy-said[^{]*\{[^}]*display:\s*none/', $css);

        // The empty region must still cost no space, and a flex `gap` would have applied to it —
        // which is exactly why `display: none` was reached for in the first place.
        self::assertMatchesRegularExpression('/\.copy-said:not\(:empty\)\s*\{[^}]*margin-left/', $css);
        self::assertDoesNotMatchRegularExpression('/\.copy\s*\{[^}]*\bgap:/', $css);

        // And the region is in the markup at all times, empty or not.
        self::assertStringContainsString('<span class="copy-said" role="status"></span>', self::render(['command' => 'php bin/coa serve']));
    }

    /**
     * 🚨 EACH BUTTON IS NAMED BY THE COMMAND IT TAKES, not by the block's label.
     *
     * With the label it was «Copy terminal» on all eight buttons of one page: someone tabbing through
     * them heard the same words eight times with nothing to tell the commands apart. The command is
     * what the button copies, so it is the honest name.
     */
    public function testEachButtonIsNamedByTheCommandItTakes(): void
    {
        self::assertStringContainsString(
            'aria-label="Copy php bin/coa house:start"',
            self::render(['command' => 'php bin/coa house:start']),
        );

        // Many lines: the first is read, and the rest are counted rather than recited — a name is
        // spoken in one breath.
        self::assertStringContainsString(
            'aria-label="Copy php bin/coa list and 1 more"',
            self::render(['command' => "php bin/coa list\nphp bin/coa serve"]),
        );
    }

    /**
     * 🚨 COMPACT IS ONE ROW, AND ITS BUTTON DOES NOT WAIT FOR HOVER.
     *
     * A command listed beside others could not be a full block — four framed terminals would carry the
     * same weight as the one door they sit under — so the framework's welcome page drew them as plain
     * `<code>` chips, and the cost was measured by the person reading it: «solo se puede copiar 1
     * comando, los otros se muestran pero no hay UX». The alternative was a hand-rolled copy button on
     * the page, which is the one thing this component exists to prevent.
     *
     * So the affordance stays here and only the presentation varies. And on a chip it is visible at
     * rest: a full block is a big obvious target with a clear «over it», while a chip in a list gives
     * a reader no reason to sweep four small rows hunting for what can be taken.
     */
    public function testCompactIsOneRowWithTheButtonInlineAndVisible(): void
    {
        $html = self::render(['command' => 'php bin/coa capabilities', 'prompt' => '', 'compact' => true]);

        self::assertStringContainsString('data-compact="true"', $html);
        self::assertStringNotContainsString('class="chrome"', $html, 'a strip on a chip is the 47% again');
        self::assertStringContainsString('data-milpa-copy="php bin/coa capabilities"', $html, 'it can still be taken');
        self::assertStringContainsString('aria-label="Copy php bin/coa capabilities"', $html);

        $css = self::styles();
        self::assertMatchesRegularExpression(
            '/:host\(\[data-compact\]\) \.copy\s*\{[^}]*opacity:\s*1/',
            $css,
            'a chip has no obvious «over it», so its affordance cannot hide behind hover',
        );
    }

    /** And the full block is unchanged: still framed, still hover-revealed. */
    public function testTheFullBlockIsUntouchedByTheCompactVariant(): void
    {
        $html = self::render(['command' => 'php bin/coa house:start']);

        self::assertStringNotContainsString('data-compact', $html);
        self::assertStringContainsString('class="chrome"', $html);
        self::assertMatchesRegularExpression('/\.copy\s*\{[^}]*opacity:\s*0/', self::styles(), 'on a block it still waits for hover');
    }

    /**
     * Hover brightens the copy affordance; it does not draw a box around it.
     *
     * The border appeared on hover, and a box inside a block that already has a frame reads as a
     * second frame. On a compact row it was worse: the button sits there at rest, so the box appeared
     * around something the reader could already see. Colour alone says «reachable».
     */
    public function testHoverBrightensTheButtonWithoutDrawingABorder(): void
    {
        $css = self::styles();

        self::assertMatchesRegularExpression('/\.copy:hover\s*\{[^}]*color:\s*var\(--tierra-50\)/', $css);
        self::assertDoesNotMatchRegularExpression('/\.copy:hover\s*\{[^}]*border/', $css);
        // And the transparent border that only reserved space for it is gone too, along with the
        // transition that animated it.
        self::assertDoesNotMatchRegularExpression('/\.copy\s*\{[^}]*border:\s*1px/', $css);
        self::assertDoesNotMatchRegularExpression('/\.copy\s*\{[^}]*transition:[^;]*border/', $css);

        // The keyboard ring is untouched: it is an outline, not a border, and it is the one thing here
        // a person navigating without a mouse depends on.
        self::assertMatchesRegularExpression('/\.copy:focus-visible\s*\{[^}]*outline:\s*2px solid var\(--accent\)/', $css);
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
