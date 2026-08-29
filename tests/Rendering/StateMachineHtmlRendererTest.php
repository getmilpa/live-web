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
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\ValueObjects\ComponentContract;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\InteractionResult;
use Milpa\Live\ValueObjects\StateSnapshot;
use Milpa\Live\Rendering\StateMachineHtmlRenderer;
use Milpa\Live\Transport\XhtmlStateTransferCodec;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\RenderTarget;
use PHPUnit\Framework\TestCase;

/**
 * The renderer that gave the `state-machine` contract a server-side HTML page (greenhouse decisions/0164):
 * a state-machine is declared by data (its machine — initial + transitions), so this renders the current
 * state and the transitions available from it. Before it, `DashboardHtmlRenderer` threw for state-machines
 * and the page had no renderer at all.
 */
final class StateMachineHtmlRendererTest extends TestCase
{
    private function stateMachine(): ComponentDefinitionInterface
    {
        return new class () implements ComponentDefinitionInterface {
            public static function contract(): ComponentContract
            {
                return new ComponentContract('state-machine', '1.0');
            }

            public function mount(array $props, \Milpa\Live\ValueObjects\ComponentContext $context): StateSnapshot
            {
                $machine = \is_array($props['machine'] ?? null) ? $props['machine'] : [];
                return new StateSnapshot(
                    $context->componentId,
                    'state-machine',
                    '1.0',
                    data: ['state' => (string) ($machine['initial'] ?? '')],
                    meta: ['machine' => $machine],
                );
            }

            public function handle(InteractionRequest $request): InteractionResult
            {
                throw new \LogicException('not exercised by the renderer');
            }
        };
    }

    private function otherComponent(): ComponentDefinitionInterface
    {
        return new class () implements ComponentDefinitionInterface {
            public static function contract(): ComponentContract
            {
                return new ComponentContract('data-table', '1.0');
            }

            public function mount(array $props, \Milpa\Live\ValueObjects\ComponentContext $context): StateSnapshot
            {
                return new StateSnapshot($context->componentId, 'data-table', '1.0');
            }

            public function handle(InteractionRequest $request): InteractionResult
            {
                throw new \LogicException('not exercised by the renderer');
            }
        };
    }

    /** @return array<string, mixed> */
    private function declaredMachine(): array
    {
        return ['machine' => [
            'initial' => 'draft',
            'transitions' => [
                'draft' => ['publish' => ['to' => 'live']],
                'live' => ['retire' => ['to' => 'archived']],
                'archived' => [],
            ],
        ]];
    }

    public function testRenderProducesTheCurrentStateItsTransitionsAndASignedEnvelope(): void
    {
        $component = $this->stateMachine();
        $context = new ComponentContext('flujo', principal: 'user:1', route: '/live');
        $props = $this->declaredMachine();
        $state = $component->mount($props, $context);

        $renderer = new StateMachineHtmlRenderer(new AlpineRuntimeAdapter(), new XhtmlStateTransferCodec());
        $rendered = $renderer->render($component, new RenderRequest($context, $props, $state));

        self::assertSame(RenderTarget::HTML, $rendered->format);
        self::assertStringContainsString('draft', $rendered->output, 'the current state is rendered');
        self::assertStringContainsString('publish', $rendered->output, 'the transition available from draft is rendered');
        self::assertStringContainsString('data-milpa-action="publish"', $rendered->output);
        self::assertStringContainsString('data-milpa-state="flujo"', $rendered->output, 'the signed state envelope is embedded');
        self::assertStringNotContainsString('retire', $rendered->output, 'only transitions from the CURRENT state are offered');
    }

    public function testSupportsTargetIsHtmlOnly(): void
    {
        $renderer = new StateMachineHtmlRenderer(new AlpineRuntimeAdapter(), new XhtmlStateTransferCodec());

        self::assertTrue($renderer->supportsTarget(RenderTarget::HTML));
        self::assertFalse($renderer->supportsTarget(RenderTarget::TUI));
    }

    public function testItRefusesANonStateMachineComponent(): void
    {
        $renderer = new StateMachineHtmlRenderer(new AlpineRuntimeAdapter(), new XhtmlStateTransferCodec());
        $component = $this->otherComponent();
        $context = new ComponentContext('t', route: '/live');

        $this->expectException(\InvalidArgumentException::class);
        $renderer->render($component, new RenderRequest($context, [], $component->mount([], $context)));
    }
}
