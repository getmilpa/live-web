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

namespace Milpa\Live\Tests\Security;

use Milpa\Live\Components\Autocomplete\AutocompleteComponent;
use Milpa\Live\DataSource\ArrayDataSource;
use Milpa\Live\DataSource\InMemoryDataSourceRegistry;
use Milpa\Live\Runtime\InMemoryComponentRegistry;
use Milpa\Live\Security\ContractInteractionAuthorizer;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\InteractionRequest;
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
}
