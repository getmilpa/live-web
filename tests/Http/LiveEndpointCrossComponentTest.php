<?php

/**
 * This file is part of Milpa Live Web — the HTTP/HTML transport layer for Milpa Live.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/live-web
 */

declare(strict_types=1);

namespace Milpa\Live\Tests\Http;

use Milpa\Live\Adapters\Alpine\AlpineRuntimeAdapter;
use Milpa\Live\Components\Form\TextareaComponent;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\Effects\DispatchEffect;
use Milpa\Live\Effects\RenderEffect;
use Milpa\Live\Http\LiveEndpoint;
use Milpa\Live\Http\LiveHttpRequest;
use Milpa\Live\Rendering\FormPrimitiveHtmlRenderer;
use Milpa\Live\Runtime\InMemoryComponentRegistry;
use Milpa\Live\Tests\Fixtures\TestSecurityWiring;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\ComponentContract;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\InteractionResult;
use Milpa\Live\ValueObjects\StateSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * Cross-component render effects (greenhouse decisions/0189, evidence/0491): a handler DECLARES that another
 * component re-paints, and the endpoint renders that target component into the response — declared behaviour,
 * no imperative wiring.
 */
final class LiveEndpointCrossComponentTest extends TestCase
{
    public function testAHandlerCanDeclareThatAnotherComponentRepaints(): void
    {
        $noncePath = sys_get_temp_dir() . '/milpa-live-web-cross-' . bin2hex(random_bytes(6)) . '.json';
        $codec = TestSecurityWiring::stateCodec($noncePath);
        $csrf = TestSecurityWiring::csrfGuard();

        // Component A: on `paint`, it declares that component B (a textarea) re-paints with a new value.
        $trigger = new class () implements ComponentDefinitionInterface {
            public static function contract(): ComponentContract
            {
                return new ComponentContract(name: 'trigger', contractVersion: '1', actions: ['paint' => ['payload' => []]]);
            }

            public function mount(array $props, ComponentContext $context): StateSnapshot
            {
                return new StateSnapshot($context->componentId, 'trigger', '1', ['ready' => true]);
            }

            public function handle(InteractionRequest $request): InteractionResult
            {
                return new InteractionResult(
                    $request->state,
                    effects: [(new RenderEffect(target: 'field-b', component: 'textarea', props: ['name' => 'b', 'value' => 'painted from A']))->toArray()],
                );
            }
        };

        $components = new InMemoryComponentRegistry();
        $components->register('trigger', $trigger);
        $components->register('textarea', new TextareaComponent());

        $endpoint = new LiveEndpoint(
            components: $components,
            codec: $codec,
            authorizer: TestSecurityWiring::authorizer($components),
            csrf: $csrf,
            route: TestSecurityWiring::ROUTE,
            renderers: ['textarea' => new FormPrimitiveHtmlRenderer(new AlpineRuntimeAdapter(), $codec)],
        );

        $sessionId = 'sess-cross-1';
        $envelope = $codec->encodeState($trigger->mount([], new ComponentContext('trigger-1', route: TestSecurityWiring::ROUTE)));
        $response = $endpoint->handle(new LiveHttpRequest(
            method: 'POST',
            action: 'paint',
            stateEnvelope: $envelope,
            payload: [],
            sessionId: $sessionId,
            csrfToken: $csrf->issueToken($sessionId, TestSecurityWiring::ROUTE),
        ));

        self::assertSame(200, $response->status);
        $effects = $response->body['effects'] ?? [];
        self::assertCount(1, $effects);
        self::assertSame('render', $effects[0]['type']);
        self::assertSame('field-b', $effects[0]['target']);
        // The endpoint rendered the target component: its HTML carries the target id and the new value.
        self::assertStringContainsString('data-milpa-component-id="field-b"', $effects[0]['html']);
        self::assertStringContainsString('painted from A', $effects[0]['html']);
        // The declaration was resolved into HTML — the raw {component, props} shape is gone.
        self::assertArrayNotHasKey('component', $effects[0]);

        if (is_file($noncePath)) {
            unlink($noncePath);
        }
    }

    public function testADispatchEffectTravelsThroughToTheClientUntouched(): void
    {
        $noncePath = sys_get_temp_dir() . '/milpa-live-web-dispatch-' . bin2hex(random_bytes(6)) . '.json';
        $codec = TestSecurityWiring::stateCodec($noncePath);
        $csrf = TestSecurityWiring::csrfGuard();

        $trigger = new class () implements ComponentDefinitionInterface {
            public static function contract(): ComponentContract
            {
                return new ComponentContract(name: 'trigger', contractVersion: '1', actions: ['go' => ['payload' => []]]);
            }

            public function mount(array $props, ComponentContext $context): StateSnapshot
            {
                return new StateSnapshot($context->componentId, 'trigger', '1', ['ready' => true]);
            }

            public function handle(InteractionRequest $request): InteractionResult
            {
                return new InteractionResult($request->state, effects: [
                    (new DispatchEffect(to: 'weather-panel', event: 'refresh', payload: ['city' => 'CDMX']))->toArray(),
                ]);
            }
        };

        $components = new InMemoryComponentRegistry();
        $components->register('trigger', $trigger);

        $endpoint = new LiveEndpoint(
            components: $components,
            codec: $codec,
            authorizer: TestSecurityWiring::authorizer($components),
            csrf: $csrf,
            route: TestSecurityWiring::ROUTE,
            renderers: [],
        );

        $sessionId = 'sess-dispatch-1';
        $envelope = $codec->encodeState($trigger->mount([], new ComponentContext('trigger-d', route: TestSecurityWiring::ROUTE)));
        $response = $endpoint->handle(new LiveHttpRequest(
            method: 'POST',
            action: 'go',
            stateEnvelope: $envelope,
            payload: [],
            sessionId: $sessionId,
            csrfToken: $csrf->issueToken($sessionId, TestSecurityWiring::ROUTE),
        ));

        self::assertSame(200, $response->status);
        // A dispatch effect is client-side — the endpoint leaves it intact for the runtime to deliver.
        self::assertSame(
            [['type' => 'dispatch', 'to' => 'weather-panel', 'event' => 'refresh', 'payload' => ['city' => 'CDMX']]],
            $response->body['effects'] ?? [],
        );

        if (is_file($noncePath)) {
            unlink($noncePath);
        }
    }

    public function testOtherEffectsPassThroughAndAnUnrenderableTargetIsLeftForTheClient(): void
    {
        $noncePath = sys_get_temp_dir() . '/milpa-live-web-cross2-' . bin2hex(random_bytes(6)) . '.json';
        $codec = TestSecurityWiring::stateCodec($noncePath);
        $csrf = TestSecurityWiring::csrfGuard();

        $trigger = new class () implements ComponentDefinitionInterface {
            public static function contract(): ComponentContract
            {
                return new ComponentContract(name: 'trigger', contractVersion: '1', actions: ['go' => ['payload' => []]]);
            }

            public function mount(array $props, ComponentContext $context): StateSnapshot
            {
                return new StateSnapshot($context->componentId, 'trigger', '1', ['ready' => true]);
            }

            public function handle(InteractionRequest $request): InteractionResult
            {
                return new InteractionResult($request->state, effects: [
                    ['type' => 'persist'],                                                        // a non-render effect passes through
                    (new RenderEffect(target: 'x', component: 'not-registered'))->toArray(),      // no such component → left as declared
                ]);
            }
        };

        $components = new InMemoryComponentRegistry();
        $components->register('trigger', $trigger);

        $endpoint = new LiveEndpoint(
            components: $components,
            codec: $codec,
            authorizer: TestSecurityWiring::authorizer($components),
            csrf: $csrf,
            route: TestSecurityWiring::ROUTE,
            renderers: [],
        );

        $sessionId = 'sess-cross-2';
        $envelope = $codec->encodeState($trigger->mount([], new ComponentContext('trigger-2', route: TestSecurityWiring::ROUTE)));
        $response = $endpoint->handle(new LiveHttpRequest(
            method: 'POST',
            action: 'go',
            stateEnvelope: $envelope,
            payload: [],
            sessionId: $sessionId,
            csrfToken: $csrf->issueToken($sessionId, TestSecurityWiring::ROUTE),
        ));

        self::assertSame(200, $response->status);
        $effects = $response->body['effects'] ?? [];
        self::assertSame(['type' => 'persist'], $effects[0]);
        // The unresolvable render declaration is left untouched (its {component} shape survives).
        self::assertSame('render', $effects[1]['type']);
        self::assertSame('not-registered', $effects[1]['component']);

        if (is_file($noncePath)) {
            unlink($noncePath);
        }
    }
}
