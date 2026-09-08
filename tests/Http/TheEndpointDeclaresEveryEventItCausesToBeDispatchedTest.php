<?php

/**
 * This file is part of Milpa Live Web — the HTML/Alpine surface of the Milpa live component core.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/live-web
 */

declare(strict_types=1);

namespace Milpa\Live\Tests\Http;

use Milpa\Events\InterceptionSlot;
use Milpa\Interfaces\Event\DeclaredEvents;
use Milpa\Interfaces\Event\EventDeclaration;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Live\Adapters\Alpine\AlpineRuntimeAdapter;
use Milpa\Live\Components\Autocomplete\AutocompleteComponent;
use Milpa\Live\DataSource\ArrayDataSource;
use Milpa\Live\DataSource\InMemoryDataSourceRegistry;
use Milpa\Live\Events\LiveEventEmitter;
use Milpa\Live\Http\LiveEndpoint;
use Milpa\Live\Http\LiveHttpRequest;
use Milpa\Live\Http\LiveHttpResponse;
use Milpa\Live\Rendering\AutocompleteHtmlRenderer;
use Milpa\Live\Runtime\InMemoryComponentRegistry;
use Milpa\Live\Tests\Fixtures\RecordingEventDispatcher;
use Milpa\Live\Tests\Fixtures\TestSecurityWiring;
use Milpa\Live\ValueObjects\ComponentContext;
use PHPUnit\Framework\TestCase;

/**
 * The falsifier for greenhouse decisions/0228 on this surface: this package
 * holds NO `dispatch()` site of its own — every event it causes to exist is
 * dispatched by `milpa/live`'s {@see LiveEventEmitter} — so what it must
 * prove is that the dispatcher it receives leaves this package's entry sites
 * (the endpoint's and the renderers' constructors) already knowing every
 * name a real request will dispatch, before the first one fires.
 *
 * The control is the same request on a dispatcher that does not implement
 * {@see DeclaredEvents}: it is asked nothing and answers identically.
 */
final class TheEndpointDeclaresEveryEventItCausesToBeDispatchedTest extends TestCase
{
    /**
     * Every name a live HTTP interaction on this surface can dispatch — the
     * catalogue `milpa/live` declares. Hardcoded so a declaration deleted
     * upstream, or an entry site that stops declaring, goes red here.
     *
     * @var list<string>
     */
    private const EXPECTED_NAMES = [
        'component.mounting',
        'component.mounted',
        'component.handling',
        'component.handled',
        'component.rendering',
        'component.rendered',
        'live.request',
        'live.responded',
    ];

    private string $noncePath;

    protected function setUp(): void
    {
        $this->noncePath = sys_get_temp_dir() . '/milpa-live-web-declared-events-nonce-' . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->noncePath)) {
            unlink($this->noncePath);
        }
    }

    /**
     * The endpoint's OWN site, isolated: no renderer is wired, so nothing
     * else in this package can declare on its behalf. (Wired together, a
     * renderer would mask a missing declaration here — which is exactly the
     * hole this isolation closes.)
     */
    public function testConstructingTheEndpointAloneWithNoRendererDeclaresTheWholeCatalogue(): void
    {
        $spy = $this->spy();

        new LiveEndpoint(
            components: new InMemoryComponentRegistry(),
            codec: TestSecurityWiring::stateCodec($this->noncePath),
            authorizer: TestSecurityWiring::authorizer(new InMemoryComponentRegistry()),
            csrf: TestSecurityWiring::csrfGuard(),
            route: TestSecurityWiring::ROUTE,
            dispatcher: $spy,
        );

        self::assertSame([], $spy->dispatchedNames, 'Constructing an endpoint dispatches nothing.');
        self::assertSame(self::EXPECTED_NAMES, $this->declaredNames($spy), 'The endpoint declares the whole catalogue at the site the dispatcher enters this package.');
    }

    public function testConstructingARendererAloneDeclaresTheWholeCatalogueToo(): void
    {
        $spy = $this->spy();

        new AutocompleteHtmlRenderer(new AlpineRuntimeAdapter(), TestSecurityWiring::stateCodec($this->noncePath), null, $spy);

        self::assertSame(self::EXPECTED_NAMES, $this->declaredNames($spy), 'A renderer is an entry site of its own: a surface that renders without the endpoint still declares.');
    }

    public function testARealSignedInteractionDispatchesNoNameThatWasNotDeclared(): void
    {
        $spy = $this->spy();

        $response = $this->handleOneRealInteraction($this->endpoint($spy), $spy);

        self::assertSame(200, $response->status);
        self::assertNotSame([], $spy->dispatchedNames, 'The drive must actually dispatch, or this proves nothing.');
        self::assertSame([], array_diff($spy->dispatchedNames, $this->declaredNames($spy)), 'Every name a real request dispatched must be declared — no undeclared dispatch.');
        self::assertContains('live.request', $spy->dispatchedNames);
        self::assertContains('live.responded', $spy->dispatchedNames);
        self::assertContains('component.rendering', $spy->dispatchedNames);
        self::assertSame(1, $spy->declareCalls, 'Five entry sites, one dispatcher: declared once.');
    }

    public function testEachDeclarationDescribesThePayloadTheRealRequestCarried(): void
    {
        $spy = $this->spy();

        $this->handleOneRealInteraction($this->endpoint($spy), $spy);

        $checked = 0;
        foreach ($spy->declarations as $declaration) {
            $payload = $spy->payloads[$declaration->name] ?? null;
            if ($payload === null) {
                continue; // Declared for this surface, not reached by this particular drive.
            }

            ++$checked;
            self::assertArrayHasKey($declaration->subjectKey, $payload, "{$declaration->name}: the payload carries the declared subject key.");
            self::assertNotNull($declaration->subjectType);
            self::assertInstanceOf($declaration->subjectType, $payload[$declaration->subjectKey], "{$declaration->name}: the subject is of the declared type.");
            self::assertSame(
                ($payload['slot'] ?? null) instanceof InterceptionSlot,
                $declaration->interceptable,
                "{$declaration->name}: interceptable iff the payload carries an InterceptionSlot.",
            );
        }

        self::assertGreaterThanOrEqual(5, $checked, 'A real interaction exercises most of the catalogue; fewer means the drive stopped early.');
    }

    /**
     * CONTROL: a dispatcher that does not implement DeclaredEvents is asked
     * nothing at any entry site, and the same signed interaction answers
     * exactly as it does for the declaring spy.
     */
    public function testControlAPlainDispatcherIsAskedNothingAndAnswersTheSameRequest(): void
    {
        $plain = new RecordingEventDispatcher();
        self::assertNotInstanceOf(DeclaredEvents::class, $plain);

        $response = $this->handleOneRealInteraction($this->endpoint($plain), $plain);

        self::assertSame(200, $response->status);
        self::assertContains('live.responded', array_column($plain->dispatched, 'name'));

        $spy = $this->spy();
        $declaring = $this->handleOneRealInteraction($this->endpoint($spy), $spy);
        self::assertSame($response->body['data'], $declaring->body['data'], 'Declaring must not change a single byte of the answer.');
    }

    /** No dispatcher at all: nothing is declared, nothing breaks. */
    public function testControlNoDispatcherAtAllStillAnswers(): void
    {
        $response = $this->handleOneRealInteraction($this->endpoint(null), null);

        self::assertSame(200, $response->status);
    }

    /**
     * @return list<string>
     */
    private function declaredNames(object $spy): array
    {
        /** @var object{declarations: list<EventDeclaration>} $spy */
        return array_map(static fn (EventDeclaration $d): string => $d->name, $spy->declarations);
    }

    private function endpoint(?MilpaEventDispatcherInterface $dispatcher): LiveEndpoint
    {
        $sources = new InMemoryDataSourceRegistry();
        $sources->register(new ArrayDataSource('customers.search', [
            ['value' => 'milpa', 'label' => 'Milpa Labs', 'search' => 'framework'],
        ]));
        $components = new InMemoryComponentRegistry();
        $components->register('autocomplete', new AutocompleteComponent($sources, $dispatcher));

        $codec = TestSecurityWiring::stateCodec($this->noncePath);

        return new LiveEndpoint(
            components: $components,
            codec: $codec,
            authorizer: TestSecurityWiring::authorizer($components),
            csrf: TestSecurityWiring::csrfGuard(),
            route: TestSecurityWiring::ROUTE,
            renderers: ['autocomplete' => new AutocompleteHtmlRenderer(new AlpineRuntimeAdapter(), $codec, null, $dispatcher)],
            renderProps: ['autocomplete' => ['endpoint' => TestSecurityWiring::ROUTE]],
            dispatcher: $dispatcher,
        );
    }

    /**
     * Drives the real production path: mount, sign the state envelope, issue
     * a CSRF token, and POST one interaction through {@see LiveEndpoint}.
     */
    private function handleOneRealInteraction(LiveEndpoint $endpoint, ?MilpaEventDispatcherInterface $dispatcher): LiveHttpResponse
    {
        $sources = new InMemoryDataSourceRegistry();
        $sources->register(new ArrayDataSource('customers.search', [
            ['value' => 'milpa', 'label' => 'Milpa Labs', 'search' => 'framework'],
        ]));
        $component = new AutocompleteComponent($sources, $dispatcher);
        $codec = TestSecurityWiring::stateCodec($this->noncePath);
        $csrf = TestSecurityWiring::csrfGuard();

        $state = $component->mount(
            ['name' => 'customer', 'source' => 'customers.search'],
            new ComponentContext('declared-events', route: '/autocomplete-demo'),
        );
        $sessionId = 'declared-events-session-' . bin2hex(random_bytes(4));

        return $endpoint->handle(new LiveHttpRequest(
            method: 'POST',
            action: 'search',
            stateEnvelope: $codec->encodeState($state),
            payload: ['query' => 'mil'],
            sessionId: $sessionId,
            csrfToken: $csrf->issueToken($sessionId, TestSecurityWiring::ROUTE),
        ));
    }

    /**
     * A spy that is BOTH a dispatcher and a {@see DeclaredEvents}: records
     * what was declared to it, every name dispatched (first occurrence
     * first) and the first payload seen per name.
     *
     * @return MilpaEventDispatcherInterface&DeclaredEvents&object{declarations: list<EventDeclaration>, declareCalls: int, dispatchedNames: list<string>, payloads: array<string, array<string, mixed>>}
     */
    private function spy(): MilpaEventDispatcherInterface
    {
        return new class () implements MilpaEventDispatcherInterface, DeclaredEvents {
            /** @var list<EventDeclaration> */
            public array $declarations = [];
            public int $declareCalls = 0;
            /** @var list<string> */
            public array $dispatchedNames = [];
            /** @var array<string, array<string, mixed>> */
            public array $payloads = [];

            public function declare(EventDeclaration ...$events): void
            {
                ++$this->declareCalls;
                foreach ($events as $event) {
                    foreach ($this->declarations as $known) {
                        if ($known->name === $event->name) {
                            continue 2;
                        }
                    }
                    $this->declarations[] = $event;
                }
            }

            public function declared(): array
            {
                return $this->declarations;
            }

            public function dispatched(): array
            {
                return $this->dispatchedNames;
            }

            public function dispatch(string $eventName, array $payload = [], bool $async = false): void
            {
                if (!in_array($eventName, $this->dispatchedNames, true)) {
                    $this->dispatchedNames[] = $eventName;
                    $this->payloads[$eventName] = $payload;
                }
            }

            public function subscribe(string $eventName, callable $handler, int $priority = 0): void
            {
            }

            public function getSubscribers(string $eventName): array
            {
                return [];
            }

            public function hasSubscribers(string $eventName): bool
            {
                return false;
            }
        };
    }
}
