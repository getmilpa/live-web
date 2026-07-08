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

namespace Milpa\Live\Contracts\Rendering;

use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\RenderResult;

/**
 * Compiles authored XHTML-with-component-tags markup (e.g.
 * `<milpa:autocomplete .../>`) into a single rendered {@see RenderResult}
 * by resolving each component tag through a
 * {@see \Milpa\Live\Contracts\Component\ComponentRegistryInterface} and
 * its paired {@see ComponentRendererInterface}. This is the authoring-time
 * seam: templates write component tags, not renderer calls.
 */
interface MarkupCompilerInterface
{
    /**
     * Compiles markup that MUST contain exactly one root component
     * element into that component's rendered output.
     *
     * @throws \RuntimeException If the markup is not valid XML, does not contain exactly one root
     *                           component element, or names a component/renderer that is not
     *                           registered.
     */
    public function compile(string $markup, ComponentContext $context): RenderResult;
}
