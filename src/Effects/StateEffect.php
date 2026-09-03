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
 * A shared-signal effect: a handler DECLARES a new value for a NAMED shared signal, and every element that
 * reads it updates — one truth, many projections (greenhouse decisions/0189).
 *
 * Where {@see RenderEffect} re-paints one target and {@see DispatchEffect} signals one target, this one has
 * NO target: it sets a value in the client's reactive signals store, and everything bound to `<key>` (a topbar
 * badge, a side panel, a chip — `x-text="$store.milpa['<key>']"`) tracks it. The value is the single source of
 * truth projected across the UI; declaring it from the backend keeps the projection consistent everywhere.
 */
final readonly class StateEffect
{
    public const string TYPE = 'state';

    public function __construct(
        public string $key,
        public mixed $value,
    ) {
    }

    /**
     * The effect as the array that travels in {@see \Milpa\Live\ValueObjects\InteractionResult::$effects}.
     *
     * @return array{type: string, key: string, value: mixed}
     */
    public function toArray(): array
    {
        return ['type' => self::TYPE, 'key' => $this->key, 'value' => $this->value];
    }
}
