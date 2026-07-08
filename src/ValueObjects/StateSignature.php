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
 * The result of {@see \Milpa\Live\Contracts\Security\StateSignerInterface::sign()}
 * — a signature value plus the metadata needed to independently verify
 * and expire it. Carries no reference to what was signed; the signer
 * re-derives that binding at {@see \Milpa\Live\Contracts\Security\StateSignerInterface::verify()}
 * time from the original payload and `$claims`, which is why `$claims`
 * MUST round-trip byte-for-byte via {@see toAttributes()}/{@see fromAttributes()}.
 */
final readonly class StateSignature
{
    /**
     * @param string               $algorithm The signing algorithm identifier (e.g. `'hmac-sha256'`); verification MUST
     *                                        reject a signature whose algorithm it does not implement.
     * @param string               $value     The signature/MAC value itself.
     * @param int                  $issuedAt  Unix timestamp the signature was issued at.
     * @param int                  $expiresAt Unix timestamp after which the signature MUST be treated as invalid.
     * @param string               $nonce     A per-signature random value preventing signature reuse/replay across calls.
     * @param array<string, mixed> $claims    Domain data bound into the signature (e.g. component id, kind); MUST
     *                                        match what the caller re-supplies as `$requiredClaims` on verify.
     */
    public function __construct(
        public string $algorithm,
        public string $value,
        public int $issuedAt,
        public int $expiresAt,
        public string $nonce,
        public array $claims = [],
    ) {
    }

    /**
     * Flattens this signature into a string-keyed attribute map (the
     * `sig-*` counterpart of {@see fromAttributes()}) suitable for
     * embedding as XML/HTML element attributes, as the `Signed*Codec`
     * decorators in this package do.
     *
     * @return array<string, string|int>
     */
    public function toAttributes(): array
    {
        return [
            'sig-alg' => $this->algorithm,
            'sig-value' => $this->value,
            'sig-issued-at' => $this->issuedAt,
            'sig-expires-at' => $this->expiresAt,
            'sig-nonce' => $this->nonce,
            'sig-claims' => self::base64UrlEncode(json_encode($this->claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        ];
    }

    /**
     * Rebuilds a signature from the attribute map produced by
     * {@see toAttributes()}. Missing attributes default to empty/zero
     * rather than throw, so a tampered or partial attribute set still
     * decodes into a signature — one that will then simply fail
     * cryptographic verification rather than failing to parse.
     *
     * @param array<string, mixed> $attributes
     */
    public static function fromAttributes(array $attributes): self
    {
        $claims = [];
        $encodedClaims = (string) ($attributes['sig-claims'] ?? '');
        if ($encodedClaims !== '') {
            $decoded = self::base64UrlDecode($encodedClaims);
            $claims = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($claims)) {
                $claims = [];
            }
        }

        return new self(
            algorithm: (string) ($attributes['sig-alg'] ?? ''),
            value: (string) ($attributes['sig-value'] ?? ''),
            issuedAt: (int) ($attributes['sig-issued-at'] ?? 0),
            expiresAt: (int) ($attributes['sig-expires-at'] ?? 0),
            nonce: (string) ($attributes['sig-nonce'] ?? ''),
            claims: $claims,
        );
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        $padded = str_pad(strtr($value, '-_', '+/'), strlen($value) % 4 === 0 ? strlen($value) : strlen($value) + 4 - strlen($value) % 4, '=');
        $decoded = base64_decode($padded, true);
        if ($decoded === false) {
            throw new \RuntimeException('Invalid base64url signature claims.');
        }

        return $decoded;
    }
}
