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
use Milpa\Live\Components\Form\CheckboxComponent;
use Milpa\Live\Components\Form\InputComponent;
use Milpa\Live\Components\Form\SelectComponent;
use Milpa\Live\Components\Form\TextareaComponent;
use Milpa\Live\DataSource\ArrayDataSource;
use Milpa\Live\DataSource\InMemoryDataSourceRegistry;
use Milpa\Live\Rendering\AutocompleteHtmlRenderer;
use Milpa\Live\Rendering\FormPrimitiveHtmlRenderer;
use Milpa\Live\Rendering\XhtmlComponentCompiler;
use Milpa\Live\Runtime\InMemoryComponentRegistry;
use Milpa\Live\Transport\XhtmlStateTransferCodec;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\RenderTarget;
use PHPUnit\Framework\TestCase;

/**
 * Converted from tests/smoke.php lines ~988-1032 — the form primitive HTML
 * renderer, composed via {@see XhtmlComponentCompiler} alongside the
 * autocomplete renderer in the same fragment (the compiler's real, shared
 * multi-component use case).
 */
final class FormPrimitiveHtmlRendererTest extends TestCase
{
    public function testSupportsTargetIsHtmlOnly(): void
    {
        $renderer = new FormPrimitiveHtmlRenderer(new AlpineRuntimeAdapter(), new XhtmlStateTransferCodec());

        self::assertTrue($renderer->supportsTarget(RenderTarget::HTML));
        self::assertFalse($renderer->supportsTarget(RenderTarget::TUI));
    }

    public function testCompileFragmentRendersAllFourFormPrimitivesPlusAutocomplete(): void
    {
        $codec = new XhtmlStateTransferCodec();
        $sources = new InMemoryDataSourceRegistry();
        $sources->register(new ArrayDataSource('customers.search', [
            ['value' => 'acme', 'label' => 'Acme Studio'],
        ]));
        $autocomplete = new AutocompleteComponent($sources);
        $autocompleteRenderer = new AutocompleteHtmlRenderer(new AlpineRuntimeAdapter(), $codec);
        $formRenderer = new FormPrimitiveHtmlRenderer(new AlpineRuntimeAdapter(), $codec);

        $components = new InMemoryComponentRegistry();
        $components->register('input', new InputComponent());
        $components->register('textarea', new TextareaComponent());
        $components->register('select', new SelectComponent());
        $components->register('checkbox', new CheckboxComponent());
        $components->register('autocomplete', $autocomplete);

        $compiler = new XhtmlComponentCompiler(
            $components,
            [
                'input' => $formRenderer,
                'textarea' => $formRenderer,
                'select' => $formRenderer,
                'checkbox' => $formRenderer,
                'autocomplete' => $autocompleteRenderer,
            ],
            [
                'select' => [
                    'options' => [
                        ['value' => 'discovery', 'label' => 'Discovery'],
                        ['value' => 'prototype', 'label' => 'Prototype'],
                    ],
                ],
            ],
        );

        $compiled = $compiler->compileFragment(
            <<<'XHTML'
<milpa:input name="project_name" label="Project" required="true" />
<milpa:textarea name="brief" label="Brief" rows="4" />
<milpa:select name="stage" label="Stage" placeholder="Pick one" />
<milpa:autocomplete name="customers" label="Customers" source="customers.search" multiple="true" />
<milpa:checkbox name="approved" label="Approved" value="1" />
XHTML,
            new ComponentContext('form-prototype', route: '/lab/form'),
        );

        self::assertStringContainsString('milpaField(', $compiled->output);
        self::assertStringContainsString('milpaCheckbox(', $compiled->output);
        self::assertStringContainsString('<textarea', $compiled->output);
        self::assertStringContainsString('<select', $compiled->output);
        self::assertStringContainsString('mui-select-wrap', $compiled->output);
        self::assertStringContainsString('type="checkbox"', $compiled->output);
        self::assertStringContainsString('mui-choice', $compiled->output);
        self::assertStringContainsString('name="project_name"', $compiled->output);
        self::assertStringContainsString('value="prototype"', $compiled->output, 'select defaults must render as options');
    }
}
