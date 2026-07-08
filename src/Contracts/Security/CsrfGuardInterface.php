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

namespace Milpa\Live\Contracts\Security;

/**
 * Issues and verifies per-session, per-route CSRF tokens for the live
 * interaction endpoint. A token issued for one `$sessionId`/`$route` pair
 * MUST NOT verify for a different session or route — implementations bind
 * both into the token rather than treating it as an opaque, globally-valid
 * secret.
 */
interface CsrfGuardInterface
{
    /**
     * Issues a new token bound to the given session and route.
     */
    public function issueToken(string $sessionId, string $route): string;

    /**
     * Verifies a token was issued by {@see issueToken()} for this exact
     * session and route, and has not expired. MUST NOT throw on a
     * malformed token — an invalid token is a normal, expected input and
     * is reported via the boolean return, not an exception.
     */
    public function verifyToken(string $token, string $sessionId, string $route): bool;
}
