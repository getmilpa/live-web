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

namespace Milpa\Live\Tests\Rendering;

use Milpa\Live\Adapters\Alpine\AlpineRuntimeAdapter;
use Milpa\Live\Components\Autocomplete\AutocompleteComponent;
use Milpa\Live\DataSource\ArrayDataSource;
use Milpa\Live\DataSource\InMemoryDataSourceRegistry;
use Milpa\Live\Rendering\AutocompleteHtmlRenderer;
use Milpa\Live\Transport\XhtmlStateTransferCodec;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\RenderTarget;
use PHPUnit\Framework\TestCase;

/**
 * Converted from tests/smoke.php lines ~234-243. Exercises the real
 * package templates (`packages/milpa-live-web/templates/`), which
 * `LatteTemplateRenderer` resolves by default relative to the package root.
 */
final class AutocompleteHtmlRendererTest extends TestCase
{
    private function component(): AutocompleteComponent
    {
        $sources = new InMemoryDataSourceRegistry();
        $sources->register(new ArrayDataSource('customers.search', [
            ['value' => 'acme', 'label' => 'Acme Studio', 'search' => 'agency design'],
            ['value' => 'milpa', 'label' => 'Milpa Labs', 'search' => 'framework components'],
        ]));

        return new AutocompleteComponent($sources);
    }

    public function testRenderProducesAlpineInitializedHtmlWithASignedStateEnvelope(): void
    {
        $component = $this->component();
        $context = new ComponentContext('customer-picker', principal: 'user:1', route: '/lab/autocomplete');
        $props = [
            'name' => 'customer',
            'label' => 'Customer',
            'source' => 'customers.search',
            'multiple' => true,
        ];
        $state = $component->mount($props, $context);

        $renderer = new AutocompleteHtmlRenderer(new AlpineRuntimeAdapter(), new XhtmlStateTransferCodec());
        $rendered = $renderer->render($component, new RenderRequest($context, $props, $state));

        self::assertSame(RenderTarget::HTML, $rendered->format);
        self::assertStringContainsString('milpaAutocomplete(', $rendered->output);
        self::assertStringContainsString('<milpa-state', $rendered->output);
        self::assertStringContainsString('name="customer[]"', $rendered->output, 'multiselect must use array hidden-input naming');
        self::assertSame('/milpa-live.js', $rendered->assets['script']);
    }

    public function testSupportsTargetIsHtmlOnly(): void
    {
        $renderer = new AutocompleteHtmlRenderer(new AlpineRuntimeAdapter(), new XhtmlStateTransferCodec());

        self::assertTrue($renderer->supportsTarget(RenderTarget::HTML));
        self::assertFalse($renderer->supportsTarget(RenderTarget::TUI));
    }

    public function testRenderRejectsAComponentOtherThanAutocomplete(): void
    {
        $renderer = new AutocompleteHtmlRenderer(new AlpineRuntimeAdapter(), new XhtmlStateTransferCodec());
        $checkbox = new \Milpa\Live\Components\Form\CheckboxComponent();
        $context = new ComponentContext('cb-1');
        $state = $checkbox->mount(['name' => 'approved'], $context);

        $this->expectException(\InvalidArgumentException::class);
        $renderer->render($checkbox, new RenderRequest($context, ['name' => 'approved'], $state));
    }
}
