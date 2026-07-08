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
}
