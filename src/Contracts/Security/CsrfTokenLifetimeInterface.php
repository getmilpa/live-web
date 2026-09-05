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

namespace Milpa\Live\Contracts\Security;

/**
 * A {@see CsrfGuardInterface} that can say how long a token has left — what
 * {@see \Milpa\Live\Http\LiveEndpoint} needs to refresh a token before it
 * expires under a long-lived page (greenhouse decisions/0211: the CSRF token
 * is renewed when it has a tenth of its life left).
 *
 * A sibling interface rather than two more methods on the guard contract, so
 * every existing guard stays valid; a guard that does not implement it is
 * simply never refreshed.
 */
interface CsrfTokenLifetimeInterface
{
    /**
     * Seconds until `$token` expires — `0` for an expired or malformed token.
     * Reads the token's own expiry; it does NOT verify the signature, so call
     * {@see CsrfGuardInterface::verifyToken()} first.
     */
    public function remaining(string $token): int;

    /** The lifetime, in seconds, every token this guard issues starts with. */
    public function ttl(): int;
}
