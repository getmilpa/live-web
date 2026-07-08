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

use Milpa\Live\ValueObjects\CorsDecision;

/**
 * Decides whether a cross-origin HTTP request to the live endpoint is
 * allowed, and which CORS response headers to send back. Consulted by the
 * HTTP transport layer before any component interaction is dispatched —
 * this is a transport-level gate, independent of
 * {@see InteractionAuthorizerInterface}'s per-action authorization.
 */
interface CorsPolicyInterface
{
    /**
     * Evaluates one request's CORS eligibility.
     *
     * @param string|null        $origin         The request's `Origin` header value, or `null`/empty for a
     *                                           same-origin (or non-browser) request.
     * @param array<int, string> $requestHeaders Header names the request declares it will send (e.g. from a
     *                                           preflight's `Access-Control-Request-Headers`).
     *
     * @return CorsDecision `allowed: false` means the caller MUST reject the request; `allowed: true`
     *                      carries the response headers to attach.
     */
    public function check(?string $origin, string $method, array $requestHeaders = []): CorsDecision;
}
