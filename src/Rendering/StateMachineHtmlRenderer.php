<?php

/**
 * This file is part of Milpa Live Web — the HTML render target of the Milpa live component core.
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
 * HTML {@see ComponentRendererInterface} for the `state-machine` contract — the counterpart the dashboard
 * renderer never was (greenhouse decisions/0164). `DashboardHtmlRenderer` renders dashboard primitives and
 * throws for everything else, so a state-machine page had no server renderer; this is it, single-component
 * on purpose (it throws for any contract but `state-machine`), so the page controller dispatches to it by
 * contract name.
 *
 * A state-machine is DECLARABLE by data (its `machine` — initial + transitions — arrives in the props,
 * greenhouse decisions/0095), so this renders the CURRENT state and the transitions available from it as
 * actions the live wire fires over `POST {route}`. The server owns the state; the transition buttons carry
 * the action name for the client to fire and for the endpoint to advance and re-sign.
 */
final readonly class StateMachineHtmlRenderer implements ComponentRendererInterface
{
    private const CONTRACT = 'state-machine';

    private TemplateRendererInterface $templates;

    public function __construct(
        private ClientRuntimeAdapterInterface $client,
        private StateTransferCodecInterface $codec,
        ?TemplateRendererInterface $templates = null,
        private ?MilpaEventDispatcherInterface $dispatcher = null,
    ) {
        $this->templates = $templates ?? new LatteTemplateRenderer();
    }

    /** This renderer targets HTML only. */
    public function supportsTarget(RenderTarget $target): bool
    {
        return $target === RenderTarget::HTML;
    }

    /**
     * Render one `state-machine` component to HTML: the signed state envelope plus the current state and the
     * transitions available from it. Throws when handed any contract other than `state-machine` — this
     * renderer is intentionally single-family, and the page controller dispatches to it by contract name.
     */
    public function render(ComponentDefinitionInterface $component, RenderRequest $request): RenderResult
    {
        $contract = $component::contract();
        if ($contract->name !== self::CONTRACT) {
            throw new \InvalidArgumentException('StateMachineHtmlRenderer only renders state-machine components.');
        }

        return LiveEventEmitter::withRendering(
            $this->dispatcher,
            $contract->name,
            $request,
            function () use ($component, $request): RenderResult {
                $state = $request->state ?? $component->mount($request->props, $request->context);
                $stateEnvelope = $this->codec->encodeState($state);

                $current = (string) ($state->data['state'] ?? '');
                $machine = \is_array($state->meta['machine'] ?? null) ? $state->meta['machine'] : [];
                $fromHere = \is_array($machine['transitions'][$current] ?? null) ? $machine['transitions'][$current] : [];
                $transitions = array_values(array_filter(array_keys($fromHere), 'is_string'));

                $html = $this->templates->render('components/state-machine.latte', [
                    'componentId' => $state->componentId,
                    'stateEnvelope' => $stateEnvelope,
                    'rootAttrs' => Html::attrs([
                        'class' => 'mui-state-machine',
                        'data-milpa-component-id' => $state->componentId,
                        'data-milpa-state-name' => $current,
                    ]),
                    'current' => $current,
                    'transitions' => $transitions,
                ]);

                return new RenderResult(
                    output: $html,
                    state: $state,
                    assets: $this->client->assets(),
                    format: RenderTarget::HTML,
                );
            },
        );
    }
}
