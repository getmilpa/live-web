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

namespace Milpa\Live\Tests\Fixtures;

use Milpa\Live\Contracts\Component\ComponentRegistryInterface;
use Milpa\Live\Contracts\Security\CsrfGuardInterface;
use Milpa\Live\Contracts\Security\InteractionAuthorizerInterface;
use Milpa\Live\Contracts\Transport\StateTransferCodecInterface;
use Milpa\Live\Security\ContractInteractionAuthorizer;
use Milpa\Live\Security\FileNonceStore;
use Milpa\Live\Security\HmacCsrfGuard;
use Milpa\Live\Security\HmacStateSigner;
use Milpa\Live\Security\SignedXhtmlStateTransferCodec;
use Milpa\Live\Transport\XhtmlStateTransferCodec;

/**
 * Test-scoped equivalent of the lab's `Demo\DemoSecurityFactory`: wires the
 * exact same real security classes ({@see HmacStateSigner},
 * {@see SignedXhtmlStateTransferCodec}, {@see HmacCsrfGuard},
 * {@see ContractInteractionAuthorizer}) this package ships, so a pass here
 * exercises the real production wiring, not a reimplementation. Not part of
 * `src/` — a PHPUnit-only test double for security wiring composition,
 * scoped per-test via an injectable nonce store path so tests never share
 * replay state.
 */
final readonly class TestSecurityWiring
{
    public const string ROUTE = '/live.test';

    public static function stateCodec(?string $noncePath = null): StateTransferCodecInterface
    {
        return new SignedXhtmlStateTransferCodec(
            new XhtmlStateTransferCodec(),
            new HmacStateSigner('test-signing-secret'),
            $noncePath !== null ? new FileNonceStore($noncePath) : null,
        );
    }

    public static function csrfGuard(): CsrfGuardInterface
    {
        return new HmacCsrfGuard('test-csrf-secret');
    }

    public static function authorizer(ComponentRegistryInterface $components): InteractionAuthorizerInterface
    {
        return new ContractInteractionAuthorizer($components);
    }
}
