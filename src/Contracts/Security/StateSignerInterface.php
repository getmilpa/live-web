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

use Milpa\Live\ValueObjects\StateSignature;

/**
 * Signs and verifies an opaque payload string, independent of what that
 * payload contains — the tamper-evidence primitive underneath
 * {@see \Milpa\Live\Contracts\Transport\StateTransferCodecInterface} and
 * {@see \Milpa\Live\Contracts\Sync\SyncCodecInterface} signed variants.
 * Callers embed domain claims (component id, kind, revision, ...) in
 * `$claims`/`$requiredClaims` so a signature valid for one payload/claim
 * set cannot be replayed against another.
 */
interface StateSignerInterface
{
    /**
     * Signs `$payload`, binding `$claims` into the signature so
     * {@see verify()} can later confirm both the payload and the claims
     * are unmodified and intact.
     *
     * @param array<string, mixed> $claims Domain data to bind into the signature (e.g. component id, kind).
     */
    public function sign(string $payload, array $claims = []): StateSignature;

    /**
     * Verifies that `$signature` was produced by {@see sign()} for this
     * exact `$payload`, has not expired, and — if `$requiredClaims` is
     * given — that every required claim is present in the signature with
     * a matching value. MUST NOT throw on an invalid signature; failure is
     * reported via the boolean return.
     *
     * @param array<string, mixed> $requiredClaims Claims that MUST be present (and match) in `$signature` for
     *                                             verification to succeed; an empty array checks only the
     *                                             cryptographic signature and expiry.
     */
    public function verify(string $payload, StateSignature $signature, array $requiredClaims = []): bool;
}
