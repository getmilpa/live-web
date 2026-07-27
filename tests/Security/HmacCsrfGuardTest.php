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

namespace Milpa\Live\Tests\Security;

use Milpa\Live\Security\HmacCsrfGuard;
use PHPUnit\Framework\TestCase;

/**
 * Converted from tests/smoke.php lines ~941-945.
 */
final class HmacCsrfGuardTest extends TestCase
{
    public function testIssueTokenAndVerifyTokenRoundTrip(): void
    {
        $csrf = new HmacCsrfGuard('lab-csrf-secret');
        $token = $csrf->issueToken('session-1', '/lab/autocomplete');

        self::assertTrue($csrf->verifyToken($token, 'session-1', '/lab/autocomplete'));
    }

    /** PINNED: CSRF token bound to a session must not verify for another session. */
    public function testVerifyTokenRejectsASessionMismatch(): void
    {
        $csrf = new HmacCsrfGuard('lab-csrf-secret');
        $token = $csrf->issueToken('session-1', '/lab/autocomplete');

        self::assertFalse($csrf->verifyToken($token, 'session-2', '/lab/autocomplete'));
    }

    public function testVerifyTokenRejectsARouteMismatch(): void
    {
        $csrf = new HmacCsrfGuard('lab-csrf-secret');
        $token = $csrf->issueToken('session-1', '/lab/autocomplete');

        self::assertFalse($csrf->verifyToken($token, 'session-1', '/other'));
    }

    public function testVerifyTokenRejectsGarbageInput(): void
    {
        $csrf = new HmacCsrfGuard('lab-csrf-secret');

        self::assertFalse($csrf->verifyToken('not-a-real-token', 'session-1', '/lab/autocomplete'));
    }

    public function testConstructorRejectsEmptySecret(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new HmacCsrfGuard('');
    }

    public function testVerifyRejectsAnExpiredToken(): void
    {
        // The whole point of expiresAt: a token lifted from an old page must
        // stop working. A guard that never rejects one is a guard in name only.
        $guard = new HmacCsrfGuard('lab-secret-for-csrf', ttlSeconds: -60, clockSkewSeconds: 0);
        $token = $guard->issueToken('session-1', '/live/interact');

        self::assertFalse($guard->verifyToken($token, 'session-1', '/live/interact'));
    }

    public function testVerifyRejectsATokenDatedInTheFuture(): void
    {
        // Minted against a clock that is not ours — or backdated to outlive
        // its own TTL. Either way it is not a token this guard issued now.
        $guard = new HmacCsrfGuard('lab-secret-for-csrf', clockSkewSeconds: 0);
        $payload = [
            'route' => '/live/interact',
            'issuedAt' => time() + 3600,
            'expiresAt' => time() + 7200,
            'nonce' => 'n',
        ];
        $token = rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '=');

        self::assertFalse($guard->verifyToken($token, 'session-1', '/live/interact'));
    }
}
