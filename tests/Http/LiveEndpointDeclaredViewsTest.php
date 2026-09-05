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

namespace Milpa\Live\Tests\Http;

use Milpa\Live\Adapters\Alpine\AlpineRuntimeAdapter;
use Milpa\Live\Components\Form\TextareaComponent;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\Contracts\Transport\StateTransferCodecInterface;
use Milpa\Live\Effects\RenderEffect;
use Milpa\Live\Http\LiveEndpoint;
use Milpa\Live\Http\LiveHttpRequest;
use Milpa\Live\Rendering\ComponentRendererRegistry;
use Milpa\Live\Rendering\FormPrimitiveHtmlRenderer;
use Milpa\Live\Runtime\CompositeComponentRegistry;
use Milpa\Live\Runtime\InMemoryComponentRegistry;
use Milpa\Live\Security\HmacCsrfGuard;
use Milpa\Live\Tests\Fixtures\TestSecurityWiring;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\ComponentContract;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\InteractionResult;
use Milpa\Live\ValueObjects\StateSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * One endpoint per page (greenhouse decisions/0211): the endpoint serves a composite registry (host + guest
 * layers) through a renderer registry, a RenderEffect from a host component re-paints a guest component, a
 * target's current envelope is re-rendered from (never mounted over), and a CSRF token near expiry comes back
 * refreshed.
 */
final class LiveEndpointDeclaredViewsTest extends TestCase
{
    private string $noncePath;
    private StateTransferCodecInterface $codec;

    protected function setUp(): void
    {
        $this->noncePath = sys_get_temp_dir() . '/milpa-live-web-declared-' . bin2hex(random_bytes(6)) . '.json';
        $this->codec = TestSecurityWiring::stateCodec($this->noncePath);
    }

    protected function tearDown(): void
    {
        if (is_file($this->noncePath)) {
            unlink($this->noncePath);
        }
    }

    /** A host component whose `paint` action declares that the guest's textarea re-paints — from the payload's envelope when one is given. */
    private function trigger(): ComponentDefinitionInterface
    {
        return new class () implements ComponentDefinitionInterface {
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
                $state = $request->payload['targetState'] ?? null;

                return new InteractionResult(
                    $request->state,
                    effects: [(new RenderEffect(
                        target: 'field-b',
                        component: 'textarea',
                        props: ['name' => 'b', 'value' => 'painted from host'],
                        state: \is_string($state) ? $state : null,
                    ))->toArray()],
                );
            }
        };
    }

    private function composite(): CompositeComponentRegistry
    {
        $host = new InMemoryComponentRegistry();
        $host->register('trigger', $this->trigger());
        $guest = new InMemoryComponentRegistry();
        $guest->register('textarea', new TextareaComponent());

        return new CompositeComponentRegistry(['host' => $host, 'guest' => $guest]);
    }

    private function renderers(): ComponentRendererRegistry
    {
        $renderers = new ComponentRendererRegistry();
        $renderers->registerFor('textarea', new FormPrimitiveHtmlRenderer(new AlpineRuntimeAdapter(), $this->codec));

        return $renderers;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function paint(LiveEndpoint $endpoint, CompositeComponentRegistry $components, HmacCsrfGuard $csrf, array $payload = [], ?string $csrfToken = null): \Milpa\Live\Http\LiveHttpResponse
    {
        $sessionId = 'sess-declared-1';
        $envelope = $this->codec->encodeState($components->get('trigger')->mount([], new ComponentContext('trigger-1', route: TestSecurityWiring::ROUTE)));

        return $endpoint->handle(new LiveHttpRequest(
            method: 'POST',
            action: 'paint',
            stateEnvelope: $envelope,
            payload: $payload,
            sessionId: $sessionId,
            csrfToken: $csrfToken ?? $csrf->issueToken($sessionId, TestSecurityWiring::ROUTE),
        ));
    }

    public function testARenderEffectFromAHostComponentResolvesAGuestComponentAcrossLayers(): void
    {
        $components = $this->composite();
        $csrf = new HmacCsrfGuard('test-csrf-secret');
        $endpoint = new LiveEndpoint(
            components: $components,
            codec: $this->codec,
            authorizer: TestSecurityWiring::authorizer($components),
            csrf: $csrf,
            route: TestSecurityWiring::ROUTE,
            renderers: $this->renderers(),
        );

        $response = $this->paint($endpoint, $components, $csrf);

        self::assertSame(200, $response->status, json_encode($response->body));
        $effects = $response->body['effects'] ?? [];
        self::assertCount(1, $effects);
        self::assertSame('render', $effects[0]['type']);
        self::assertSame('field-b', $effects[0]['target']);
        self::assertStringContainsString('data-milpa-component-id="field-b"', $effects[0]['html'], 'the guest layer\'s textarea was rendered by the registry\'s per-name renderer');
        self::assertStringContainsString('painted from host', $effects[0]['html']);
        self::assertArrayNotHasKey('component', $effects[0]);
        self::assertArrayNotHasKey('csrfToken', $response->body, 'a fresh token is not refreshed');
    }

    public function testARenderEffectWithTheTargetsCurrentEnvelopeReRendersFromItInsteadOfMountingFresh(): void
    {
        $components = $this->composite();
        $csrf = new HmacCsrfGuard('test-csrf-secret');
        $endpoint = new LiveEndpoint(
            components: $components,
            codec: $this->codec,
            authorizer: TestSecurityWiring::authorizer($components),
            csrf: $csrf,
            route: TestSecurityWiring::ROUTE,
            renderers: $this->renderers(),
        );
        // The target as the page holds it: the human already typed into it.
        $current = $components->get('textarea')->mount(['name' => 'b', 'value' => 'typed by the human'], new ComponentContext('field-b'));

        $response = $this->paint($endpoint, $components, $csrf, ['targetState' => $this->codec->encodeState($current)]);

        self::assertSame(200, $response->status, json_encode($response->body));
        $html = $response->body['effects'][0]['html'] ?? '';
        self::assertStringContainsString('typed by the human', $html, 're-rendered from the current state');
        self::assertStringNotContainsString('painted from host', $html, 'the fresh-mount props did not win over the current state');
        self::assertArrayNotHasKey('state', $response->body['effects'][0], 'the envelope never travels back raw');
    }

    public function testATamperedOrForeignTargetEnvelopeIsIgnoredAndTheFreshMountStands(): void
    {
        $components = $this->composite();
        $csrf = new HmacCsrfGuard('test-csrf-secret');
        $endpoint = new LiveEndpoint(
            components: $components,
            codec: $this->codec,
            authorizer: TestSecurityWiring::authorizer($components),
            csrf: $csrf,
            route: TestSecurityWiring::ROUTE,
            renderers: $this->renderers(),
        );
        $current = $components->get('textarea')->mount(['name' => 'b', 'value' => 'typed by the human'], new ComponentContext('field-b'));
        $tampered = str_replace('component-id="field-b"', 'component-id="field-x"', $this->codec->encodeState($current));

        $response = $this->paint($endpoint, $components, $csrf, ['targetState' => $tampered]);
        self::assertSame(200, $response->status);
        self::assertStringContainsString('painted from host', $response->body['effects'][0]['html'], 'a tampered envelope is never rendered from');

        // Authentic, but signed for ANOTHER component id: not this target's state.
        $foreign = $components->get('textarea')->mount(['name' => 'z', 'value' => 'someone else'], new ComponentContext('field-z'));
        $response = $this->paint($endpoint, $components, $csrf, ['targetState' => $this->codec->encodeState($foreign)]);
        self::assertSame(200, $response->status);
        self::assertStringContainsString('painted from host', $response->body['effects'][0]['html']);
        self::assertStringNotContainsString('someone else', $response->body['effects'][0]['html']);
    }

    public function testAnUnrenderableTargetPassesThroughAsADeclarationWithoutItsEnvelope(): void
    {
        $components = $this->composite();
        $csrf = new HmacCsrfGuard('test-csrf-secret');
        $endpoint = new LiveEndpoint(
            components: $components,
            codec: $this->codec,
            authorizer: TestSecurityWiring::authorizer($components),
            csrf: $csrf,
            route: TestSecurityWiring::ROUTE,
            renderers: [], // nothing renders the textarea here
        );
        $current = $components->get('textarea')->mount(['name' => 'b', 'value' => 'typed by the human'], new ComponentContext('field-b'));

        $response = $this->paint($endpoint, $components, $csrf, ['targetState' => $this->codec->encodeState($current)]);

        self::assertSame(200, $response->status, json_encode($response->body));
        $effect = $response->body['effects'][0] ?? [];
        self::assertSame('render', $effect['type']);
        self::assertSame('field-b', $effect['target']);
        self::assertSame('textarea', $effect['component'], 'the declaration is left for the client to see');
        self::assertArrayNotHasKey('html', $effect);
        self::assertArrayNotHasKey('state', $effect, 'the envelope never travels back raw, renderable or not');
    }

    public function testTheLegacyRenderersArrayStillWorksNextToTheComposite(): void
    {
        $components = $this->composite();
        $csrf = new HmacCsrfGuard('test-csrf-secret');
        $endpoint = new LiveEndpoint(
            components: $components,
            codec: $this->codec,
            authorizer: TestSecurityWiring::authorizer($components),
            csrf: $csrf,
            route: TestSecurityWiring::ROUTE,
            renderers: ['textarea' => new FormPrimitiveHtmlRenderer(new AlpineRuntimeAdapter(), $this->codec)],
        );

        $response = $this->paint($endpoint, $components, $csrf);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('painted from host', $response->body['effects'][0]['html']);
    }

    public function testACsrfTokenWithLessThanATenthOfItsLifeLeftComesBackRefreshed(): void
    {
        $components = $this->composite();
        $now = 1_000_000;
        $csrf = new HmacCsrfGuard('test-csrf-secret', ttlSeconds: 100, clock: static function () use (&$now): int {
            return $now;
        });
        $endpoint = new LiveEndpoint(
            components: $components,
            codec: $this->codec,
            authorizer: TestSecurityWiring::authorizer($components),
            csrf: $csrf,
            route: TestSecurityWiring::ROUTE,
            renderers: $this->renderers(),
        );
        $token = $csrf->issueToken('sess-declared-1', TestSecurityWiring::ROUTE);

        $now += 50;
        $response = $this->paint($endpoint, $components, $csrf, csrfToken: $token);
        self::assertSame(200, $response->status);
        self::assertArrayNotHasKey('csrfToken', $response->body, 'half its life left: nothing to refresh');

        $now += 45; // 5 s left of 100
        $response = $this->paint($endpoint, $components, $csrf, csrfToken: $token);
        self::assertSame(200, $response->status);
        self::assertArrayHasKey('csrfToken', $response->body, 'under a tenth of its life: refreshed');
        $fresh = $response->body['csrfToken'];
        self::assertNotSame($token, $fresh);
        self::assertTrue($csrf->verifyToken($fresh, 'sess-declared-1', TestSecurityWiring::ROUTE), 'bound to the same session and route');
        self::assertSame(100, $csrf->remaining($fresh));
    }
}
