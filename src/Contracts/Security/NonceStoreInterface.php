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
 * Tracks which signed-envelope nonces (see {@see StateSignerInterface})
 * have already been consumed, so a captured, still-signature-valid
 * request cannot be replayed within the signature's TTL. A nonce is a
 * one-time ticket: the first {@see consume()} call for a given nonce
 * succeeds and marks it spent; every subsequent call for that same
 * nonce — whether the original caller resubmitting or an attacker who
 * captured the request — MUST fail.
 *
 * Implementations own pruning: `$expiresAt` gives them enough information
 * to forget a nonce once the signature it belonged to could no longer
 * verify anyway, without needing a separate sweep/cron process.
 */
interface NonceStoreInterface
{
    /**
     * Atomically checks whether `$nonce` has already been consumed and,
     * if not, marks it consumed. MUST be atomic under concurrent callers
     * for the same nonce — two concurrent calls for the same nonce MUST
     * NOT both observe "not yet consumed".
     *
     * @param int $expiresAt Unix timestamp after which `$nonce` may be pruned; implementations
     *                       SHOULD retain it at least until this point so a resubmission within
     *                       the signature's own TTL is reliably caught as a replay.
     *
     * @return bool `true` if this call is the first to consume `$nonce` (the caller may proceed);
     *              `false` if `$nonce` was already consumed by a prior call (a replay).
     */
    public function consume(string $nonce, int $expiresAt): bool;
}
