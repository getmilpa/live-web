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

namespace Milpa\Live\Contracts\Client;

use Milpa\Live\ValueObjects\ComponentContract;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * The client-side JS runtime a rendered component boots into (e.g. Alpine).
 *
 * A renderer that produces markup for a live client MUST consult this
 * contract instead of hardcoding runtime-specific attributes or bootstrap
 * data, so swapping the client runtime does not require touching the
 * renderer. Implementations are stateless: every method is a pure
 * projection of its arguments.
 */
interface ClientRuntimeAdapterInterface
{
    /**
     * The runtime's identifier (e.g. `'alpine'`), also embedded verbatim
     * into {@see rootAttributes()} and {@see bootPayload()} so the client
     * can self-identify which runtime rendered a given root.
     */
    public function name(): string;

    /**
     * Attributes that the renderer should add to the component root.
     *
     * @return array<string, string>
     */
    public function rootAttributes(ComponentContract $contract, StateSnapshot $state): array;

    /**
     * The payload embedded on the page so the client runtime knows which
     * component contracts it must be ready to boot.
     *
     * @param array<int, ComponentContract> $contracts All contracts the current page/response may render.
     *
     * @return array<string, mixed> Runtime-specific, JSON-serializable boot payload.
     */
    public function bootPayload(array $contracts): array;

    /**
     * The static client-runtime assets (e.g. `<script>` src) the page MUST
     * include for the runtime to function.
     *
     * @return array<string, string> Asset kind (e.g. `'script'`) => URL.
     */
    public function assets(): array;
}
