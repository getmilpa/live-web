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
use Milpa\Live\ValueObjects\RenderRequest;
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

    /**
     * ADR#8 (Server Truth First): the field error must reach the human
     * WITHOUT JavaScript. Before this test, the message only rode into the
     * `x-data` JSON blob; the visible `<p>` was `x-cloak`'d and its text
     * came exclusively from `x-text`, so a no-JS request never saw it.
     */
    public function testAFieldBindsTheRemoteFactoryWhenMarkedRemote(): void
    {
        // A `remote` field validates on the server on blur (greenhouse decisions/0189); it binds the remote
        // Alpine factory instead of the local one. Without the flag it stays local (zero-network).
        $renderer = new FormPrimitiveHtmlRenderer(new AlpineRuntimeAdapter(), new XhtmlStateTransferCodec());

        $remote = $renderer->render(new TextareaComponent(), new RenderRequest(
            context: new ComponentContext('composer-message', route: '/desktop/live'),
            props: ['name' => 'message', 'remote' => true],
        ));
        self::assertStringContainsString('x-data="milpaFieldRemote(', $remote->output);

        $local = $renderer->render(new TextareaComponent(), new RenderRequest(
            context: new ComponentContext('note', route: '/desktop/live'),
            props: ['name' => 'note'],
        ));
        self::assertStringContainsString('x-data="milpaField(', $local->output);
        self::assertStringNotContainsString('milpaFieldRemote', $local->output);
    }

    public function testInputRendersServerSideErrorMessageAsStaticTextWithoutJs(): void
    {
        $renderer = new FormPrimitiveHtmlRenderer(new AlpineRuntimeAdapter(), new XhtmlStateTransferCodec());

        $withError = $renderer->render(new InputComponent(), new RenderRequest(
            context: new ComponentContext('project-name-field', route: '/lab/form'),
            props: ['name' => 'project_name', 'label' => 'Project', 'error' => 'Nombre y correo son obligatorios & no pueden ir vacíos'],
        ));

        self::assertStringNotContainsString('x-cloak', $withError->output, 'x-cloak hides server truth until Alpine boots — ADR#8 forbids it on the error node');
        self::assertMatchesRegularExpression(
            '/<p class="mui-field__error"[^>]*>Nombre y correo son obligatorios &amp; no pueden ir vac[íi]os<\/p>/u',
            $withError->output,
            'the escaped error message must be static HTML text inside the <p>, not only inside the x-data JSON blob',
        );
        self::assertDoesNotMatchRegularExpression(
            '/<p class="mui-field__error"[^>]*(?<!:)\bhidden\b(?!=)[^>]*>Nombre/',
            $withError->output,
            'the error paragraph must NOT carry the bare hidden attribute when a server error is present (the "hidden" inside x-bind:hidden does not count)',
        );
        // With an error present the hidden-attribute slot is empty: the <p>'s attributes stay
        // single-spaced, never doubled (previously it emitted `id="..."  x-bind`).
        self::assertStringNotContainsString('  x-bind:hidden', $withError->output, 'no double space when the hidden slot is empty');

        $withoutError = $renderer->render(new InputComponent(), new RenderRequest(
            context: new ComponentContext('project-name-field-clean', route: '/lab/form'),
            props: ['name' => 'project_name', 'label' => 'Project'],
        ));

        self::assertMatchesRegularExpression(
            '/<p class="mui-field__error"[^>]*(?<!:)\bhidden\b(?!=)[^>]*><\/p>/',
            $withoutError->output,
            'with no error, the paragraph must be empty AND carry the bare hidden attribute — no dead visible box',
        );
    }

    /**
     * Same ADR#8 defect, checkbox structure (label/control markup differs
     * from input/textarea/select — the error node must still be honest).
     */
    public function testCheckboxRendersServerSideErrorMessageAsStaticTextWithoutJs(): void
    {
        $renderer = new FormPrimitiveHtmlRenderer(new AlpineRuntimeAdapter(), new XhtmlStateTransferCodec());

        $withError = $renderer->render(new CheckboxComponent(), new RenderRequest(
            context: new ComponentContext('approved-field', route: '/lab/form'),
            props: ['name' => 'approved', 'label' => 'Approved', 'error' => 'Debes aceptar los términos'],
        ));

        self::assertStringNotContainsString('x-cloak', $withError->output);
        self::assertMatchesRegularExpression(
            '/<p class="mui-field__error"[^>]*>Debes aceptar los t[ée]rminos<\/p>/u',
            $withError->output,
            'the checkbox error message must also be static HTML text, not only inside x-data',
        );
        self::assertDoesNotMatchRegularExpression(
            '/<p class="mui-field__error"[^>]*(?<!:)\bhidden\b(?!=)[^>]*>Debes/',
            $withError->output,
        );

        $withoutError = $renderer->render(new CheckboxComponent(), new RenderRequest(
            context: new ComponentContext('approved-field-clean', route: '/lab/form'),
            props: ['name' => 'approved', 'label' => 'Approved'],
        ));

        self::assertMatchesRegularExpression(
            '/<p class="mui-field__error"[^>]*(?<!:)\bhidden\b(?!=)[^>]*><\/p>/',
            $withoutError->output,
            'checkbox error paragraph must be hidden (no dead box) when there is no error',
        );
    }
}
