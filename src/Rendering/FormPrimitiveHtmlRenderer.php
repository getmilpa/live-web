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

use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Live\Contracts\Client\ClientRuntimeAdapterInterface;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\Contracts\Rendering\ComponentRendererInterface;
use Milpa\Live\Contracts\Rendering\TemplateRendererInterface;
use Milpa\Live\Contracts\Transport\StateTransferCodecInterface;
use Milpa\Live\Events\LiveEventEmitter;
use Milpa\Live\Support\Html;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\RenderResult;
use Milpa\Live\ValueObjects\RenderTarget;

/**
 * HTML {@see ComponentRendererInterface} for the form-field primitive
 * contract family — `input`, `textarea`, `select`, `checkbox`. Renders each
 * field as an Alpine-bound control (`milpaField(...)` / `milpaCheckbox(...)`)
 * wired to the signed state envelope, with shared label/hint/`aria-describedby`
 * wiring across all four field types.
 */
final readonly class FormPrimitiveHtmlRenderer implements ComponentRendererInterface
{
    private TemplateRendererInterface $templates;

    /**
     * @var array<int, string>
     */
    private const SUPPORTED = ['input', 'textarea', 'select', 'checkbox'];

    public function __construct(
        private ClientRuntimeAdapterInterface $client,
        private StateTransferCodecInterface $codec,
        ?TemplateRendererInterface $templates = null,
        private ?MilpaEventDispatcherInterface $dispatcher = null,
    ) {
        $this->templates = $templates ?? new LatteTemplateRenderer();
    }

    /**
     * True only for {@see RenderTarget::HTML} — this renderer produces
     * server-rendered markup, not another target format.
     */
    public function supportsTarget(RenderTarget $target): bool
    {
        return $target === RenderTarget::HTML;
    }

    /**
     * Renders one form-field-primitive component to HTML. Throws
     * `InvalidArgumentException` if `$component`'s contract name is outside
     * {@see self::SUPPORTED} (`input`, `textarea`, `select`, `checkbox`).
     */
    public function render(ComponentDefinitionInterface $component, RenderRequest $request): RenderResult
    {
        $contract = $component::contract();
        if (!in_array($contract->name, self::SUPPORTED, true)) {
            throw new \InvalidArgumentException('FormPrimitiveHtmlRenderer only renders form field primitives.');
        }

        return LiveEventEmitter::withRendering(
            $this->dispatcher,
            $contract->name,
            $request,
            function () use ($component, $request, $contract): RenderResult {
                $state = $request->state ?? $component->mount($request->props, $request->context);
                $name = (string) ($request->props['name'] ?? $state->meta['name'] ?? $state->componentId);
                $label = (string) ($request->props['label'] ?? $state->meta['label'] ?? '');
                $hint = (string) ($request->props['hint'] ?? $state->meta['hint'] ?? '');
                $persistKey = (string) ($request->props['persistKey'] ?? $state->meta['persistKey'] ?? '');
                $required = $this->bool($request->props['required'] ?? $state->meta['required'] ?? false);
                $disabled = $this->bool($request->props['disabled'] ?? $state->meta['disabled'] ?? false);
                $fieldId = $state->componentId . '-field';
                $hintId = $fieldId . '-hint';
                $errorId = $fieldId . '-error';
                $describedBy = trim(($hint !== '' ? $hintId : '') . ' ' . $errorId);
                $valueKey = $contract->name === 'checkbox' ? 'checked' : 'value';

                $options = [
                    'componentId' => $state->componentId,
                    'name' => $name,
                    'persistKey' => $persistKey,
                    'storage' => (string) ($request->props['storage'] ?? $state->meta['storage'] ?? 'local'),
                    'initialState' => $state->data,
                    'value' => $state->data[$valueKey] ?? ($valueKey === 'checked' ? false : ''),
                ];

                $rootAttributes = array_merge(
                    $this->client->rootAttributes($contract, $state),
                    [
                        'class' => 'mui-field mui-field--' . $contract->name,
                        'x-data' => ($contract->name === 'checkbox' ? 'milpaCheckbox(' : 'milpaField(')
                            . json_encode($options, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                        . ')',
                        'x-init' => 'init()',
                    ],
                );
                if ($contract->name === 'checkbox') {
                    $rootAttributes['class'] .= ' mui-field--row';
                }

                $params = [
                    'componentId' => $state->componentId,
                    'stateEnvelope' => $this->codec->encodeState($state),
                    'rootAttrs' => Html::attrs($rootAttributes),
                    'labelHtml' => $contract->name !== 'checkbox' && $label !== '' ? $this->label($fieldId, $label) : '',
                    'hintHtml' => $hint !== ''
                        ? '<p class="mui-field__hint" id="' . Html::escape($hintId) . '">' . Html::escape($hint) . '</p>'
                        : '',
                    'errorId' => $errorId,
                ];

                $params += match ($contract->name) {
                    'input' => [
                        'controlAttrs' => $this->inputAttrs($fieldId, $name, $state->data['value'] ?? '', $request->props, $state->meta, $required, $disabled, $describedBy),
                    ],
                    'textarea' => [
                        'controlAttrs' => $this->textareaAttrs($fieldId, $name, $request->props, $state->meta, $required, $disabled, $describedBy),
                        'value' => (string) ($state->data['value'] ?? ''),
                    ],
                    'select' => [
                        'controlAttrs' => $this->selectAttrs($fieldId, $name, $request->props, $state->meta, $required, $disabled, $describedBy),
                        'optionsHtml' => $this->optionsHtml($state->data['value'] ?? '', $request->props, $state->meta),
                    ],
                    'checkbox' => [
                        'fieldId' => $fieldId,
                        'name' => $name,
                        'label' => $label,
                        'controlAttrs' => $this->checkboxAttrs($fieldId, $name, $state->data['checked'] ?? false, $request->props, $state->meta, $required, $disabled, $describedBy),
                    ],
                };

                return new RenderResult(
                    output: $this->templates->render($contract->defaultTemplate ?? 'components/' . $contract->name . '.latte', $params),
                    state: $state,
                    assets: $this->client->assets(),
                    format: RenderTarget::HTML,
                );
            },
        );
    }

    /**
     * @param array<string, mixed> $props
     * @param array<string, mixed> $meta
     */
    private function inputAttrs(
        string $fieldId,
        string $name,
        mixed $value,
        array $props,
        array $meta,
        bool $required,
        bool $disabled,
        string $describedBy,
    ): string {
        return Html::attrs([
            'class' => 'mui-input',
            'id' => $fieldId,
            'name' => $name,
            'type' => (string) ($props['type'] ?? $meta['type'] ?? 'text'),
            'value' => (string) $value,
            'placeholder' => (string) ($props['placeholder'] ?? $meta['placeholder'] ?? ''),
            'required' => $required,
            'disabled' => $disabled,
            'aria-describedby' => $describedBy,
            'x-model' => 'value',
            '@input' => 'change($event.target.value)',
            '@blur' => 'blur()',
            ':aria-invalid' => "error ? 'true' : 'false'",
        ]);
    }

    /**
     * @param array<string, mixed> $props
     * @param array<string, mixed> $meta
     */
    private function textareaAttrs(
        string $fieldId,
        string $name,
        array $props,
        array $meta,
        bool $required,
        bool $disabled,
        string $describedBy,
    ): string {
        return Html::attrs([
            'class' => 'mui-textarea',
            'id' => $fieldId,
            'name' => $name,
            'rows' => max(1, (int) ($props['rows'] ?? $meta['rows'] ?? 4)),
            'placeholder' => (string) ($props['placeholder'] ?? $meta['placeholder'] ?? ''),
            'required' => $required,
            'disabled' => $disabled,
            'aria-describedby' => $describedBy,
            'x-model' => 'value',
            '@input' => 'change($event.target.value)',
            '@blur' => 'blur()',
            ':aria-invalid' => "error ? 'true' : 'false'",
        ]);
    }

    /**
     * @param array<string, mixed> $props
     * @param array<string, mixed> $meta
     */
    private function selectAttrs(
        string $fieldId,
        string $name,
        array $props,
        array $meta,
        bool $required,
        bool $disabled,
        string $describedBy,
    ): string {
        return Html::attrs([
            'class' => 'mui-select',
            'id' => $fieldId,
            'name' => $name,
            'required' => $required,
            'disabled' => $disabled,
            'aria-describedby' => $describedBy,
            'x-model' => 'value',
            '@change' => 'change($event.target.value)',
            '@blur' => 'blur()',
            ':aria-invalid' => "error ? 'true' : 'false'",
        ]);
    }

    /**
     * @param array<string, mixed> $props
     * @param array<string, mixed> $meta
     */
    private function optionsHtml(mixed $value, array $props, array $meta): string
    {
        $placeholder = (string) ($props['placeholder'] ?? $meta['placeholder'] ?? '');
        $html = [];

        if ($placeholder !== '') {
            $html[] = '<option value="">' . Html::escape($placeholder) . '</option>';
        }

        foreach ($this->options($props['options'] ?? $meta['options'] ?? []) as $option) {
            $html[] = '<option ' . Html::attrs([
                'value' => $option['value'],
                'selected' => (string) $value === $option['value'],
                'disabled' => $option['disabled'],
            ]) . '>' . Html::escape($option['label']) . '</option>';
        }

        return implode("\n", $html);
    }

    /**
     * @param array<string, mixed> $props
     * @param array<string, mixed> $meta
     */
    private function checkboxAttrs(
        string $fieldId,
        string $name,
        mixed $checked,
        array $props,
        array $meta,
        bool $required,
        bool $disabled,
        string $describedBy,
    ): string {
        return Html::attrs([
            'class' => 'mui-checkbox',
            'id' => $fieldId,
            'name' => $name,
            'type' => 'checkbox',
            'value' => (string) ($props['value'] ?? $meta['value'] ?? '1'),
            'checked' => $this->bool($checked),
            'required' => $required,
            'disabled' => $disabled,
            'aria-describedby' => $describedBy,
            'x-model' => 'checked',
            '@change' => 'change($event.target.checked)',
            '@blur' => 'blur()',
            ':aria-invalid' => "error ? 'true' : 'false'",
        ]);
    }

    private function label(string $fieldId, string $label): string
    {
        return sprintf(
            '<label class="mui-field__label" for="%s">%s</label>',
            Html::escape($fieldId),
            Html::escape($label),
        );
    }

    /**
     * @return array<int, array{value: string, label: string, disabled: bool}>
     */
    private function options(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($raw)) {
            return [];
        }

        $options = [];
        foreach ($raw as $key => $option) {
            if (is_array($option)) {
                $options[] = [
                    'value' => (string) ($option['value'] ?? $key),
                    'label' => (string) ($option['label'] ?? $option['value'] ?? $key),
                    'disabled' => $this->bool($option['disabled'] ?? false),
                ];
                continue;
            }

            $options[] = [
                'value' => (string) $key,
                'label' => (string) $option,
                'disabled' => false,
            ];
        }

        return $options;
    }

    private function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['', '1', 'true', 'yes', 'checked', 'required', 'disabled'], true);
        }

        return (bool) $value;
    }
}
