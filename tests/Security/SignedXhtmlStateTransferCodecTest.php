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

use Milpa\Live\Contracts\Security\ReplayedNonceException;
use Milpa\Live\Security\FileNonceStore;
use Milpa\Live\Security\HmacStateSigner;
use Milpa\Live\Security\SignedXhtmlStateTransferCodec;
use Milpa\Live\Transport\XhtmlStateTransferCodec;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\StateSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * Converted from tests/smoke.php lines ~210-232 — the signed XHTML state
 * transfer codec's sign/verify/tamper behavior, isolated from the full
 * {@see \Milpa\Live\Http\LiveEndpoint} HTTP loop (that's LiveEndpointTest).
 *
 * PINNED SECURITY ASSERTION: tampering with a signed envelope (state or
 * interaction) MUST be rejected. This is one of the security invariants
 * this front is explicitly required to keep honest and equivalent to the
 * lab's smoke.php.
 */
final class SignedXhtmlStateTransferCodecTest extends TestCase
{
    private function state(): StateSnapshot
    {
        return new StateSnapshot(
            componentId: 'customer-picker',
            componentName: 'autocomplete',
            version: '0.1.0',
            data: ['query' => 'mil', 'selected' => []],
        );
    }

    public function testEncodeStateProducesASignedEnvelope(): void
    {
        $codec = new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner('lab-secret-for-signatures', ttlSeconds: 300));
        $signed = $codec->encodeState($this->state());

        self::assertStringContainsString('security="signed"', $signed);
        self::assertStringContainsString('sig-value=', $signed);
    }

    public function testDecodeStateRoundTripsAnUntamperedEnvelope(): void
    {
        $codec = new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner('lab-secret-for-signatures', ttlSeconds: 300));
        $signed = $codec->encodeState($this->state());

        $decoded = $codec->decodeState($signed);
        self::assertSame('customer-picker', $decoded->componentId);
    }

    /** PINNED: tamper -> reject. */
    public function testDecodeStateRejectsATamperedComponentId(): void
    {
        $codec = new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner('lab-secret-for-signatures', ttlSeconds: 300));
        $signed = $codec->encodeState($this->state());
        $tampered = str_replace('component-id="customer-picker"', 'component-id="evil-picker"', $signed);

        $this->expectException(\Throwable::class);
        $codec->decodeState($tampered);
    }

    public function testEncodeInteractionAndDecodeInteractionRoundTrip(): void
    {
        $codec = new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner('lab-secret-for-signatures', ttlSeconds: 300));
        $signedInteraction = $codec->encodeInteraction(new InteractionRequest(
            componentId: 'customer-picker',
            componentName: 'autocomplete',
            action: 'clear',
            state: $this->state(),
        ));

        self::assertSame('clear', $codec->decodeInteraction($signedInteraction)->action);
    }

    /** PINNED: tamper -> reject (interaction envelope, not just state). */
    public function testDecodeInteractionRejectsATamperedAction(): void
    {
        $codec = new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner('lab-secret-for-signatures', ttlSeconds: 300));
        $signedInteraction = $codec->encodeInteraction(new InteractionRequest(
            componentId: 'customer-picker',
            componentName: 'autocomplete',
            action: 'clear',
            state: $this->state(),
        ));
        $tampered = str_replace('action="clear"', 'action="select"', $signedInteraction);

        $this->expectException(\Throwable::class);
        $codec->decodeInteraction($tampered);
    }

    /** PINNED: replay -> reject via the injected NonceStoreInterface. */
    public function testDecodeStateRejectsAReplayedEnvelope(): void
    {
        $noncePath = sys_get_temp_dir() . '/milpa-live-web-signed-codec-replay-' . bin2hex(random_bytes(6)) . '.json';
        $codec = new SignedXhtmlStateTransferCodec(
            new XhtmlStateTransferCodec(),
            new HmacStateSigner('lab-secret-for-signatures', ttlSeconds: 300),
            new FileNonceStore($noncePath),
        );
        $signed = $codec->encodeState($this->state());

        $first = $codec->decodeState($signed);
        self::assertSame('customer-picker', $first->componentId);

        $this->expectException(ReplayedNonceException::class);

        try {
            $codec->decodeState($signed);
        } finally {
            @unlink($noncePath);
        }
    }

    public function testDecodeStateWithoutANonceStorePreservesLegacyReusableBehavior(): void
    {
        $codec = new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner('lab-secret-for-signatures', ttlSeconds: 300));
        $signed = $codec->encodeState($this->state());

        // No NonceStoreInterface injected -> replay protection is opt-in;
        // decoding the same envelope twice must NOT throw.
        $codec->decodeState($signed);
        $decodedAgain = $codec->decodeState($signed);
        self::assertSame('customer-picker', $decodedAgain->componentId);
    }
}
