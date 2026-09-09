<?php

/**
 * This file is part of Milpa Live Web — the HTTP/HTML transport layer (security, transport, rendering) of the Milpa PHP framework live component system.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/live-web
 */

declare(strict_types=1);

namespace Milpa\Live\Tests\Assets;

use Milpa\Live\Assets\CssScoper;
use PHPUnit\Framework\TestCase;

/**
 * Scoping has to be invisible to the author, which means every way it could quietly break the CSS
 * is a case here. Each one below has already been wrong once or is one character away from it: the
 * failure mode is never an error, it is a stylesheet that parses, looks right, and does nothing.
 */
final class CssScoperTest extends TestCase
{
    private const SCOPE = '[data-milpa-component="x"]';

    private function scope(string $css): string
    {
        return (new CssScoper())->scope($css, self::SCOPE);
    }

    public function testADescendantRuleIsConfinedToTheComponent(): void
    {
        self::assertStringContainsString(self::SCOPE . ' .field ', $this->scope('.field { color: red; }'));
    }

    /**
     * `:host` is the component's own root, so it attaches WITHOUT a combinator. Prefixed as a
     * descendant instead, every root-level rule a component writes silently stops applying.
     */
    public function testHostBecomesTheRootItselfAndNotADescendantOfIt(): void
    {
        $scoped = $this->scope(':host { display: flex; }');

        self::assertStringContainsString(self::SCOPE . ' {', $scoped);
        self::assertStringNotContainsString(self::SCOPE . ' :host', $scoped);
    }

    public function testHostWithAnArgumentNarrowsTheRootAsACompound(): void
    {
        self::assertStringContainsString(
            self::SCOPE . '.is-open ',
            $this->scope(':host(.is-open) { display: block; }'),
        );
    }

    public function testHostWithADescendantKeepsTheCombinator(): void
    {
        self::assertStringContainsString(
            self::SCOPE . ' > .row ',
            $this->scope(':host > .row { gap: 1px; }'),
        );
    }

    /**
     * Keyframe "selectors" are `from`, `to` and percentages. Prefixing them costs no error and no
     * warning — the animation simply never runs again.
     */
    public function testKeyframeStopsAreLeftAlone(): void
    {
        $scoped = $this->scope('@keyframes pop { from { opacity: 0; } to { opacity: 1; } }');

        self::assertStringContainsString('from {', $scoped);
        self::assertStringNotContainsString(self::SCOPE . ' from', $scoped);
    }

    public function testConditionalAtRulesAreScopedInside(): void
    {
        $scoped = $this->scope('@media (min-width: 40rem) { .grid { display: grid; } }');

        self::assertStringContainsString('@media (min-width: 40rem)', $scoped);
        self::assertStringContainsString(self::SCOPE . ' .grid ', $scoped);
    }

    public function testFontFaceAndImportAreLeftAlone(): void
    {
        $scoped = $this->scope('@import url("a.css"); @font-face { font-family: Grano; }');

        self::assertStringContainsString('@import url("a.css");', $scoped);
        self::assertStringContainsString('@font-face { font-family: Grano; }', $scoped);
        self::assertStringNotContainsString(self::SCOPE . ' @', $scoped);
    }

    public function testEverySelectorInAListIsScopedNotJustTheFirst(): void
    {
        $scoped = $this->scope('.a, .b { color: red; }');

        self::assertStringContainsString(self::SCOPE . ' .a', $scoped);
        self::assertStringContainsString(self::SCOPE . ' .b', $scoped);
    }

    public function testACommaInsideAFunctionalSelectorIsNotASeparator(): void
    {
        $scoped = $this->scope(':is(.a, .b) .c { color: red; }');

        self::assertSame(1, substr_count($scoped, self::SCOPE));
        self::assertStringContainsString(self::SCOPE . ' :is(.a, .b) .c ', $scoped);
    }

    public function testACommaInsideAnAttributeValueIsNotASeparator(): void
    {
        $scoped = $this->scope('[data-tags="a,b"] { color: red; }');

        self::assertSame(1, substr_count($scoped, self::SCOPE));
    }

    /**
     * A nested rule already inherits its parent's now-scoped selector; scoping it again would confine
     * it to a component inside a component, which never exists.
     */
    public function testNestedRulesAreLeftToInheritTheirScopedParent(): void
    {
        $scoped = $this->scope('.card { color: red; & .title { font-weight: 700; } }');

        self::assertSame(1, substr_count($scoped, self::SCOPE));
        self::assertStringContainsString('& .title { font-weight: 700; }', $scoped);
    }

    /**
     * The defect this file was opened by: a prose comma in a comment read as a selector separator.
     */
    public function testAProseCommaInACommentIsNotASelectorSeparator(): void
    {
        $scoped = $this->scope("/* one, two */\n.field { color: red; }");

        self::assertSame(1, substr_count($scoped, self::SCOPE));
        self::assertStringContainsString('/* one, two */', $scoped);
        self::assertStringContainsString(self::SCOPE . ' .field ', $scoped);
    }

    public function testDeclarationsAreCopiedByteForByte(): void
    {
        $scoped = $this->scope('.a { background: url("x,y.png"); content: "}"; }');

        self::assertStringContainsString('background: url("x,y.png");', $scoped);
        self::assertStringContainsString('content: "}";', $scoped);
    }

    public function testAnEmptyStylesheetStaysEmpty(): void
    {
        self::assertSame('', $this->scope(''));
    }
}
