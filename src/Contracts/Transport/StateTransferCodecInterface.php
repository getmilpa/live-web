<?php

/**
 * This file is part of Milpa Live Web — the HTTP/HTML transport layer (security, transport, rendering) of the Milpa PHP framework live component system.
 *
 * (c) TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/live-web
 */

declare(strict_types=1);

namespace Milpa\Live\Contracts\Transport;

use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * Serializes {@see StateSnapshot} and {@see InteractionRequest} to and
 * from wire strings the client round-trips (e.g. embedded in a
 * `<milpa-state>` element in the SSR'd page, then echoed back verbatim on
 * the next interaction). Because the client is untrusted, {@see
 * \Milpa\Live\Http\LiveEndpoint} relies on a signed decorator of this
 * interface (pairing an inner codec with a
 * {@see \Milpa\Live\Contracts\Security\StateSignerInterface}) so a
 * decoded snapshot is provably the one the server last issued — plain
 * encode/decode here carries no authenticity guarantee by itself.
 */
interface StateTransferCodecInterface
{
    /**
     * Serializes a state snapshot to a wire string.
     */
    public function encodeState(StateSnapshot $snapshot): string;

    /**
     * Parses a wire string back into a {@see StateSnapshot}.
     *
     * @throws \RuntimeException If `$encoded` is not well-formed, or (for signed implementations) fails
     *                           signature verification.
     */
    public function decodeState(string $encoded): StateSnapshot;

    /**
     * Serializes an interaction request to a wire string.
     */
    public function encodeInteraction(InteractionRequest $request): string;

    /**
     * Parses a wire string back into an {@see InteractionRequest}.
     *
     * @throws \RuntimeException If `$encoded` is not well-formed, or (for signed implementations) fails
     *                           signature verification.
     */
    public function decodeInteraction(string $encoded): InteractionRequest;
}
