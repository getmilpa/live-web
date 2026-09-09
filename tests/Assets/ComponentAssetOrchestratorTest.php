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

use Milpa\Live\Adapters\Alpine\AlpineRuntimeAdapter;
use Milpa\Live\Assets\ComponentAssetOrchestrator;
use Milpa\Live\ValueObjects\ComponentContract;
use Milpa\Live\ValueObjects\ComponentPresentation;
use Milpa\Live\ValueObjects\StateSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * A component travels whole: it declares its own look, and the page emits it once.
 *
 * The fixtures under `Fixtures/ForeignPackage` stand in for a package this transport has never heard
 * of — which is the entire claim under test. Nothing here may reach into a consumer package to make a
 * component look right; if it had to, the component would not be travelling, it would be a template
 * with extra steps.
 */
final class ComponentAssetOrchestratorTest extends TestCase
{
    private const FOREIGN = __DIR__ . '/../Fixtures/ForeignPackage';

    private function rating(): ComponentContract
    {
        return new ComponentContract(
            name: 'rating',
            contractVersion: '1',
            presentation: new ComponentPresentation(
                styles: self::FOREIGN . '/rating.css',
                script: self::FOREIGN . '/rating.js',
            ),
        );
    }

    private function badge(): ComponentContract
    {
        return new ComponentContract(
            name: 'badge',
            contractVersion: '1',
            presentation: new ComponentPresentation(styles: self::FOREIGN . '/badge.css'),
        );
    }

    /**
     * F1 — a stranger's component arrives complete: markup scope, styles and script, no consumer edit.
     */
    public function testAForeignComponentBringsItsOwnStylesAndScript(): void
    {
        $assets = (new ComponentAssetOrchestrator())->collect([$this->rating()]);

        self::assertSame(['rating@1'], $assets->emitted);
        self::assertSame([], $assets->unreadable);
        self::assertStringContainsString('[data-milpa-component="rating"]', $assets->styleTag());
        self::assertStringContainsString('window.milpaRating', $assets->scriptTag());
        self::assertFalse($assets->isEmpty());
    }

    /**
     * F1 (the coupling that makes it true) — the scope is built from the attribute the DOM gets.
     *
     * Asked of the adapter and of the emitted stylesheet rather than of an internal helper: a scope
     * that drifted from the rendered root would produce CSS that is present, correct-looking, and
     * matches nothing. That the selector then SELECTS is proved against a real renderer in
     * {@see \Milpa\Live\Tests\Components\BrandMarkComponentTest}.
     */
    public function testTheEmittedScopeIsBuiltFromTheAttributeTheRuntimePutsOnEveryRoot(): void
    {
        $contract = $this->rating();
        $attributes = (new AlpineRuntimeAdapter())->rootAttributes(
            $contract,
            new StateSnapshot('rating-1', 'rating', '1', [], []),
        );

        self::assertSame('rating', $attributes['data-milpa-component'] ?? null);
        self::assertStringContainsString(
            '[data-milpa-component="' . $attributes['data-milpa-component'] . '"]',
            (new ComponentAssetOrchestrator())->collect([$contract])->styles,
        );
    }

    /**
     * F2 — two strangers using `.label` for different things do not reach each other.
     */
    public function testTwoComponentsUsingTheSameClassNameDoNotCollide(): void
    {
        $assets = (new ComponentAssetOrchestrator())->collect([$this->rating(), $this->badge()]);

        self::assertStringContainsString('[data-milpa-component="rating"] .label', $assets->styles);
        self::assertStringContainsString('[data-milpa-component="badge"] .label', $assets->styles);
        self::assertStringNotContainsString("\n.label", "\n" . $assets->styles);
    }

    /**
     * F2, positive control — the collision was real, so preventing it is a result and not a tautology.
     */
    public function testWithoutScopingThoseTwoComponentsWouldCollide(): void
    {
        $rating = (string) file_get_contents(self::FOREIGN . '/rating.css');
        $badge = (string) file_get_contents(self::FOREIGN . '/badge.css');

        self::assertStringContainsString('.label', $rating);
        self::assertStringContainsString('.label', $badge);
        self::assertStringContainsString(".label", $rating . $badge);
    }

    /**
     * F3 — fifty uses cost one stylesheet, measured in bytes rather than assumed.
     */
    public function testTheSameComponentFiftyTimesEmitsItsStylesheetOnce(): void
    {
        $orchestrator = new ComponentAssetOrchestrator();
        $once = $orchestrator->collect([$this->rating()]);
        $fifty = $orchestrator->collect(array_fill(0, 50, $this->rating()));

        self::assertSame(\strlen($once->styles), \strlen($fifty->styles));
        self::assertSame(['rating@1'], $fifty->emitted);
        self::assertCount(1, $fifty->scripts);
    }

    /**
     * A declared file the package does not ship is a packaging defect, and it stays legible as one.
     */
    public function testAStylesheetThatIsNotShippedIsReportedRatherThanSwallowed(): void
    {
        $contract = new ComponentContract(
            name: 'ghost',
            contractVersion: '1',
            presentation: new ComponentPresentation(styles: self::FOREIGN . '/nope.css'),
        );

        $assets = (new ComponentAssetOrchestrator())->collect([$contract]);

        self::assertSame([], $assets->emitted);
        self::assertArrayHasKey('ghost@1', $assets->unreadable);
        self::assertTrue($assets->isEmpty());
    }

    /**
     * A component that declares nothing costs nothing — the page emits no empty tags for it.
     */
    public function testAComponentThatDeclaresNothingContributesNothing(): void
    {
        $assets = (new ComponentAssetOrchestrator())->collect([
            new ComponentContract(name: 'bare', contractVersion: '1'),
        ]);

        self::assertTrue($assets->isEmpty());
        self::assertSame('', $assets->styleTag());
        self::assertSame('', $assets->scriptTag());
    }
}
