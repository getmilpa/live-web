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

namespace Milpa\Live\Adapters\Alpine;

use Milpa\Live\Contracts\Client\ClientRuntimeAdapterInterface;
use Milpa\Live\ValueObjects\ComponentContract;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * The Alpine.js {@see ClientRuntimeAdapterInterface}: marks rendered root
 * elements with `data-milpa-*` attributes an Alpine component picks up, and
 * describes the boot payload / static asset the HTML renderers embed so the
 * browser knows which contracts are live and where to load the runtime
 * script from. Stateless and side-effect-free — every method is a pure
 * projection of its arguments.
 */
final class AlpineRuntimeAdapter implements ClientRuntimeAdapterInterface
{
    /**
     * The runtime identifier this adapter targets, `"alpine"`.
     */
    public function name(): string
    {
        return 'alpine';
    }

    /**
     * Root-element attributes an HTML renderer merges into a mounted
     * component's wrapper tag so the client runtime can find it and bind to
     * its `$state->componentId`.
     */
    public function rootAttributes(ComponentContract $contract, StateSnapshot $state): array
    {
        return [
            'data-milpa-runtime' => 'alpine',
            'data-milpa-component' => $contract->name,
            'data-milpa-component-id' => $state->componentId,
        ];
    }

    /**
     * The payload embedded in the page for the client runtime to bootstrap
     * from: this adapter's {@see name()} plus a name/version/design-contract
     * summary of every mounted component contract.
     */
    public function bootPayload(array $contracts): array
    {
        return [
            'runtime' => $this->name(),
            'contracts' => array_map(
                static fn (ComponentContract $contract): array => [
                    'name' => $contract->name,
                    'version' => $contract->contractVersion,
                    'designContract' => $contract->designContract,
                ],
                $contracts,
            ),
        ];
    }

    /**
     * Static assets a page rendering this runtime's components must load —
     * currently just the Alpine runtime script.
     */
    public function assets(): array
    {
        return [
            'script' => '/milpa-live.js',
        ];
    }
}
