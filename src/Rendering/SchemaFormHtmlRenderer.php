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

namespace Milpa\Live\Rendering;

use Milpa\Live\Adapters\Alpine\AlpineRuntimeAdapter;
use Milpa\Live\Components\Form\CheckboxComponent;
use Milpa\Live\Components\Form\InputComponent;
use Milpa\Live\Components\Form\SelectComponent;
use Milpa\Live\Runtime\InMemoryComponentRegistry;
use Milpa\Live\Schema\FieldError;
use Milpa\Live\Schema\FieldType;
use Milpa\Live\Schema\FormField;
use Milpa\Live\Schema\FormView;
use Milpa\Live\Transport\XhtmlStateTransferCodec;
use Milpa\Live\ValueObjects\ComponentContext;

/**
 * Renders a FormView to the styled milpa/live form-widget HTML fragment — FIELDS ONLY, never the
 * `<form>` wrapper (that is transport-specific host glue owned by a later task). Each FieldType maps
 * to a milpa/live form component (Boolean -> checkbox, an `enumOptions` constraint -> select, else ->
 * text/number input); current values and per-field error messages ride as tag attributes into the
 * real {@see FormPrimitiveHtmlRenderer}/{@see XhtmlComponentCompiler} pipeline, so the emitted markup
 * carries the same Alpine directives — and the same no-JS degradation story — as any other
 * milpa/live form widget (ADR#6, progressive enhancement).
 */
final class SchemaFormHtmlRenderer
{
    /** @var ?\Closure(FieldError, FormField): string */
    private readonly ?\Closure $messageResolver;

    /**
     * The resolver turns a field's first validation error into the message the user reads. It gets
     * the `FieldError` — switch on its stable `code`, a closed set — and the `FormField`, for its
     * `label`. Left null it returns the error's own English `message`, which is the prior behavior
     * and keeps this constructor backward compatible.
     *
     * A host in another language injects one keyed on `code`, so the UI never pairs a translated
     * label with an English suffix ("Nombre del sitio is required.").
     *
     * @param ?callable(FieldError, FormField): string $messageResolver
     */
    public function __construct(?callable $messageResolver = null)
    {
        $this->messageResolver = $messageResolver === null ? null : \Closure::fromCallable($messageResolver);
    }

    /**
     * Renders one `<milpa:*>` fragment tag per field in `$view->definition`, compiled through the
     * real form-primitive rendering pipeline, with current values and first-error messages injected.
     */
    public function render(FormView $view): string
    {
        $formRenderer = new FormPrimitiveHtmlRenderer(new AlpineRuntimeAdapter(), new XhtmlStateTransferCodec());

        $components = new InMemoryComponentRegistry();
        $components->register('input', new InputComponent());
        $components->register('select', new SelectComponent());
        $components->register('checkbox', new CheckboxComponent());

        $renderers = [
            'input' => $formRenderer,
            'select' => $formRenderer,
            'checkbox' => $formRenderer,
        ];

        $markup = '';
        foreach ($view->definition->fields as $field) {
            $markup .= $this->fieldMarkup($field, $view);
        }

        $compiler = new XhtmlComponentCompiler($components, $renderers);
        $result = $compiler->compileFragment($markup, new ComponentContext('settings-form', route: '/milpa/admin'));

        return $result->output;
    }

    /** Builds the `<milpa:input|select|checkbox>` tag for one field, with value/error attributes. */
    private function fieldMarkup(FormField $field, FormView $view): string
    {
        $name = $field->name;
        $value = $view->values[$name] ?? $field->default;
        $firstError = $view->validation->errors[$name][0] ?? null;
        $errorMessage = $firstError === null
            ? null
            : ($this->messageResolver !== null ? ($this->messageResolver)($firstError, $field) : $firstError->message);

        $attributes = 'name="' . self::attr($name) . '" label="' . self::attr($field->label) . '"';
        if ($field->required) {
            $attributes .= ' required="true"';
        }
        if ($errorMessage !== null) {
            $attributes .= ' error="' . self::attr($errorMessage) . '"';
        }

        if ($field->type === FieldType::Boolean) {
            $checkedAttr = $value ? ' checked="true"' : '';

            return '<milpa:checkbox ' . $attributes . $checkedAttr . ' value="1" />';
        }

        $enumOptions = $field->constraints->enumOptions;
        if ($enumOptions !== null) {
            $optionsJson = (string) json_encode(array_map(
                static fn (int|float|string $option): array => ['value' => (string) $option, 'label' => (string) $option],
                $enumOptions,
            ), \JSON_THROW_ON_ERROR);
            $valueAttr = $value !== null ? ' value="' . self::attr((string) $value) . '"' : '';

            return '<milpa:select ' . $attributes . ' options="' . self::attr($optionsJson) . '"' . $valueAttr . ' />';
        }

        $type = $field->type === FieldType::Text ? 'text' : 'number';
        $valueAttr = $value !== null ? ' value="' . self::attr((string) $value) . '"' : '';

        return '<milpa:input ' . $attributes . ' type="' . $type . '"' . $valueAttr . ' />';
    }

    private static function attr(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_XML1, 'UTF-8');
    }
}
