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
 * HTML {@see ComponentRendererInterface} for the `autocomplete` contract: an
 * Alpine-bound `<input>` + listbox pair driven by `milpaAutocomplete(...)`,
 * boot-configured with the component's source/endpoint/persistence options
 * and the signed state envelope the client echoes back on every interaction.
 */
final readonly class AutocompleteHtmlRenderer implements ComponentRendererInterface
{
    private TemplateRendererInterface $templates;

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
     * Renders one `autocomplete` component to HTML. Throws
     * `InvalidArgumentException` if `$component`'s contract name is anything
     * other than `"autocomplete"` — this renderer is intentionally single-
     * purpose, not a generic dispatcher.
     */
    public function render(ComponentDefinitionInterface $component, RenderRequest $request): RenderResult
    {
        $contract = $component::contract();
        if ($contract->name !== 'autocomplete') {
            throw new \InvalidArgumentException('AutocompleteHtmlRenderer only renders autocomplete components.');
        }

        return LiveEventEmitter::withRendering(
            $this->dispatcher,
            $contract->name,
            $request,
            function () use ($component, $request, $contract): RenderResult {
                $state = $request->state ?? $component->mount($request->props, $request->context);
                $label = (string) ($request->props['label'] ?? $state->meta['label'] ?? '');
                $name = (string) ($request->props['name'] ?? $state->meta['name'] ?? $state->componentId);
                $persistKey = (string) ($request->props['persistKey'] ?? $state->meta['persistKey'] ?? '');
                $source = (string) ($request->props['source'] ?? $state->meta['source'] ?? '');
                $endpoint = (string) ($request->props['endpoint'] ?? '');
                $multiple = (bool) ($state->meta['multiple'] ?? false);
                $inputName = $multiple ? $name . '[]' : $name;

                $options = [
                    'componentId' => $state->componentId,
                    'name' => $name,
                    'source' => $source,
                    'endpoint' => $endpoint,
                    'persistKey' => $persistKey,
                    'multiple' => $multiple,
                    'initialState' => $state->data,
                    'staticItems' => $request->props['staticItems'] ?? [],
                ];

                $rootAttributes = array_merge(
                    $this->client->rootAttributes($contract, $state),
                    [
                        'class' => 'mui-field',
                        'x-data' => 'milpaAutocomplete(' . json_encode($options, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ')',
                        'x-init' => 'init()',
                    ],
                );

                $inputId = $state->componentId . '-input';
                $listId = $state->componentId . '-listbox';
                return new RenderResult(
                    output: $this->templates->render($contract->defaultTemplate ?? 'components/autocomplete.latte', [
                        'componentId' => $state->componentId,
                        'stateEnvelope' => $this->codec->encodeState($state),
                        'rootAttrs' => Html::attrs($rootAttributes),
                        'labelHtml' => $label !== ''
                            ? sprintf('<label class="mui-field__label" for="%s">%s</label>', Html::escape($inputId), Html::escape($label))
                            : '',
                        'inputId' => $inputId,
                        'listId' => $listId,
                        'name' => $name,
                        'inputName' => $inputName,
                    ]),
                    state: $state,
                    assets: $this->client->assets(),
                    format: RenderTarget::HTML,
                );
            },
        );
    }
}
