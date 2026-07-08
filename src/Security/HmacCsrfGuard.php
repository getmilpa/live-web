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

use Milpa\Live\Contracts\Security\CsrfGuardInterface;

/**
 * HMAC-SHA256 {@see CsrfGuardInterface}: a token is a base64url-encoded JSON
 * payload (`route`, `issuedAt`, `expiresAt`, `nonce`, `signature`) whose
 * `signature` binds the payload to the session id it was issued for via
 * `hash_hmac('sha256', $sessionId . "\n" . json_encode($payload), $secret)`.
 * Stateless — no storage, no server-side lookup — verification is pure
 * recomputation-and-compare with {@see hash_equals()}. A token issued for
 * one session/route pair fails verification for any other.
 */
final readonly class HmacCsrfGuard implements CsrfGuardInterface
{
    public function __construct(
        private string $secret,
        private int $ttlSeconds = 3600,
        private int $clockSkewSeconds = 30,
    ) {
        if ($secret === '') {
            throw new \InvalidArgumentException('CSRF guard requires a non-empty secret.');
        }
    }

    /**
     * Issues a new HMAC-signed token bound to `$sessionId` and `$route`,
     * valid for `$ttlSeconds` from now.
     */
    public function issueToken(string $sessionId, string $route): string
    {
        $issuedAt = time();
        $payload = [
            'route' => $route,
            'issuedAt' => $issuedAt,
            'expiresAt' => $issuedAt + $this->ttlSeconds,
            'nonce' => bin2hex(random_bytes(16)),
        ];
        $payload['signature'] = $this->signature($sessionId, $payload);

        return $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Verifies `$token` was issued by {@see issueToken()} for this exact
     * `$sessionId`/`$route` pair and has not expired (within the configured
     * clock-skew tolerance). Returns `false` — never throws — for a
     * malformed, mismatched, or expired token.
     */
    public function verifyToken(string $token, string $sessionId, string $route): bool
    {
        try {
            $payload = json_decode($this->base64UrlDecode($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return false;
        }

        if (!is_array($payload) || ($payload['route'] ?? null) !== $route) {
            return false;
        }

        $now = time();
        if ((int) ($payload['issuedAt'] ?? 0) > $now + $this->clockSkewSeconds) {
            return false;
        }

        if ((int) ($payload['expiresAt'] ?? 0) < $now - $this->clockSkewSeconds) {
            return false;
        }

        $signature = (string) ($payload['signature'] ?? '');
        unset($payload['signature']);

        return $signature !== '' && hash_equals($this->signature($sessionId, $payload), $signature);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function signature(string $sessionId, array $payload): string
    {
        ksort($payload);

        return hash_hmac(
            'sha256',
            $sessionId . "\n" . json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $this->secret,
        );
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $padded = str_pad(strtr($value, '-_', '+/'), strlen($value) % 4 === 0 ? strlen($value) : strlen($value) + 4 - strlen($value) % 4, '=');
        $decoded = base64_decode($padded, true);

        return $decoded === false ? '' : $decoded;
    }
}
