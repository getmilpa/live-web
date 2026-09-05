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

use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\Contracts\Rendering\ComponentRendererInterface;
use Milpa\Live\Contracts\Rendering\DeclaresClientAssets;
use Milpa\Live\ValueObjects\ClientAssets;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\RenderResult;
use Milpa\Live\ValueObjects\RenderTarget;

/**
 * A plugin's HTML renderer as the declared-view contract sees it (greenhouse decisions/0211): it renders
 * a root carrying the component id and its children, declares the client files it needs, and still fills
 * the legacy string-keyed `assets` bag — so a test can see both channels merge independently.
 */
final class DeclaringHtmlRenderer implements ComponentRendererInterface, DeclaresClientAssets
{
    /**
     * @param array<string, mixed> $legacyAssets
     */
    public function __construct(
        private readonly ClientAssets $declared,
        private readonly array $legacyAssets = [],
    ) {
    }

    public function supportsTarget(RenderTarget $target): bool
    {
        return $target === RenderTarget::HTML;
    }

    public function render(ComponentDefinitionInterface $component, RenderRequest $request): RenderResult
    {
        $state = $request->state ?? $component->mount($request->props, $request->context);
        $children = (string) ($request->props['childrenHtml'] ?? '');

        return new RenderResult(
            output: sprintf('<div data-milpa-component-id="%s" data-value="%s">%s</div>', $state->componentId, (string) ($state->data['value'] ?? ''), $children),
            state: $state,
            assets: $this->legacyAssets,
            format: RenderTarget::HTML,
        );
    }

    public function clientAssets(): ClientAssets
    {
        return $this->declared;
    }
}
