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

use Milpa\Live\Security\StaticBearerTokenVerifier;
use Milpa\Live\ValueObjects\SecurityPrincipal;
use PHPUnit\Framework\TestCase;

/**
 * Converted from tests/smoke.php lines ~905-913.
 */
final class StaticBearerTokenVerifierTest extends TestCase
{
    public function testVerifyResolvesAKnownBearerToken(): void
    {
        $verifier = new StaticBearerTokenVerifier([
            'valid-token' => new SecurityPrincipal('user:1', ['milpa:component:autocomplete:search']),
        ]);

        $principal = $verifier->verify('Bearer valid-token');

        self::assertInstanceOf(SecurityPrincipal::class, $principal);
        self::assertSame('user:1', $principal->id);
    }

    public function testVerifyRejectsAnUnknownBearerToken(): void
    {
        $verifier = new StaticBearerTokenVerifier([
            'valid-token' => new SecurityPrincipal('user:1', []),
        ]);

        self::assertNull($verifier->verify('Bearer invalid-token'));
    }

    public function testVerifyRejectsANonBearerHeader(): void
    {
        $verifier = new StaticBearerTokenVerifier(['valid-token' => new SecurityPrincipal('user:1', [])]);

        self::assertNull($verifier->verify('Basic dXNlcjpwYXNz'));
    }

    public function testVerifyAcceptsAnArrayShapedTokenRecord(): void
    {
        $verifier = new StaticBearerTokenVerifier([
            'array-token' => ['id' => 'user:2', 'scopes' => ['milpa:*']],
        ]);

        $principal = $verifier->verify('Bearer array-token');

        self::assertInstanceOf(SecurityPrincipal::class, $principal);
        self::assertSame('user:2', $principal->id);
        self::assertTrue($principal->can('milpa:anything'));
    }
}
