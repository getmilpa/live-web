<?php

/**
 * This file is part of Milpa Live Web — the HTML render target of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/live-web
 */

declare(strict_types=1);

namespace Milpa\Live\Tests\Rendering;

use Milpa\Live\Adapters\Alpine\AlpineRuntimeAdapter;
use Milpa\Live\Contracts\Rendering\ComponentRendererInterface;
use Milpa\Live\Rendering\DashboardHtmlRenderer;
use Milpa\Live\Transport\XhtmlStateTransferCodec;
use Milpa\Live\Testing\RendersTheSameComponent;
use Milpa\Live\ValueObjects\RenderTarget;
use PHPUnit\Framework\TestCase;

/**
 * This surface, held to the contract package's suite — the same one the terminal answers.
 *
 * Two renderers listing the same component names proves nothing; they can drift into rendering
 * different things under matching labels and no test would say so. Running one suite against both
 * is what turns "one component, every surface" from a design intention into a checked claim.
 */
final class HtmlRendererConformanceTest extends TestCase
{
    use RendersTheSameComponent;

    protected function rendererUnderTest(): ComponentRendererInterface
    {
        return new DashboardHtmlRenderer(new AlpineRuntimeAdapter(), new XhtmlStateTransferCodec());
    }

    protected function targetUnderTest(): RenderTarget
    {
        return RenderTarget::HTML;
    }
}
