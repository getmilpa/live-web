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
use Milpa\Live\Rendering\XhtmlComponentCompiler;
use Milpa\Live\Runtime\InMemoryComponentRegistry;
use Milpa\Live\Transport\XhtmlStateTransferCodec;
use Milpa\Live\ValueObjects\ComponentContext;
use PHPUnit\Framework\TestCase;

/**
 * Converted from tests/smoke.php lines ~888-903 — the `<milpa:x>`/`<milpa-x>`
 * XHTML markup compiler.
 */
final class XhtmlComponentCompilerTest extends TestCase
{
    private InMemoryComponentRegistry $components;
    private AutocompleteHtmlRenderer $renderer;
    private ComponentContext $context;

    protected function setUp(): void
    {
        $sources = new InMemoryDataSourceRegistry();
        $sources->register(new ArrayDataSource('customers.search', [
            ['value' => 'acme', 'label' => 'Acme Studio'],
        ]));

        $this->components = new InMemoryComponentRegistry();
        $this->components->register('autocomplete', new AutocompleteComponent($sources));
        $this->renderer = new AutocompleteHtmlRenderer(new AlpineRuntimeAdapter(), new XhtmlStateTransferCodec());
        $this->context = new ComponentContext('form-prototype', route: '/lab/form');
    }

    public function testCompileSupportsTheMilpaPrefixedTag(): void
    {
        $compiler = new XhtmlComponentCompiler($this->components, ['autocomplete' => $this->renderer]);

        $compiled = $compiler->compile(
            '<milpa:autocomplete name="customer" label="Customer" source="customers.search" persist-key="demo.customer" multiple="true" />',
            $this->context,
        );

        self::assertStringContainsString('data-milpa-component="autocomplete"', $compiled->output);
        self::assertStringContainsString('name="customer_label"', $compiled->output, 'dash-case attrs must compile to camelCase renderer props');
    }

    public function testCompileSupportsTheMilpaDashFallbackTag(): void
    {
        $compiler = new XhtmlComponentCompiler($this->components, ['autocomplete' => $this->renderer]);

        $compiled = $compiler->compile(
            '<milpa-autocomplete name="customer" label="Customer" source="customers.search" persist-key="demo.customer" multiple="true" />',
            $this->context,
        );

        self::assertStringContainsString('milpaAutocomplete(', $compiled->output);
        self::assertStringContainsString('mui-selection-tray', $compiled->output);
        self::assertStringContainsString('mui-badge', $compiled->output);
        self::assertStringContainsString('x-for="item in selected"', $compiled->output);
    }

    public function testCompileRejectsMultipleRootElements(): void
    {
        $compiler = new XhtmlComponentCompiler($this->components, ['autocomplete' => $this->renderer]);

        $this->expectException(\RuntimeException::class);
        $compiler->compile(
            '<milpa:autocomplete name="a" source="customers.search" /><milpa:autocomplete name="b" source="customers.search" />',
            $this->context,
        );
    }

    public function testCompileRejectsAnUnregisteredComponentTag(): void
    {
        $compiler = new XhtmlComponentCompiler($this->components, ['autocomplete' => $this->renderer]);

        $this->expectException(\RuntimeException::class);
        $compiler->compile('<milpa:does-not-exist name="x" />', $this->context);
    }
}
