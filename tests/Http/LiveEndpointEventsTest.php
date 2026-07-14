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
use Milpa\Live\Components\Autocomplete\AutocompleteComponent;
use Milpa\Live\DataSource\ArrayDataSource;
use Milpa\Live\DataSource\InMemoryDataSourceRegistry;
use Milpa\Live\Events\LiveRequestEvent;
use Milpa\Live\Events\LiveRespondedEvent;
use Milpa\Live\Http\LiveEndpoint;
use Milpa\Live\Http\LiveHttpRequest;
use Milpa\Live\Rendering\AutocompleteHtmlRenderer;
use Milpa\Live\Runtime\InMemoryComponentRegistry;
use Milpa\Live\Tests\Fixtures\RecordingEventDispatcher;
use Milpa\Live\Tests\Fixtures\TestSecurityWiring;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\InteractionResult;
use Milpa\Live\ValueObjects\StateSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * Converted from tests/smoke.php "F4: milpa/live event-driven emit points"
 * section, items 6-7 (lines ~1573-1645) — the two LiveEndpoint-specific F4
 * assertions the partition doc assigns to milpa/live-web (items 1-5 are
 * milpa/live core's own Events tests, ported by front B1).
 */
final class LiveEndpointEventsTest extends TestCase
{
    private string $noncePath;

    protected function setUp(): void
    {
        $this->noncePath = sys_get_temp_dir() . '/milpa-live-web-endpoint-events-nonce-' . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->noncePath)) {
            unlink($this->noncePath);
        }
    }

    /** Item 6: live.request short-circuit — post-security, pre-handle(). */
    public function testLiveRequestShortCircuitReplacesTheInteractionResultBeforeReRendering(): void
    {
        $dispatcher = new RecordingEventDispatcher();
        $dispatcher->subscribe('live.request', function (string $name, array $payload): void {
            $event = $payload['event'];
            self::assertInstanceOf(LiveRequestEvent::class, $event);
            $payload['slot']->shortCircuit(new InteractionResult(
                state: new StateSnapshot(
                    componentId: $event->interaction->state->componentId,
                    componentName: $event->interaction->state->componentName,
                    version: $event->interaction->state->version,
                    data: array_merge($event->interaction->state->data, [
                        'items' => [['value' => 'live-intercepted', 'label' => 'Live Intercepted']],
                        'open' => true,
                    ]),
                    meta: $event->interaction->state->meta,
                ),
            ));
        });

        $sources = new InMemoryDataSourceRegistry();
        $sources->register(new ArrayDataSource('customers.search', [
            ['value' => 'milpa', 'label' => 'Milpa Labs', 'search' => 'framework'],
        ]));
        $components = new InMemoryComponentRegistry();
        $components->register('autocomplete', new AutocompleteComponent($sources));

        $codec = TestSecurityWiring::stateCodec($this->noncePath);
        $csrf = TestSecurityWiring::csrfGuard();
        $renderer = new AutocompleteHtmlRenderer(new AlpineRuntimeAdapter(), $codec);

        $endpoint = new LiveEndpoint(
            components: $components,
            codec: $codec,
            authorizer: TestSecurityWiring::authorizer($components),
            csrf: $csrf,
            route: TestSecurityWiring::ROUTE,
            renderers: ['autocomplete' => $renderer],
            renderProps: ['autocomplete' => ['endpoint' => TestSecurityWiring::ROUTE]],
            dispatcher: $dispatcher,
        );

        $context = new ComponentContext('f4-live', route: '/autocomplete-demo');
        $initialState = $components->get('autocomplete')->mount([
            'name' => 'customer',
            'source' => 'customers.search',
        ], $context);
        $sessionId = 'f4-live-session';
        $csrfToken = $csrf->issueToken($sessionId, TestSecurityWiring::ROUTE);
        $envelope = $codec->encodeState($initialState);

        $response = $endpoint->handle(new LiveHttpRequest(
            method: 'POST',
            action: 'search',
            stateEnvelope: $envelope,
            payload: ['query' => 'mil'],
            sessionId: $sessionId,
            csrfToken: $csrfToken,
        ));

        self::assertSame(200, $response->status, 'a live.request short-circuit must still produce a normal 200 response');
        self::assertSame('live-intercepted', $response->body['data']['items'][0]['value']);
        self::assertStringContainsString('milpaAutocomplete(', (string) $response->body['html'], 'LiveEndpoint must still re-render fresh HTML from the intercepted state');

        $requestEvents = $dispatcher->named('live.request');
        self::assertCount(1, $requestEvents);

        $respondedEvents = $dispatcher->named('live.responded');
        self::assertCount(1, $respondedEvents);
        self::assertInstanceOf(LiveRespondedEvent::class, $respondedEvents[0]['payload']['event']);
        self::assertTrue($respondedEvents[0]['payload']['event']->intercepted, 'live.responded must be marked intercepted for a short-circuited live.request');
    }

    /**
     * Item 7: with no dispatcher wired at all, LiveEndpoint::handle() must
     * behave exactly as when a dispatcher is present but nothing subscribes
     * — the no-dispatcher-unchanged proof for LiveEndpoint specifically
     * (LiveEndpointTest's whole suite, which never passes a dispatcher, is
     * the broader version of this same proof).
     */
    public function testLiveRequestFiresEvenWithoutASubscriberAndDoesNotAlterTheResponse(): void
    {
        $dispatcher = new RecordingEventDispatcher();

        $sources = new InMemoryDataSourceRegistry();
        $sources->register(new ArrayDataSource('customers.search', [
            ['value' => 'milpa', 'label' => 'Milpa Labs', 'search' => 'framework'],
        ]));
        $components = new InMemoryComponentRegistry();
        $components->register('autocomplete', new AutocompleteComponent($sources));

        $codec = TestSecurityWiring::stateCodec($this->noncePath);
        $csrf = TestSecurityWiring::csrfGuard();
        $renderer = new AutocompleteHtmlRenderer(new AlpineRuntimeAdapter(), $codec);

        $endpoint = new LiveEndpoint(
            components: $components,
            codec: $codec,
            authorizer: TestSecurityWiring::authorizer($components),
            csrf: $csrf,
            route: TestSecurityWiring::ROUTE,
            renderers: ['autocomplete' => $renderer],
            renderProps: ['autocomplete' => ['endpoint' => TestSecurityWiring::ROUTE]],
            dispatcher: $dispatcher,
        );

        $context = new ComponentContext('f4-live-plain', route: '/autocomplete-demo');
        $initialState = $components->get('autocomplete')->mount([
            'name' => 'customer',
            'source' => 'customers.search',
        ], $context);
        $sessionId = 'f4-live-plain-session';
        $csrfToken = $csrf->issueToken($sessionId, TestSecurityWiring::ROUTE);
        $envelope = $codec->encodeState($initialState);

        $response = $endpoint->handle(new LiveHttpRequest(
            method: 'POST',
            action: 'search',
            stateEnvelope: $envelope,
            payload: ['query' => 'mil'],
            sessionId: $sessionId,
            csrfToken: $csrfToken,
        ));

        self::assertSame(200, $response->status);
        self::assertSame('milpa', $response->body['data']['items'][0]['value'], 'the real search() must run unmodified when nothing intercepts live.request');
        self::assertCount(1, $dispatcher->named('live.request'), 'live.request must still fire even with no subscriber acting on it');
    }
}
