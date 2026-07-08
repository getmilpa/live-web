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

namespace Milpa\Live\Security;

use Milpa\Live\Contracts\Security\TokenVerifierInterface;
use Milpa\Live\ValueObjects\SecurityPrincipal;

/**
 * Fixed in-memory {@see TokenVerifierInterface}: maps opaque bearer-token
 * strings, configured up front, to a {@see SecurityPrincipal}. Intended for
 * static/service-account credentials and tests, not user sessions — there is
 * no expiry, rotation, or revocation, just a lookup table.
 */
final readonly class StaticBearerTokenVerifier implements TokenVerifierInterface
{
    /**
     * @param array<string, SecurityPrincipal|array{id?: string, scopes?: array<int, string>, claims?: array<string, mixed>}> $tokens
     */
    public function __construct(
        private array $tokens,
    ) {
    }

    /**
     * Extracts the bearer token from `$authorizationHeader` (expects the
     * `Bearer <token>` scheme) and resolves it against the configured token
     * table. Returns `null` — never throws — for a malformed header or an
     * unrecognized token.
     */
    public function verify(string $authorizationHeader): ?SecurityPrincipal
    {
        if (!preg_match('/^Bearer\s+(.+)$/i', trim($authorizationHeader), $match)) {
            return null;
        }

        $record = $this->tokens[$match[1]] ?? null;
        if ($record instanceof SecurityPrincipal) {
            return $record;
        }

        if (is_array($record) && isset($record['id'])) {
            return new SecurityPrincipal(
                id: (string) $record['id'],
                scopes: is_array($record['scopes'] ?? null) ? $record['scopes'] : [],
                claims: is_array($record['claims'] ?? null) ? $record['claims'] : [],
            );
        }

        return null;
    }
}
