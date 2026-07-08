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

use Milpa\Live\ValueObjects\SecurityPrincipal;

/**
 * Turns a raw HTTP `Authorization` header into the {@see SecurityPrincipal}
 * it identifies, or `null` if the header does not identify a valid
 * principal. This is the authentication seam feeding
 * {@see InteractionAuthorizerInterface::authorize()}'s `$principal`
 * argument — a request with no principal is treated as anonymous, not as
 * an error.
 */
interface TokenVerifierInterface
{
    /**
     * Verifies the given `Authorization` header value and resolves it to
     * a principal.
     *
     * @return SecurityPrincipal|null `null` if the header is missing, malformed, or names an unknown/expired
     *                                token — callers treat that as an anonymous request, not a fatal error.
     */
    public function verify(string $authorizationHeader): ?SecurityPrincipal;
}
