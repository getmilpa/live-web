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

use Milpa\Live\Assets\ComponentAssetOrchestrator;
use Milpa\Live\Assets\PresentationOverrides;
use Milpa\Live\ValueObjects\ComponentContract;
use Milpa\Live\ValueObjects\ComponentPresentation;
use PHPUnit\Framework\TestCase;

/**
 * Contributing a component and overriding one are two different acts, and only the second spends
 * authority.
 *
 * The claim under test is greenhouse `decisions/0246` §2, and its teeth are in the default: with
 * nobody to ask about overrides, there are none. If installing a package were enough to restyle
 * somebody else's component, the authorization would be decoration — the effect would already have
 * happened by the time anyone was asked.
 */
final class PresentationOverridesTest extends TestCase
{
    private const FOREIGN = __DIR__ . '/../Fixtures/ForeignPackage';

    private function rating(): ComponentContract
    {
        return new ComponentContract(
            name: 'rating',
            contractVersion: '1',
            presentation: new ComponentPresentation(
                styles: self::FOREIGN . '/rating.css',
                messages: self::FOREIGN . '/rating.messages.php',
            ),
        );
    }

    /**
     * @param array<string, ComponentPresentation> $granted
     */
    private function store(array $granted): PresentationOverrides
    {
        return new class ($granted) implements PresentationOverrides {
            /** @param array<string, ComponentPresentation> $granted */
            public function __construct(private readonly array $granted)
            {
            }

            public function forComponent(string $component): ?ComponentPresentation
            {
                return $this->granted[$component] ?? null;
            }
        };
    }

    /**
     * THE ONE THAT DECIDES — a house that wired no store cannot be surprised by an override.
     */
    public function testWithNobodyToAskThereAreNoOverrides(): void
    {
        $assets = (new ComponentAssetOrchestrator())->collect([$this->rating()]);

        self::assertStringNotContainsString('OVERRIDDEN', $assets->styles);
        self::assertSame([], $assets->refused);
    }

    /**
     * An authorized override is emitted AFTER the component's own, so the cascade does the work.
     *
     * Order is the whole mechanism: emitted first, the override would lose to the very rules it was
     * authorized to change, and the grant would look honoured while doing nothing.
     */
    public function testAnAuthorizedOverrideIsEmittedAfterTheComponentsOwnLook(): void
    {
        $assets = (new ComponentAssetOrchestrator(
            overrides: $this->store(['rating' => new ComponentPresentation(styles: self::FOREIGN . '/rating-override.css')]),
        ))->collect([$this->rating()]);

        $own = strpos($assets->styles, 'var(--color-accent)');
        $override = strpos($assets->styles, 'OVERRIDDEN');

        self::assertIsInt($own);
        self::assertIsInt($override);
        self::assertGreaterThan($own, $override, 'an override emitted first loses to what it was authorized to change');
    }

    /**
     * The override is scoped to the component it CHANGES, not to whoever was allowed to change it.
     */
    public function testTheOverrideIsScopedToTheComponentItChanges(): void
    {
        $assets = (new ComponentAssetOrchestrator(
            overrides: $this->store(['rating' => new ComponentPresentation(styles: self::FOREIGN . '/rating-override.css')]),
        ))->collect([$this->rating()]);

        self::assertStringContainsString('[data-milpa-component="rating"] .star', $assets->styles);
    }

    /**
     * A stylesheet changes how a component LOOKS. A script changes what it DOES, on a page it does
     * not own — and no authorization collected for the first is an authorization for the second.
     *
     * Refused in the orchestrator rather than trusted to the store, so a store that hands one over
     * still cannot get it onto the page.
     */
    public function testAnOverrideMayNotCarryAScriptEvenWhenAStoreOffersOne(): void
    {
        $assets = (new ComponentAssetOrchestrator(
            overrides: $this->store(['rating' => new ComponentPresentation(script: self::FOREIGN . '/rating.js')]),
        ))->collect([$this->rating()]);

        self::assertSame([], $assets->scripts, 'a script rode in on a look');
        self::assertArrayHasKey('rating@1', $assets->refused);
        self::assertStringNotContainsString('window.milpaRating', $assets->scriptTag());
    }

    /**
     * Words lay over BY KEY, and only over keys the component declares.
     */
    public function testAnOverrideMaySayAWordDifferentlyButNotInventOne(): void
    {
        $assets = (new ComponentAssetOrchestrator(
            overrides: $this->store(['rating' => new ComponentPresentation(messages: self::FOREIGN . '/rating-override.messages.php')]),
        ))->collect([$this->rating()]);

        self::assertSame('Score it', $assets->messages['rating.label'] ?? null, 'the override says the word differently');
        self::assertSame('Pick a star', $assets->messages['rating.hint'] ?? null, 'the key it left alone is untouched');
        self::assertArrayNotHasKey('rating.smuggled', $assets->messages, 'an invented key has nowhere to print');
    }

    /**
     * An override naming a file it does not ship is a defect, not a silent no-op — and it is told
     * apart from the component's own missing file, because they are two different people's mistakes.
     */
    public function testAnOverrideThatIsNotShippedIsNamedAsTheOverride(): void
    {
        $assets = (new ComponentAssetOrchestrator(
            overrides: $this->store(['rating' => new ComponentPresentation(styles: self::FOREIGN . '/nope.css')]),
        ))->collect([$this->rating()]);

        self::assertArrayHasKey('rating@1 (override)', $assets->unreadable);
        self::assertArrayNotHasKey('rating@1', $assets->unreadable, "the component's own stylesheet is fine");
    }

    /**
     * A store consulted about a component nobody overrode changes nothing.
     */
    public function testAStoreThatGrantedNothingForThisComponentChangesNothing(): void
    {
        $withStore = (new ComponentAssetOrchestrator(overrides: $this->store([])))->collect([$this->rating()]);
        $without = (new ComponentAssetOrchestrator())->collect([$this->rating()]);

        self::assertSame($without->styles, $withStore->styles);
        self::assertSame($without->messages, $withStore->messages);
    }
}
