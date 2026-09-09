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

namespace Milpa\Live\Tests\Components;

use DOMDocument;
use DOMXPath;
use Milpa\Live\Assets\ComponentAssetOrchestrator;
use Milpa\Live\Components\BrandMarkComponent;
use Milpa\Live\Rendering\BrandMarkHtmlRenderer;
use Milpa\Live\Support\DesignTokens;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\RenderRequest;
use PHPUnit\Framework\TestCase;

/**
 * The first component of the house to travel whole: contract, markup and look in one package.
 *
 * This is where `decisions/0246`'s F5 gets answered — the falsifier that can say no. If a surface
 * had to write rules of its own to make the mark look right, the component would not be travelling,
 * it would be a template with extra steps, and the architecture would be wrong.
 */
final class BrandMarkComponentTest extends TestCase
{
    private function render(string $state): string
    {
        return (new BrandMarkHtmlRenderer())->render(new BrandMarkComponent(), new RenderRequest(
            context: new ComponentContext('gate-mark', route: '/webauthn/enroll'),
            props: ['state' => $state],
        ))->output;
    }

    private function styles(): string
    {
        return (new ComponentAssetOrchestrator())->collect([BrandMarkComponent::contract()])->styles;
    }

    /**
     * F5 — everything the mark looks like arrives with the mark. The renderer contributes no style.
     */
    public function testTheMarkCarriesItsOwnLookAndTheRendererAddsNone(): void
    {
        $markup = $this->render('sown');
        $assets = (new ComponentAssetOrchestrator())->collect([BrandMarkComponent::contract()]);

        self::assertStringNotContainsString('<style', $markup, 'the renderer must not sew styles into the markup');
        self::assertStringNotContainsString('fill=', $markup, 'the colour belongs to the stylesheet, not the markup');
        self::assertSame(['brand-mark@1'], $assets->emitted);
        self::assertStringContainsString(DesignTokens::MARK_GOLD, $assets->styles, 'the mark keeps the brand gold');
    }

    /**
     * The scope reaches the root the renderer produced — asked of the document, not of the strings.
     */
    public function testTheScopedStylesheetSelectsTheRenderedMark(): void
    {
        $document = new DOMDocument();
        $document->loadHTML('<!doctype html><html><body>' . $this->render('growing') . '</body></html>', \LIBXML_NOERROR);
        $matched = (new DOMXPath($document))->query('//*[@data-milpa-component="brand-mark"][@data-state="growing"]');

        self::assertNotFalse($matched);
        self::assertSame(1, $matched->length);
    }

    /**
     * `:host` rules must land ON the root. Scoped as descendants they are dead, silently.
     */
    public function testTheHostRulesLandOnTheMarkItselfAndNotInsideIt(): void
    {
        $styles = $this->styles();

        self::assertStringContainsString('[data-milpa-component="brand-mark"] {', $styles);
        self::assertStringContainsString('[data-milpa-component="brand-mark"][data-state="growing"] rect', $styles);
        self::assertStringNotContainsString(':host', $styles, ':host is the authoring form and must not survive scoping');
    }

    /**
     * Scoping leaves `@keyframes` alone by design, so their NAMES are global and must be prefixed.
     *
     * Prefixing a keyframe stop (`from`, `to`, a percentage) kills the animation with no error, so
     * the scoper cannot touch them — which leaves two components calling one `grow` replacing each
     * other's motion. The house's own component has to follow the convention it asks for.
     */
    public function testEveryKeyframeTheMarkShipsCarriesTheComponentsOwnPrefix(): void
    {
        preg_match_all('/@keyframes\s+([a-zA-Z0-9_-]+)/', $this->styles(), $names);

        self::assertNotEmpty($names[1]);

        foreach ($names[1] as $name) {
            self::assertStringStartsWith('milpa-mark-', $name, 'a bare keyframe name is one stranger away from being replaced');
        }
    }

    /**
     * The thirteen grains carry the sowing index the stylesheet's wave reads.
     */
    public function testTheGrainsCarryTheIndexTheAnimationReads(): void
    {
        $markup = $this->render('sown');

        self::assertSame(13, substr_count($markup, '<rect '));
        self::assertStringContainsString('style="--i:0"', $markup);
        self::assertStringContainsString('style="--i:12"', $markup);
        self::assertStringContainsString('var(--i)', $this->styles());
    }

    /**
     * A state the mark does not have must not leave it in none: that reads as "nothing is running".
     */
    public function testAnUnknownStateFallsBackToSownRatherThanToNothing(): void
    {
        self::assertStringContainsString('data-state="sown"', $this->render('halfway'));
    }

    /**
     * Reduced motion keeps the STATE and drops only the choreography.
     */
    public function testReducedMotionStillReportsThatSomethingIsRunning(): void
    {
        $styles = $this->styles();

        self::assertStringContainsString('@media (prefers-reduced-motion: reduce)', $styles);
        self::assertMatchesRegularExpression(
            '/prefers-reduced-motion: reduce\).*data-state="growing"\]\s*rect\s*\{[^}]*opacity: \.5/s',
            $styles,
            'the mark must still dim while it waits, or that person cannot tell anything is running',
        );
    }
}
