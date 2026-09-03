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
 * A cross-component dispatch effect: a component's `handle()` DECLARES that a browser event is delivered to
 * ANOTHER component, which reacts client-side — no re-render, no imperative JS (greenhouse decisions/0189).
 *
 * Where {@see RenderEffect} re-paints the target, this one SIGNALS it: the client dispatches a
 * `milpa:<event>` CustomEvent (with the payload as its `detail`) on the target component's root. The target
 * listens with `x-on:milpa:<event>` (Alpine) or a plain listener and updates itself. Use it when the other
 * component should react (open, highlight, refetch) without the server re-rendering it.
 */
final readonly class DispatchEffect
{
    public const string TYPE = 'dispatch';

    /**
     * @param string               $to      the componentId of the component to notify
     * @param string               $event   the event name (delivered as `milpa:<event>`)
     * @param array<string, mixed> $payload the event detail
     */
    public function __construct(
        public string $to,
        public string $event,
        public array $payload = [],
    ) {
    }

    /**
     * The effect as the array that travels in {@see \Milpa\Live\ValueObjects\InteractionResult::$effects}.
     *
     * @return array{type: string, to: string, event: string, payload: array<string, mixed>}
     */
    public function toArray(): array
    {
        return ['type' => self::TYPE, 'to' => $this->to, 'event' => $this->event, 'payload' => $this->payload];
    }
}
