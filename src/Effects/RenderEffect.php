<?php

/**
 * This file is part of Milpa Live Web — the HTTP/HTML transport layer for Milpa Live.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/live-web
 */

declare(strict_types=1);

namespace Milpa\Live\Effects;

/**
 * A cross-component render effect: a component's `handle()` DECLARES that ANOTHER component should be
 * re-painted, and the framework does the rest (greenhouse decisions/0189, evidence/0491).
 *
 * This is the standardized shape of the `effects` seam that {@see \Milpa\Live\ValueObjects\InteractionResult}
 * left open ("shape is action/effect-type defined, not standardized here"). A handler returns one of these;
 * the {@see \Milpa\Live\Http\LiveEndpoint} renders the named target component with the given props and puts its
 * HTML into the response, and the client swaps the target component's root — no imperative JS. So an agent or a
 * human declares BEHAVIOUR (on this interaction, re-paint that component) instead of wiring callbacks by hand.
 *
 * With `$state` — the target's CURRENT signed envelope, as the page holds it — the endpoint re-renders the
 * target from that state instead of mounting it fresh from `$props` (greenhouse decisions/0211): a target
 * that has been interacted with keeps what it had. An envelope that fails verification is never used; the
 * endpoint falls back to the fresh mount.
 */
final readonly class RenderEffect
{
    public const string TYPE = 'render';

    /**
     * @param string               $target    the componentId of the component to re-paint
     * @param string               $component the component name to render into the target
     * @param array<string, mixed> $props     the props to mount the target with (used when there is no `$state`)
     * @param string|null          $state     the target's current signed state envelope to re-render from, or null to mount fresh
     */
    public function __construct(
        public string $target,
        public string $component,
        public array $props = [],
        public ?string $state = null,
    ) {
    }

    /**
     * The effect as the array that travels in {@see \Milpa\Live\ValueObjects\InteractionResult::$effects};
     * `state` is present only when given.
     *
     * @return array{type: string, target: string, component: string, props: array<string, mixed>, state?: string}
     */
    public function toArray(): array
    {
        $effect = ['type' => self::TYPE, 'target' => $this->target, 'component' => $this->component, 'props' => $this->props];
        if ($this->state !== null) {
            $effect['state'] = $this->state;
        }

        return $effect;
    }
}
