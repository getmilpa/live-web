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
use Milpa\Live\Components\Form\InputComponent;
use Milpa\Live\Components\Form\TextareaComponent;
use Milpa\Live\DataSource\ArrayDataSource;
use Milpa\Live\DataSource\InMemoryDataSourceRegistry;
use Milpa\Live\Rendering\AutocompleteHtmlRenderer;
use Milpa\Live\Rendering\ComponentRendererRegistry;
use Milpa\Live\Rendering\XhtmlComponentCompiler;
use Milpa\Live\Runtime\InMemoryComponentRegistry;
use Milpa\Live\Tests\Fixtures\DeclaringHtmlRenderer;
use Milpa\Live\Transport\XhtmlStateTransferCodec;
use Milpa\Live\ValueObjects\ClientAssets;
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

    /** greenhouse decisions/0211: declared assets merge by URL across sibling nodes; the legacy bag keeps array_merge. */
    public function testCompileFragmentMergesDeclaredAssetsByUrlAndLeavesTheLegacyBagToArrayMerge(): void
    {
        $this->components->register('textarea', new TextareaComponent());
        $this->components->register('input', new InputComponent());
        $compiler = new XhtmlComponentCompiler($this->components, [
            'textarea' => new DeclaringHtmlRenderer(
                new ClientAssets(scripts: ['/plugins/a/a.js', '/shared/vendor.js'], styles: ['/plugins/a/a.css']),
                ['script' => '/milpa-live.js', 'from' => 'a'],
            ),
            'input' => new DeclaringHtmlRenderer(
                new ClientAssets(scripts: ['/shared/vendor.js', '/plugins/b/b.js'], styles: ['/plugins/a/a.css', '/plugins/b/b.css']),
                ['from' => 'b', 'extra' => 1],
            ),
        ]);

        $compiled = $compiler->compileFragment(
            '<milpa:textarea name="a" /><milpa:input name="b" /><milpa:textarea name="c" />',
            $this->context,
        );

        self::assertSame(['/plugins/a/a.js', '/shared/vendor.js', '/plugins/b/b.js'], $compiled->clientAssets()->scripts, 'each URL once, first-seen order');
        self::assertSame(['/plugins/a/a.css', '/plugins/b/b.css'], $compiled->clientAssets()->styles);
        self::assertSame(['script' => '/milpa-live.js', 'from' => 'a', 'extra' => 1], $compiled->assets, 'the legacy string-keyed bag is still array_merge — the last node (a) wins the shared key, untouched semantics');
        self::assertSame(3, substr_count($compiled->output, 'data-milpa-component-id='));
    }

    public function testCompileCollectsDeclaredAssetsFromNestedComponentsToo(): void
    {
        $this->components->register('textarea', new TextareaComponent());
        $this->components->register('input', new InputComponent());
        $compiler = new XhtmlComponentCompiler($this->components, [
            'textarea' => new DeclaringHtmlRenderer(new ClientAssets(scripts: ['/outer.js'])),
            'input' => new DeclaringHtmlRenderer(new ClientAssets(scripts: ['/inner.js'], styles: ['/inner.css'])),
        ]);

        $compiled = $compiler->compile('<milpa:textarea name="outer"><milpa:input name="inner" /></milpa:textarea>', $this->context);

        self::assertStringContainsString('data-milpa-component-id="inner"', $compiled->output);
        self::assertSame(['/outer.js', '/inner.js'], $compiled->clientAssets()->scripts, 'a nested component\'s renderer declares too');
        self::assertSame(['/inner.css'], $compiled->clientAssets()->styles);
    }

    public function testARendererThatDeclaresNothingYieldsEmptyClientAssets(): void
    {
        $compiler = new XhtmlComponentCompiler($this->components, ['autocomplete' => $this->renderer]);

        $compiled = $compiler->compile('<milpa:autocomplete name="customer" source="customers.search" />', $this->context);

        self::assertTrue($compiled->clientAssets()->isEmpty());
        self::assertSame(['script' => '/milpa-live.js'], $compiled->assets, 'the legacy bag is what it always was');
    }

    public function testTheCompilerResolvesRenderersThroughAComponentRendererRegistry(): void
    {
        $this->components->register('textarea', new TextareaComponent());
        $registry = new ComponentRendererRegistry();
        $registry->registerFor('textarea', new DeclaringHtmlRenderer(new ClientAssets(scripts: ['/plugins/a/a.js'])));
        $compiler = new XhtmlComponentCompiler($this->components, $registry);

        $compiled = $compiler->compile('<milpa:textarea name="a" />', $this->context);

        self::assertStringContainsString('data-milpa-component-id="a"', $compiled->output);
        self::assertSame(['/plugins/a/a.js'], $compiled->clientAssets()->scripts);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No renderer registered for component: autocomplete');
        $compiler->compile('<milpa:autocomplete name="customer" source="customers.search" />', $this->context);
    }
}
