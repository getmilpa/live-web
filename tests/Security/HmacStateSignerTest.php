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
use Milpa\Live\ValueObjects\StateSignature;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function malformedSignatures(): iterable
    {
        yield 'another algorithm' => ['hmac-sha1', 'abc123', 'nonce'];
        yield 'no value' => ['hmac-sha256', '', 'nonce'];
        yield 'no nonce' => ['hmac-sha256', 'abc123', ''];
    }

    #[DataProvider('malformedSignatures')]
    public function testVerifyRejectsAMalformedSignatureBeforeSpendingAHash(string $algorithm, string $value, string $nonce): void
    {
        // A signature naming another algorithm is the downgrade attempt this
        // guard exists for: accept it and the value is compared under rules
        // this signer never agreed to.
        $signer = new HmacStateSigner('lab-secret-for-signatures');
        $signature = new StateSignature($algorithm, $value, time(), time() + 300, $nonce);

        self::assertFalse($signer->verify('payload-bytes', $signature));
    }

    public function testVerifyRejectsASignatureDatedInTheFuture(): void
    {
        // Beyond the tolerated skew, "issued later than now" means the clock
        // it was minted against is not ours — or the date was chosen to keep
        // the signature alive past its real expiry.
        $signer = new HmacStateSigner('lab-secret-for-signatures', clockSkewSeconds: 30);
        $future = time() + 3600;
        $signature = new StateSignature('hmac-sha256', 'no-importa-el-valor', $future, $future + 300, 'nonce');

        self::assertFalse($signer->verify('payload-bytes', $signature));
    }
}
