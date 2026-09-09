<?php

/**
 * This file is part of Milpa Live Web — the HTTP/HTML transport layer of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/live-web
 */

declare(strict_types=1);

namespace Milpa\Live\Components;

use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\Support\DesignTokens;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\ComponentContract;
use Milpa\Live\ValueObjects\ComponentPresentation;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\InteractionResult;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * The house's mark, as a component that reports what the surface is doing.
 *
 * It lives beside the tokens, the faces and the wordmark rather than with the form primitives,
 * because it is the design system's and not a generic input: a surface that has the house's
 * vocabulary can have its mark, and one that has neither has no use for either.
 *
 * Its three states are one plant, not a spinner with a logo on it — sown on arrival, growing while
 * something is waited for, opening once when it is ready. That is why the mark can BE the loader
 * instead of standing next to a borrowed one, and why reduced-motion still gets the state.
 *
 * The thirteen grains carry their sowing index as `--i`, and the stylesheet's wave reads the same
 * index, so the order the mark is drawn in and the order it animates in cannot drift apart.
 */
final class BrandMarkComponent implements ComponentDefinitionInterface
{
    public const STATES = ['sown', 'growing', 'ready'];

    /**
     * The thirteen grains, in SOWING ORDER: the left stem top to bottom, the diagonal, then the
     * right stem. The order is the animation's numbering, so it is data here and not decoration.
     *
     * @var array<int, array{float, float}>
     */
    private const GRAINS = [
        [0.0, 0.0], [0.0, 12.5], [0.0, 25.0], [0.0, 37.5], [0.0, 50.0],
        [12.5, 12.5],
        [25.0, 25.0],
        [37.5, 12.5],
        [50.0, 0.0], [50.0, 12.5], [50.0, 25.0], [50.0, 37.5], [50.0, 50.0],
    ];

    /**
     * The mark's runtime contract, including the stylesheet it ships beside this file.
     */
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: 'brand-mark',
            contractVersion: '1',
            summary: 'The Milpa mark, reporting the surface state as sown, growing or ready.',
            propsSchema: [
                'state' => ['type' => 'string', 'default' => 'sown', 'enum' => self::STATES],
                'label' => ['type' => 'string', 'default' => 'Milpa'],
            ],
            stateSchema: ['state' => ['type' => 'string']],
            actions: ['set' => ['payload' => ['state' => 'string']]],
            presentation: new ComponentPresentation(
                styles: \dirname(__DIR__, 2) . '/resources/components/brand-mark.css',
            ),
        );
    }

    /**
     * The grains and their sowing index, for a renderer to draw.
     *
     * @return array<int, array{x: float, y: float, i: int}>
     */
    public static function grains(): array
    {
        $grains = [];

        foreach (self::GRAINS as $i => [$x, $y]) {
            $grains[] = ['x' => $x, 'y' => $y, 'i' => $i];
        }

        return $grains;
    }

    /**
     * The mark's gold, which is the brand's and never the theme's.
     *
     * Read from the design system rather than written here, so the one place that may state it
     * stays the one place that states it.
     */
    public static function gold(): string
    {
        return DesignTokens::MARK_GOLD;
    }

    /**
     * Mounts the mark in the state the surface asked for, carrying its accessible label.
     */
    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        return new StateSnapshot(
            $context->componentId,
            'brand-mark',
            '1',
            ['state' => $this->admissible($props['state'] ?? null)],
            ['label' => \is_string($props['label'] ?? null) ? $props['label'] : 'Milpa'],
        );
    }

    /**
     * Moves the mark to another state — the same admissibility as mounting, so a payload cannot
     * reach a state that props could not.
     */
    public function handle(InteractionRequest $request): InteractionResult
    {
        $state = $request->state;

        return new InteractionResult(
            state: new StateSnapshot(
                $state->componentId,
                $state->componentName,
                $state->version,
                array_merge($state->data, ['state' => $this->admissible($request->payload['state'] ?? null)]),
                $state->meta,
            ),
        );
    }

    /**
     * A state the mark does not have falls back to `sown` rather than reaching the page.
     *
     * The mark is what a person watches to know whether anything is happening, so an unknown value
     * must not leave it in no state at all — that reads as "nothing is running", which is the one
     * thing it must never say by accident.
     */
    private function admissible(mixed $state): string
    {
        return \is_string($state) && \in_array($state, self::STATES, true) ? $state : 'sown';
    }
}
