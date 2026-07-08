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

namespace Milpa\Live\Tests\ValueObjects;

use Milpa\Live\ValueObjects\StateSignature;
use PHPUnit\Framework\TestCase;

/**
 * `toAttributes()`/`fromAttributes()` MUST round-trip byte-for-byte — the
 * signer re-derives its binding from `$claims` at verify time, so any drift
 * here would silently break every signed codec built on top of it.
 */
final class StateSignatureTest extends TestCase
{
    public function testToAttributesAndFromAttributesRoundTrip(): void
    {
        $signature = new StateSignature(
            algorithm: 'hmac-sha256',
            value: 'abc123',
            issuedAt: 1_000,
            expiresAt: 1_300,
            nonce: 'nonce-value',
            claims: ['kind' => 'state', 'componentId' => 'customer-picker'],
        );

        $roundTripped = StateSignature::fromAttributes($signature->toAttributes());

        self::assertSame($signature->algorithm, $roundTripped->algorithm);
        self::assertSame($signature->value, $roundTripped->value);
        self::assertSame($signature->issuedAt, $roundTripped->issuedAt);
        self::assertSame($signature->expiresAt, $roundTripped->expiresAt);
        self::assertSame($signature->nonce, $roundTripped->nonce);
        self::assertSame($signature->claims, $roundTripped->claims);
    }

    public function testFromAttributesDefaultsMissingKeysRatherThanThrowing(): void
    {
        $signature = StateSignature::fromAttributes([]);

        self::assertSame('', $signature->algorithm);
        self::assertSame('', $signature->value);
        self::assertSame(0, $signature->issuedAt);
        self::assertSame([], $signature->claims);
    }
}
