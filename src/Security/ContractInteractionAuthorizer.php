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

namespace Milpa\Live\Security;

use Milpa\Live\Contracts\Component\ComponentRegistryInterface;
use Milpa\Live\Contracts\Security\InteractionAuthorizerInterface;
use Milpa\Live\ValueObjects\AuthorizationResult;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\SecurityPrincipal;

/**
 * The `#[Action]`-style {@see InteractionAuthorizerInterface}: an
 * interaction is allowed only if its component is registered, the envelope's
 * component id/name agree with the request, the requested `action` is
 * declared in that component's own contract, the state's owning principal
 * (if any) matches the caller, and the caller holds the derived
 * `milpa:component:{name}:{action}` scope (or its `:*` wildcard). This is
 * the contract-based allowlist {@see \Milpa\Live\Http\LiveEndpoint} defers
 * to — nothing here inspects the CSRF token or state signature, those are
 * separate gates that already ran before authorization.
 */
final readonly class ContractInteractionAuthorizer implements InteractionAuthorizerInterface
{
    public function __construct(
        private ComponentRegistryInterface $components,
    ) {
    }

    /**
     * Authorizes one interaction request. Every denial path returns a
     * keyed error explaining which check failed (`component`, `componentId`,
     * `componentName`, `action`, `principal`, or `scope`) rather than a bare
     * boolean — never throws for an ordinary unauthorized request.
     */
    public function authorize(InteractionRequest $request, ?SecurityPrincipal $principal = null): AuthorizationResult
    {
        if (!$this->components->has($request->componentName)) {
            return AuthorizationResult::deny(['component' => 'Component is not registered.']);
        }

        if ($request->state->componentId !== $request->componentId) {
            return AuthorizationResult::deny(['componentId' => 'Interaction component id does not match state.']);
        }

        if ($request->state->componentName !== $request->componentName) {
            return AuthorizationResult::deny(['componentName' => 'Interaction component name does not match state.']);
        }

        $component = $this->components->get($request->componentName);
        $contract = $component::contract();
        if (!array_key_exists($request->action, $contract->actions)) {
            return AuthorizationResult::deny(['action' => 'Action is not declared by the component contract.']);
        }

        $statePrincipal = $request->state->meta['principal'] ?? null;
        if (is_string($statePrincipal) && $statePrincipal !== '') {
            if ($principal === null || $principal->id !== $statePrincipal) {
                return AuthorizationResult::deny(['principal' => 'Principal does not own this component state.']);
            }
        }

        // A component may declare that an action is scoped by a PAYLOAD FIELD rather than by the action
        // name (`actions[<action>]['scopeBy'] => '<field>'`): then the derived scope suffix is the value of
        // that field, so one generic action (e.g. a StateMachine's `fire`) carries per-EVENT authorization
        // without a dynamic contract (greenhouse decisions/0096). The contract, not the caller, chooses this.
        $actionSpec = $contract->actions[$request->action];
        $scopeKey = $request->action;
        if (is_array($actionSpec) && is_string($actionSpec['scopeBy'] ?? null) && $actionSpec['scopeBy'] !== '') {
            $field = $request->payload[$actionSpec['scopeBy']] ?? null;
            if (is_string($field) && $field !== '') {
                $scopeKey = $field;
            }
        }

        $scope = "milpa:component:{$request->componentName}:{$scopeKey}";
        if ($principal !== null && !$principal->can($scope) && !$principal->can("milpa:component:{$request->componentName}:*")) {
            return AuthorizationResult::deny(['scope' => "Missing scope: {$scope}"]);
        }

        return AuthorizationResult::allow([
            'component' => $request->componentName,
            'action' => $request->action,
            'scope' => $scope,
        ]);
    }
}
