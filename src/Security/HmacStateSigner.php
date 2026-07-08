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

use Milpa\Live\Contracts\Security\StateSignerInterface;
use Milpa\Live\ValueObjects\StateSignature;

/**
 * HMAC-SHA256 {@see StateSignerInterface}: signs the canonical JSON of
 * `{algorithm, issuedAt, expiresAt, nonce, claims}` concatenated with the
 * caller-supplied `$payload`, over `$secret`. Verification recomputes the
 * same HMAC and compares with {@see hash_equals()}, additionally rejecting a
 * signature that has expired, is not yet valid, or whose `claims` don't
 * match every `$requiredClaims` entry. This is the primitive
 * {@see SignedXhtmlStateTransferCodec} wraps to make the client-echoed
 * `<milpa-state>` envelope tamper-evident.
 */
final readonly class HmacStateSigner implements StateSignerInterface
{
    public function __construct(
        private string $secret,
        private int $ttlSeconds = 300,
        private int $clockSkewSeconds = 30,
    ) {
        if ($secret === '') {
            throw new \InvalidArgumentException('HMAC state signer requires a non-empty secret.');
        }
    }

    /**
     * Signs `$payload` with a freshly generated nonce and an expiry
     * `$ttlSeconds` from now, binding `$claims` into the signature so a
     * verifier can pin them via `$requiredClaims`.
     */
    public function sign(string $payload, array $claims = []): StateSignature
    {
        $issuedAt = time();
        $signature = new StateSignature(
            algorithm: 'hmac-sha256',
            value: '',
            issuedAt: $issuedAt,
            expiresAt: $issuedAt + $this->ttlSeconds,
            nonce: bin2hex(random_bytes(16)),
            claims: $claims,
        );

        return new StateSignature(
            algorithm: $signature->algorithm,
            value: $this->signature($payload, $signature),
            issuedAt: $signature->issuedAt,
            expiresAt: $signature->expiresAt,
            nonce: $signature->nonce,
            claims: $signature->claims,
        );
    }

    /**
     * Verifies `$signature` was produced by {@see sign()} for the exact same
     * `$payload`, has not expired (within clock-skew tolerance), and — when
     * `$requiredClaims` is given — that every required claim matches the
     * value bound into the signature. Returns `false`, never throws, for
     * any mismatch or malformed signature; a tampered `$payload` fails here.
     */
    public function verify(string $payload, StateSignature $signature, array $requiredClaims = []): bool
    {
        if ($signature->algorithm !== 'hmac-sha256' || $signature->value === '' || $signature->nonce === '') {
            return false;
        }

        $now = time();
        if ($signature->issuedAt > $now + $this->clockSkewSeconds) {
            return false;
        }

        if ($signature->expiresAt < $now - $this->clockSkewSeconds) {
            return false;
        }

        foreach ($requiredClaims as $key => $value) {
            if (($signature->claims[$key] ?? null) !== $value) {
                return false;
            }
        }

        return hash_equals($this->signature($payload, $signature), $signature->value);
    }

    private function signature(string $payload, StateSignature $signature): string
    {
        return hash_hmac('sha256', $payload . "\n" . $this->canonicalJson([
            'algorithm' => $signature->algorithm,
            'issuedAt' => $signature->issuedAt,
            'expiresAt' => $signature->expiresAt,
            'nonce' => $signature->nonce,
            'claims' => $signature->claims,
        ]), $this->secret);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function canonicalJson(array $data): string
    {
        return json_encode($this->sortKeys($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function sortKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortKeys($item), $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->sortKeys($item);
        }

        return $value;
    }
}
