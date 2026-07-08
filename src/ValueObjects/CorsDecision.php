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

namespace Milpa\Live\ValueObjects;

/**
 * The outcome of a {@see \Milpa\Live\Contracts\Security\CorsPolicyInterface::check()}
 * decision. `$headers` are the CORS response headers to attach when
 * `$allowed` is `true`; they carry no meaning when `$allowed` is `false`,
 * and `$reason` carries no meaning when `$allowed` is `true`.
 */
final readonly class CorsDecision
{
    /**
     * @param bool                  $allowed Whether the caller MUST proceed with the request.
     * @param array<string, string> $headers Response headers to send (e.g. `Access-Control-Allow-Origin`) when
     *                                       `$allowed` is `true`.
     * @param string|null           $reason  A short machine-readable denial reason (e.g. `'origin_not_allowed'`)
     *                                       when `$allowed` is `false`.
     */
    public function __construct(
        public bool $allowed,
        public array $headers = [],
        public ?string $reason = null,
    ) {
    }
}
