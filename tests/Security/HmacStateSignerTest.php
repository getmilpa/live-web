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

use Milpa\Live\Security\HmacStateSigner;
use PHPUnit\Framework\TestCase;

final class HmacStateSignerTest extends TestCase
{
    public function testSignAndVerifyRoundTrip(): void
    {
        $signer = new HmacStateSigner('lab-secret-for-signatures', ttlSeconds: 300);
        $signature = $signer->sign('payload-bytes', ['kind' => 'state']);

        self::assertSame('hmac-sha256', $signature->algorithm);
        self::assertNotSame('', $signature->value);
        self::assertTrue($signer->verify('payload-bytes', $signature, ['kind' => 'state']));
    }

    public function testVerifyRejectsTamperedPayload(): void
    {
        $signer = new HmacStateSigner('lab-secret-for-signatures');
        $signature = $signer->sign('original-payload');

        self::assertFalse($signer->verify('tampered-payload', $signature));
    }

    public function testVerifyRejectsMismatchedRequiredClaims(): void
    {
        $signer = new HmacStateSigner('lab-secret-for-signatures');
        $signature = $signer->sign('payload-bytes', ['componentId' => 'customer-picker']);

        self::assertFalse($signer->verify('payload-bytes', $signature, ['componentId' => 'evil-picker']));
    }

    public function testVerifyRejectsExpiredSignature(): void
    {
        $signer = new HmacStateSigner('lab-secret-for-signatures', ttlSeconds: -10, clockSkewSeconds: 0);
        $signature = $signer->sign('payload-bytes');

        self::assertFalse($signer->verify('payload-bytes', $signature));
    }

    public function testConstructorRejectsEmptySecret(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new HmacStateSigner('');
    }
}
