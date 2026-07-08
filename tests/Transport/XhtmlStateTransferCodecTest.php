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

namespace Milpa\Live\Tests\Transport;

use Milpa\Live\Transport\XhtmlStateTransferCodec;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\StateSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * Converted from tests/smoke.php lines ~196-208 (the unsigned XHTML codec
 * round trip, prior to the HmacStateSigner decoration).
 */
final class XhtmlStateTransferCodecTest extends TestCase
{
    public function testEncodeStateAndDecodeStateRoundTrip(): void
    {
        $codec = new XhtmlStateTransferCodec();
        $state = new StateSnapshot(
            componentId: 'customer-picker',
            componentName: 'autocomplete',
            version: '0.1.0',
            data: ['query' => 'mil', 'selected' => []],
        );

        $encoded = $codec->encodeState($state);
        self::assertStringContainsString('<milpa-state', $encoded);

        $decoded = $codec->decodeState($encoded);
        self::assertSame('customer-picker', $decoded->componentId);
        self::assertSame('mil', $decoded->data['query']);
    }

    public function testEncodeInteractionAndDecodeInteractionRoundTrip(): void
    {
        $codec = new XhtmlStateTransferCodec();
        $state = new StateSnapshot(
            componentId: 'customer-picker',
            componentName: 'autocomplete',
            version: '0.1.0',
            data: ['query' => 'mil', 'selected' => []],
        );

        $encoded = $codec->encodeInteraction(new InteractionRequest(
            componentId: 'customer-picker',
            componentName: 'autocomplete',
            action: 'clear',
            state: $state,
        ));

        $decoded = $codec->decodeInteraction($encoded);
        self::assertSame('clear', $decoded->action);
        self::assertSame('customer-picker', $decoded->componentId);
    }

    public function testDecodeStateRejectsInvalidBase64Payload(): void
    {
        $codec = new XhtmlStateTransferCodec();

        $this->expectException(\RuntimeException::class);
        $codec->decodeState('<milpa-state component-id="x" component="y" version="1" encoding="json.base64">not-base64!!!</milpa-state>');
    }
}
