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

namespace Milpa\Live\Tests\Security;

use Milpa\Live\Components\Autocomplete\AutocompleteComponent;
use Milpa\Live\DataSource\ArrayDataSource;
use Milpa\Live\DataSource\InMemoryDataSourceRegistry;
use Milpa\Live\Runtime\InMemoryComponentRegistry;
use Milpa\Live\Security\ContractInteractionAuthorizer;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\ValueObjects\ComponentContract;
use Milpa\Live\ValueObjects\InteractionResult;
use Milpa\Live\ValueObjects\StateSnapshot;
use Milpa\Live\ValueObjects\SecurityPrincipal;
use PHPUnit\Framework\TestCase;

/**
 * Converted from tests/smoke.php lines ~915-939.
 */
final class ContractInteractionAuthorizerTest extends TestCase
{
    private InMemoryComponentRegistry $components;
    private AutocompleteComponent $autocomplete;

    protected function setUp(): void
    {
        $sources = new InMemoryDataSourceRegistry();
        $sources->register(new ArrayDataSource('customers.search', []));
        $this->autocomplete = new AutocompleteComponent($sources);

        $this->components = new InMemoryComponentRegistry();
        $this->components->register('autocomplete', $this->autocomplete);
    }

    private function state(): \Milpa\Live\ValueObjects\StateSnapshot
    {
        return $this->autocomplete->mount(
            ['name' => 'customer', 'source' => 'customers.search'],
            new ComponentContext('customer-picker', principal: 'user:1'),
        );
    }

    public function testAuthorizeAllowsADeclaredActionWithSufficientScope(): void
    {
        $authorizer = new ContractInteractionAuthorizer($this->components);
        $principal = new SecurityPrincipal('user:1', ['milpa:component:autocomplete:search']);

        $result = $authorizer->authorize(new InteractionRequest(
            componentId: 'customer-picker',
            componentName: 'autocomplete',
            action: 'search',
            state: $this->state(),
        ), $principal);

        self::assertTrue($result->allowed);
    }

    /** PINNED: an action not declared by the component contract must be denied. */
    public function testAuthorizeDeniesAnUndeclaredAction(): void
    {
        $authorizer = new ContractInteractionAuthorizer($this->components);

        $result = $authorizer->authorize(new InteractionRequest(
            componentId: 'customer-picker',
            componentName: 'autocomplete',
            action: 'drop-database',
            state: $this->state(),
        ), new SecurityPrincipal('user:1', ['milpa:*']));

        self::assertFalse($result->allowed);
        self::assertArrayHasKey('action', $result->errors);
    }

    public function testAuthorizeDeniesAPrincipalMismatchAgainstStateOwnership(): void
    {
        $authorizer = new ContractInteractionAuthorizer($this->components);

        $result = $authorizer->authorize(new InteractionRequest(
            componentId: 'customer-picker',
            componentName: 'autocomplete',
            action: 'search',
            state: $this->state(),
        ), new SecurityPrincipal('user:2', ['milpa:*']));

        self::assertFalse($result->allowed);
        self::assertArrayHasKey('principal', $result->errors);
    }

    public function testAuthorizeDeniesAnUnregisteredComponent(): void
    {
        $authorizer = new ContractInteractionAuthorizer(new InMemoryComponentRegistry());

        $result = $authorizer->authorize(new InteractionRequest(
            componentId: 'x',
            componentName: 'does-not-exist',
            action: 'search',
            state: $this->state(),
        ));

        self::assertFalse($result->allowed);
        self::assertArrayHasKey('component', $result->errors);
    }

    public function testAuthorizeDeniesAPrincipalWithoutTheRequiredScope(): void
    {
        $authorizer = new ContractInteractionAuthorizer($this->components);
        // No principal-state ownership claim recorded on this state, so the
        // scope check is the only remaining gate.
        $context = new ComponentContext('customer-picker-2');
        $state = $this->autocomplete->mount(['name' => 'customer', 'source' => 'customers.search'], $context);

        $result = $authorizer->authorize(new InteractionRequest(
            componentId: 'customer-picker-2',
            componentName: 'autocomplete',
            action: 'search',
            state: $state,
        ), new SecurityPrincipal('user:3', ['milpa:component:autocomplete:clear']));

        self::assertFalse($result->allowed);
        self::assertArrayHasKey('scope', $result->errors);
    }

    public function testAnActionMarkedScopeByIsAuthorizedPerPayloadFieldNotPerActionName(): void
    {
        // greenhouse decisions/0096: an action may declare `scopeBy => '<payload field>'`, so the required
        // scope is derived from the payload (e.g. a StateMachine's `fire` scoped per EVENT) — one generic
        // action carries per-event authorization without a dynamic contract.
        $component = new class () implements ComponentDefinitionInterface {
            public static function contract(): ComponentContract
            {
                return new ComponentContract(name: 'demo', contractVersion: '1', actions: ['fire' => ['scopeBy' => 'event']]);
            }

            public function mount(array $props, ComponentContext $context): StateSnapshot
            {
                return new StateSnapshot('demo-1', 'demo', '1', ['ready' => true], ['principal' => $context->principal]);
            }

            public function handle(InteractionRequest $request): InteractionResult
            {
                return new InteractionResult($request->state);
            }
        };
        $components = new InMemoryComponentRegistry();
        $components->register('demo', $component);
        $authorizer = new ContractInteractionAuthorizer($components);
        $state = $component->mount([], new ComponentContext('demo-1')); // ownerless: isolate the SCOPE check

        $fire = static fn (string $event): InteractionRequest => new InteractionRequest(
            componentId: 'demo-1',
            componentName: 'demo',
            action: 'fire',
            state: $state,
            payload: ['event' => $event],
        );

        $canUnlock = new SecurityPrincipal('user:1', ['milpa:component:demo:unlock']);
        self::assertTrue($authorizer->authorize($fire('unlock'), $canUnlock)->allowed, 'the unlock scope allows fire{unlock}');
        $denied = $authorizer->authorize($fire('lock'), $canUnlock);
        self::assertFalse($denied->allowed, 'the unlock scope does NOT allow fire{lock}');
        self::assertSame('Missing scope: milpa:component:demo:lock', $denied->errors['scope'] ?? null, 'the missing scope is the EVENT, not the action name');

        $canLock = new SecurityPrincipal('user:1', ['milpa:component:demo:lock']);
        self::assertTrue($authorizer->authorize($fire('lock'), $canLock)->allowed, 'the lock scope allows fire{lock}');
        self::assertFalse($authorizer->authorize($fire('unlock'), $canLock)->allowed);
    }
}
