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

namespace Milpa\Live\Http;

use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Live\Contracts\Component\ComponentRegistryInterface;
use Milpa\Live\Contracts\Rendering\ComponentRendererInterface;
use Milpa\Live\Contracts\Security\CsrfGuardInterface;
use Milpa\Live\Contracts\Security\CsrfTokenLifetimeInterface;
use Milpa\Live\Contracts\Security\InteractionAuthorizerInterface;
use Milpa\Live\Contracts\Security\ReplayedNonceException;
use Milpa\Live\Contracts\Transport\StateTransferCodecInterface;
use Milpa\Live\Effects\RenderEffect;
use Milpa\Live\Events\LiveEventEmitter;
use Milpa\Live\Rendering\ComponentRendererRegistry;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\InteractionResult;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\RenderTarget;
use Milpa\Live\ValueObjects\SecurityPrincipal;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * The HTTP live loop terminus: verifies a request, dispatches it to a
 * mounted component's `handle()`, re-renders, and returns a response the
 * client can apply. This is the controller the 2026-07-08 lab audit found
 * missing — Sync/Transport/Security were all individually proven but never
 * wired to a real entrypoint.
 *
 * Trust model: the client cannot hold the HMAC signing secret, so it never
 * builds a signed envelope itself. Instead it echoes back — byte for byte —
 * the last `<milpa-state>` envelope the server signed and handed it (first
 * embedded in the SSR'd page, then refreshed on every response). Verifying
 * that envelope (`StateTransferCodecInterface::decodeState()`) is what
 * proves the state hasn't been tampered with since this server last saw it;
 * `action`/`payload` are ordinary untrusted input, constrained instead by
 * the CSRF guard and the contract-based action allowlist.
 *
 * One endpoint per page (greenhouse decisions/0211): `$components` may be a
 * {@see \Milpa\Live\Runtime\CompositeComponentRegistry} over the host's and
 * every plugin's components (only `has`/`get` are needed), and `$renderers`
 * may be a {@see ComponentRendererRegistry} instead of a hand-wired
 * name => renderer array — it answers per name (`registerFor()`), exactly
 * what the array did — then one endpoint re-renders every plugin's
 * components and a {@see RenderEffect} resolves across layers.
 *
 * The session id the CSRF token is checked against is
 * {@see LiveHttpRequest::$sessionId}, and the contract is that the host's
 * adapter fills it from the request body's `sessionId` — the runtime echoes
 * the boot the host issued with {@see LiveBoot::issue()} — not from a cookie.
 * This endpoint cannot see where the adapter took it from; keeping that
 * contract is the adapter's job.
 */
final readonly class LiveEndpoint
{
    /**
     * Below this fraction of its TTL, a presented CSRF token is refreshed in the response.
     */
    private const float CSRF_REFRESH_FRACTION = 0.1;

    /**
     * @param array<string, ComponentRendererInterface>|ComponentRendererRegistry $renderers   component name => renderer used to re-render after handle(), or a registry answering {@see ComponentRendererRegistry::resolveFor()} per name (its `registerFor()`ed renderers only — like the array, no fallback)
     * @param array<string, array<string, mixed>>                                 $renderProps component name => base render props needed to re-render faithfully (e.g. the live endpoint URL)
     */
    public function __construct(
        private ComponentRegistryInterface $components,
        private StateTransferCodecInterface $codec,
        private InteractionAuthorizerInterface $authorizer,
        private CsrfGuardInterface $csrf,
        private string $route,
        private array|ComponentRendererRegistry $renderers = [],
        private array $renderProps = [],
        private ?MilpaEventDispatcherInterface $dispatcher = null,
    ) {
    }

    /**
     * Processes one interaction request end to end: method/CSRF/state-
     * signature checks, authorization, dispatch to
     * {@see \Milpa\Live\Contracts\Component\ComponentDefinitionInterface::handle()},
     * and (when a renderer is registered for the component) re-rendering
     * to HTML. Every failure path returns an {@see LiveHttpResponse}
     * error rather than throwing — this method MUST NOT throw for
     * ordinary bad input (wrong method, missing fields, invalid/expired
     * state signature, failed CSRF/authorization); it is the single point
     * that turns those into the appropriate HTTP status code.
     *
     * `$principal` MUST already be resolved by the caller (e.g. via a
     * {@see \Milpa\Live\Contracts\Security\TokenVerifierInterface}) —
     * this method only consumes it for authorization, it does not
     * perform authentication itself.
     *
     * When the guard implements {@see CsrfTokenLifetimeInterface} and the
     * presented token has less than a tenth of its TTL left, the OK response
     * carries a fresh `csrfToken` for the same session and route; the remote
     * runtime stores it in the boot for the next action.
     */
    public function handle(LiveHttpRequest $request, ?SecurityPrincipal $principal = null): LiveHttpResponse
    {
        if (strtoupper($request->method) !== 'POST') {
            return LiveHttpResponse::error(405, 'method_not_allowed', 'The live interaction endpoint only accepts POST.');
        }

        if ($request->action === '') {
            return LiveHttpResponse::error(400, 'bad_request', 'Missing "action".');
        }

        if ($request->stateEnvelope === '') {
            return LiveHttpResponse::error(400, 'bad_request', 'Missing "state" envelope.');
        }

        if (!$this->csrf->verifyToken($request->csrfToken, $request->sessionId, $this->route)) {
            return LiveHttpResponse::error(403, 'csrf', 'Missing or invalid CSRF token.');
        }

        try {
            $snapshot = $this->codec->decodeState($request->stateEnvelope);
        } catch (ReplayedNonceException) {
            // Distinct from invalid_signature: the envelope is genuinely
            // authentic (it verified cryptographically), it has simply
            // already been acted on once. Reported as a conflict with the
            // server's current state (this exact request already applied)
            // rather than as a permissions failure — the request wasn't
            // forbidden, it's just no longer novel.
            return LiveHttpResponse::error(409, 'replay_detected', 'This request has already been processed and cannot be repeated.');
        } catch (\Throwable) {
            return LiveHttpResponse::error(400, 'invalid_signature', 'The submitted state envelope failed signature verification.');
        }

        if (!$this->components->has($snapshot->componentName)) {
            return LiveHttpResponse::error(404, 'component_not_found', "Component not registered: {$snapshot->componentName}");
        }

        $interaction = new InteractionRequest(
            componentId: $snapshot->componentId,
            componentName: $snapshot->componentName,
            action: $request->action,
            state: $snapshot,
            payload: $request->payload,
        );

        $authorization = $this->authorizer->authorize($interaction, $principal);
        if (!$authorization->allowed) {
            return LiveHttpResponse::error(403, 'action_not_allowed', 'The requested action is not permitted.', $authorization->errors);
        }

        $component = $this->components->get($snapshot->componentName);

        // THE ANCHOR (milpa/live event-driven retrofit, F4). Every gate above — method,
        // CSRF, state-envelope signature/nonce verification, contract-based authorization —
        // has ALREADY run and ALREADY passed by this line. Only now does a `live.request`
        // listener (e.g. a cache plugin) get a turn to answer on the component's behalf.
        // Dispatching this any earlier would let a short-circuit stand in for authorization —
        // see docs/superpowers/specs/2026-07-08-event-driven-familia-design.md §milpa/live.
        $slot = LiveEventEmitter::liveRequest($this->dispatcher, $interaction, $principal);
        $intercepted = $slot->hasResult() || $slot->isStopped();

        if ($slot->hasResult()) {
            // Short-circuit: a listener supplied the InteractionResult directly — the
            // component's own handle() never runs. The response below still re-renders
            // fresh HTML from the intercepted state, so a cache-served interaction is
            // indistinguishable to the client from a normally-handled one.
            $shortCircuited = $slot->getResult();
            if (!$shortCircuited instanceof InteractionResult) {
                throw new \LogicException(
                    'live.request short-circuited with a non-InteractionResult value; '
                    . 'a listener MUST call shortCircuit() with an InteractionResult.'
                );
            }

            $result = $shortCircuited;
        } elseif ($slot->isStopped()) {
            // Pure veto: a listener stopped propagation without supplying a replacement
            // result. The component never runs; the caller gets an explicit rejection
            // rather than a silent no-op.
            $response = LiveHttpResponse::error(403, 'live_request_vetoed', 'The interaction was vetoed by a live.request listener.');
            LiveEventEmitter::liveResponded($this->dispatcher, $interaction, $response, intercepted: true);

            return $response;
        } else {
            $result = $component->handle($interaction);
        }

        $html = null;
        $renderer = $this->rendererFor($snapshot->componentName);
        if ($renderer !== null) {
            $rendered = $renderer->render($component, new RenderRequest(
                context: new ComponentContext(
                    componentId: $snapshot->componentId,
                    principal: $snapshot->meta['principal'] ?? null,
                    route: $snapshot->meta['route'] ?? null,
                ),
                props: $this->renderProps[$snapshot->componentName] ?? [],
                state: $result->state,
                target: RenderTarget::HTML,
            ));
            $html = $rendered->output;
        }

        $body = [
            'componentId' => $result->state->componentId,
            'componentName' => $result->state->componentName,
            'action' => $request->action,
            'data' => $result->state->data,
            'state' => $this->codec->encodeState($result->state),
            'html' => $html,
            'effects' => $this->resolveEffects($result->effects),
            'errors' => $result->errors,
        ];
        $refreshed = $this->refreshedCsrfToken($request);
        if ($refreshed !== null) {
            $body['csrfToken'] = $refreshed;
        }
        $response = LiveHttpResponse::ok($body);

        LiveEventEmitter::liveResponded($this->dispatcher, $interaction, $response, $intercepted);

        return $response;
    }

    /**
     * Resolve cross-component render effects: a `{type: render, target, component, props}` effect a handler
     * returned is turned into `{type: render, target, html}` by rendering the named target component with its
     * props (greenhouse decisions/0189). The client swaps the target's root — so a handler DECLARES that
     * another component re-paints, and the framework renders it. Other effects pass through untouched.
     *
     * An effect that also carries `state` — the target's current signed envelope — re-renders the target from
     * that state instead of mounting it fresh (greenhouse decisions/0211); an envelope that fails verification,
     * or that belongs to another component, is ignored and the fresh mount stands. Whether or not the target
     * renders here, `state` is consumed and stripped: the envelope never travels back raw.
     *
     * @param array<int, array<string, mixed>> $effects
     *
     * @return array<int, array<string, mixed>>
     */
    private function resolveEffects(array $effects): array
    {
        $resolved = [];
        foreach ($effects as $effect) {
            if (($effect['type'] ?? null) !== RenderEffect::TYPE) {
                $resolved[] = $effect;

                continue;
            }

            $envelope = \is_string($effect['state'] ?? null) ? $effect['state'] : null;
            unset($effect['state']);

            $target = $effect['target'] ?? null;
            $name = $effect['component'] ?? null;
            $renderer = \is_string($name) && $this->components->has($name) ? $this->rendererFor($name) : null;
            if (!\is_string($target) || !\is_string($name) || $renderer === null) {
                $resolved[] = $effect; // the target is not renderable here — leave the declaration for the client to see

                continue;
            }

            $component = $this->components->get($name);
            $context = new ComponentContext(componentId: $target);
            $props = \is_array($effect['props'] ?? null) ? $effect['props'] : [];
            $current = $envelope !== null ? $this->currentStateOf($envelope, $target, $name) : null;
            $rendered = $renderer->render($component, new RenderRequest(
                context: $context,
                props: $this->renderProps[$name] ?? [],
                state: $current ?? $component->mount($props, $context),
                target: RenderTarget::HTML,
            ));
            $resolved[] = ['type' => RenderEffect::TYPE, 'target' => $target, 'html' => $rendered->output];
        }

        return $resolved;
    }

    /**
     * The verified snapshot inside `$envelope` when it is the target's own — null for anything else
     * (tampered, replayed, expired, or signed for another component id/name), so a bad envelope is
     * never rendered from.
     */
    private function currentStateOf(string $envelope, string $target, string $componentName): ?StateSnapshot
    {
        try {
            $snapshot = $this->codec->decodeState($envelope);
        } catch (\Throwable) {
            return null;
        }

        return $snapshot->componentId === $target && $snapshot->componentName === $componentName ? $snapshot : null;
    }

    /** The HTML renderer for `$componentName` — from the name-keyed array or the registry — or null when none supports HTML. */
    private function rendererFor(string $componentName): ?ComponentRendererInterface
    {
        $renderer = $this->renderers instanceof ComponentRendererRegistry
            ? $this->renderers->resolveFor($componentName, RenderTarget::HTML)
            : ($this->renderers[$componentName] ?? null);

        return $renderer instanceof ComponentRendererInterface && $renderer->supportsTarget(RenderTarget::HTML) ? $renderer : null;
    }

    /** A fresh token for the request's session and route when the presented one is near expiry; null otherwise. */
    private function refreshedCsrfToken(LiveHttpRequest $request): ?string
    {
        if (!$this->csrf instanceof CsrfTokenLifetimeInterface) {
            return null;
        }
        $ttl = $this->csrf->ttl();
        if ($ttl <= 0 || $this->csrf->remaining($request->csrfToken) >= $ttl * self::CSRF_REFRESH_FRACTION) {
            return null;
        }

        return $this->csrf->issueToken($request->sessionId, $this->route);
    }
}
