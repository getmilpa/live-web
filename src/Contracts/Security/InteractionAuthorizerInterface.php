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

namespace Milpa\Live\Contracts\Security;

use Milpa\Live\ValueObjects\AuthorizationResult;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\SecurityPrincipal;

/**
 * Decides whether a specific {@see InteractionRequest} is permitted to
 * execute, independent of transport-level concerns ({@see
 * CsrfGuardInterface}, {@see CorsPolicyInterface}). This is the seam
 * where "is this action even declared by the component contract" and
 * "does this principal have the scope for it" are enforced, so a
 * component's {@see \Milpa\Live\Contracts\Component\ComponentDefinitionInterface::handle()}
 * never has to re-implement authorization itself.
 */
interface InteractionAuthorizerInterface
{
    /**
     * Authorizes the request against the component's declared contract
     * and, when `$principal` is given, the principal's scopes. A `null`
     * `$principal` represents an anonymous/unauthenticated caller — MUST
     * still be denied for actions the component or its state restrict to
     * a specific owning principal.
     */
    public function authorize(InteractionRequest $request, ?SecurityPrincipal $principal = null): AuthorizationResult;
}
