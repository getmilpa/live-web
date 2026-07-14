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

namespace Milpa\Live\ValueObjects;

/**
 * The outcome of an {@see \Milpa\Live\Contracts\Security\InteractionAuthorizerInterface::authorize()}
 * decision. `$allowed` is the single source of truth for whether the
 * caller may proceed — `$errors` is only present to explain a denial and
 * MUST be empty when `$allowed` is `true`; construct via {@see allow()}/
 * {@see deny()} rather than the constructor directly to keep that
 * invariant.
 */
final readonly class AuthorizationResult
{
    /**
     * @param array<string, string> $errors Field/reason => human-readable message. MUST be empty when `$allowed`
     *                                      is `true`.
     * @param array<string, mixed>  $meta   Non-authoritative context about the decision (e.g. matched scope), for
     *                                      logging/debugging — callers MUST NOT branch on it.
     */
    public function __construct(
        public bool $allowed,
        public array $errors = [],
        public array $meta = [],
    ) {
    }

    /**
     * Builds an allowed result.
     *
     * @param array<string, mixed> $meta
     */
    public static function allow(array $meta = []): self
    {
        return new self(true, [], $meta);
    }

    /**
     * Builds a denied result carrying the reason(s) for the denial.
     *
     * @param array<string, string> $errors
     * @param array<string, mixed>  $meta
     */
    public static function deny(array $errors, array $meta = []): self
    {
        return new self(false, $errors, $meta);
    }
}
