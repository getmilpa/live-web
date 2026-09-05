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

    /** greenhouse decisions/0211: the guard says how long a token has left, so the endpoint can renew it before it dies. */
    public function testRemainingCountsDownFromTheTtlAndBottomsOutAtZero(): void
    {
        $now = 1_000_000;
        $csrf = new HmacCsrfGuard('lab-csrf-secret', ttlSeconds: 100, clock: static function () use (&$now): int {
            return $now;
        });
        $token = $csrf->issueToken('session-1', '/live');

        self::assertSame(100, $csrf->ttl());
        self::assertSame(100, $csrf->remaining($token));

        $now += 95;
        self::assertSame(5, $csrf->remaining($token));
        self::assertTrue($csrf->verifyToken($token, 'session-1', '/live'), 'still valid');

        $now += 200;
        self::assertSame(0, $csrf->remaining($token), 'never negative');
        self::assertFalse($csrf->verifyToken($token, 'session-1', '/live'), 'expired');
    }

    public function testRemainingOfGarbageIsZero(): void
    {
        $csrf = new HmacCsrfGuard('lab-csrf-secret');

        self::assertSame(0, $csrf->remaining('not-a-real-token'));
        self::assertSame(0, $csrf->remaining(''));
    }
}
