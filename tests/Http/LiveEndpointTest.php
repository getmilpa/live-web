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

namespace Milpa\Live\Tests\Http;

use Milpa\Live\Adapters\Alpine\AlpineRuntimeAdapter;
use Milpa\Live\Components\Autocomplete\AutocompleteComponent;
use Milpa\Live\Contracts\Transport\StateTransferCodecInterface;
use Milpa\Live\DataSource\ArrayDataSource;
use Milpa\Live\DataSource\InMemoryDataSourceRegistry;
use Milpa\Live\Http\LiveEndpoint;
use Milpa\Live\Http\LiveHttpRequest;
use Milpa\Live\Rendering\AutocompleteHtmlRenderer;
use Milpa\Live\Runtime\InMemoryComponentRegistry;
use Milpa\Live\Tests\Fixtures\TestSecurityWiring;
use Milpa\Live\ValueObjects\ComponentContext;
use PHPUnit\Framework\TestCase;

/**
 * Converted from tests/smoke.php's "A3: the real HTTP live loop" section
 * (lines ~245-419) — the full {@see LiveEndpoint} loop, exercised through
 * the exact same real security classes (`HmacStateSigner`,
 * `SignedXhtmlStateTransferCodec`, `HmacCsrfGuard`, `ContractInteractionAuthorizer`)
 * this package ships, via {@see TestSecurityWiring}.
 *
 * PINNED SECURITY ASSERTIONS (required by this front's task brief):
 * - tamper -> reject (400 invalid_signature)
 * - replay -> reject (409 replay_detected), and stays rejected on a third submission
 * - CSRF failure -> reject (403 csrf)
 * - undeclared action -> reject (403 action_not_allowed)
 * - non-POST method -> reject (405 method_not_allowed)
 */
final class LiveEndpointTest extends TestCase
{
    private string $noncePath;
    private InMemoryComponentRegistry $components;
    private StateTransferCodecInterface $codec;
    private \Milpa\Live\Contracts\Security\CsrfGuardInterface $csrf;
    private LiveEndpoint $endpoint;
    private string $sessionId = 'live-endpoint-test-session';
    private string $csrfToken;
    private \Milpa\Live\ValueObjects\StateSnapshot $initialState;

    protected function setUp(): void
    {
        $this->noncePath = sys_get_temp_dir() . '/milpa-live-web-endpoint-nonce-' . bin2hex(random_bytes(6)) . '.json';

        $sources = new InMemoryDataSourceRegistry();
        $sources->register(new ArrayDataSource('customers.search', [
            ['value' => 'acme', 'label' => 'Acme Studio', 'search' => 'agency design'],
            ['value' => 'milpa', 'label' => 'Milpa Labs', 'search' => 'framework components'],
        ]));

        $this->components = new InMemoryComponentRegistry();
        $this->components->register('autocomplete', new AutocompleteComponent($sources));

        $this->codec = TestSecurityWiring::stateCodec($this->noncePath);
        $this->csrf = TestSecurityWiring::csrfGuard();

        $renderer = new AutocompleteHtmlRenderer(new AlpineRuntimeAdapter(), $this->codec);
        $this->endpoint = new LiveEndpoint(
            components: $this->components,
            codec: $this->codec,
            authorizer: TestSecurityWiring::authorizer($this->components),
            csrf: $this->csrf,
            route: TestSecurityWiring::ROUTE,
            renderers: ['autocomplete' => $renderer],
            renderProps: ['autocomplete' => ['endpoint' => TestSecurityWiring::ROUTE]],
        );

        $context = new ComponentContext('customer-picker', route: '/autocomplete-demo');
        $this->initialState = $this->components->get('autocomplete')->mount([
            'name' => 'customer',
            'source' => 'customers.search',
        ], $context);
        $this->csrfToken = $this->csrf->issueToken($this->sessionId, TestSecurityWiring::ROUTE);
    }

    protected function tearDown(): void
    {
        if (is_file($this->noncePath)) {
            unlink($this->noncePath);
        }
    }

    private function request(string $method, string $action, string $stateEnvelope, array $payload = [], ?string $csrfToken = null): LiveHttpRequest
    {
        return new LiveHttpRequest(
            method: $method,
            action: $action,
            stateEnvelope: $stateEnvelope,
            payload: $payload,
            sessionId: $this->sessionId,
            csrfToken: $csrfToken ?? $this->csrfToken,
        );
    }

    public function testHappyPathSearchReturnsAFreshlySignedStateAndRenderedHtml(): void
    {
        $envelope = $this->codec->encodeState($this->initialState);

        $response = $this->endpoint->handle($this->request('POST', 'search', $envelope, ['query' => 'mil']));

        self::assertSame(200, $response->status);
        self::assertTrue($response->body['ok']);
        self::assertCount(1, $response->body['data']['items']);
        self::assertSame('milpa', $response->body['data']['items'][0]['value']);
        self::assertStringContainsString('security="signed"', (string) $response->body['state']);
        self::assertStringContainsString('milpaAutocomplete(', (string) $response->body['html']);
    }

    /** PINNED: replay -> 409, and stays rejected on repeated resubmission. */
    public function testReplayedEnvelopeIsRejectedWith409AndStaysRejected(): void
    {
        $envelope = $this->codec->encodeState($this->initialState);
        $request = $this->request('POST', 'search', $envelope, ['query' => 'mil']);

        $first = $this->endpoint->handle($request);
        self::assertSame(200, $first->status);

        $replay = $this->endpoint->handle($request);
        self::assertSame(409, $replay->status);
        self::assertSame('replay_detected', $replay->body['error']);

        $replayAgain = $this->endpoint->handle($request);
        self::assertSame(409, $replayAgain->status, 'a third submission of the same envelope must still be rejected');
        self::assertSame('replay_detected', $replayAgain->body['error']);
    }

    /** PINNED: tamper -> 400 invalid_signature. */
    public function testTamperedStateEnvelopeIsRejectedWith400(): void
    {
        $envelope = $this->codec->encodeState($this->initialState);
        $tampered = str_replace('component-id="customer-picker"', 'component-id="evil-picker"', $envelope);

        $response = $this->endpoint->handle($this->request('POST', 'search', $tampered, ['query' => 'mil']));

        self::assertSame(400, $response->status);
        self::assertSame('invalid_signature', $response->body['error']);
    }

    /** PINNED: undeclared action -> 403 action_not_allowed. */
    public function testUndeclaredActionIsRejectedWith403(): void
    {
        $envelope = $this->codec->encodeState($this->initialState);

        $response = $this->endpoint->handle($this->request('POST', 'drop-database', $envelope));

        self::assertSame(403, $response->status);
        self::assertSame('action_not_allowed', $response->body['error']);
    }

    /** PINNED: missing/invalid CSRF -> 403 csrf. */
    public function testMissingCsrfTokenIsRejectedWith403(): void
    {
        $envelope = $this->codec->encodeState($this->initialState);

        $response = $this->endpoint->handle($this->request('POST', 'search', $envelope, ['query' => 'mil'], csrfToken: 'not-a-real-token'));

        self::assertSame(403, $response->status);
        self::assertSame('csrf', $response->body['error']);
    }

    /** PINNED: non-POST -> 405. */
    public function testNonPostMethodIsRejectedWith405(): void
    {
        $envelope = $this->codec->encodeState($this->initialState);

        $response = $this->endpoint->handle($this->request('GET', 'search', $envelope));

        self::assertSame(405, $response->status);
    }

    public function testMissingActionIsRejectedWith400(): void
    {
        $envelope = $this->codec->encodeState($this->initialState);

        $response = $this->endpoint->handle($this->request('POST', '', $envelope));

        self::assertSame(400, $response->status);
        self::assertSame('bad_request', $response->body['error']);
    }

    public function testMissingStateEnvelopeIsRejectedWith400(): void
    {
        $response = $this->endpoint->handle($this->request('POST', 'search', ''));

        self::assertSame(400, $response->status);
        self::assertSame('bad_request', $response->body['error']);
    }
}
