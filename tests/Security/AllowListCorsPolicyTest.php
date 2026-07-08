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

use Milpa\Live\Security\AllowListCorsPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Converted from tests/smoke.php lines ~947-953.
 */
final class AllowListCorsPolicyTest extends TestCase
{
    public function testCheckAllowsAnAllowlistedOrigin(): void
    {
        $cors = new AllowListCorsPolicy(['https://app.milpa.test'], allowCredentials: true);
        $decision = $cors->check('https://app.milpa.test', 'POST', ['content-type', 'authorization']);

        self::assertTrue($decision->allowed);
        self::assertSame('https://app.milpa.test', $decision->headers['Access-Control-Allow-Origin']);
        self::assertNotContains('*', $decision->headers, 'must reflect the allowlisted origin, never a wildcard');
    }

    public function testCheckDeniesANonAllowlistedOrigin(): void
    {
        $cors = new AllowListCorsPolicy(['https://app.milpa.test']);
        $decision = $cors->check('https://evil.test', 'POST');

        self::assertFalse($decision->allowed);
        self::assertSame('origin_not_allowed', $decision->reason);
    }

    public function testCheckDeniesADisallowedMethod(): void
    {
        $cors = new AllowListCorsPolicy(['https://app.milpa.test'], allowedMethods: ['GET']);
        $decision = $cors->check('https://app.milpa.test', 'DELETE');

        self::assertFalse($decision->allowed);
        self::assertSame('method_not_allowed', $decision->reason);
    }

    public function testCheckDeniesADisallowedRequestHeader(): void
    {
        $cors = new AllowListCorsPolicy(['https://app.milpa.test'], allowedHeaders: ['content-type']);
        $decision = $cors->check('https://app.milpa.test', 'POST', ['x-not-allowed']);

        self::assertFalse($decision->allowed);
        self::assertSame('header_not_allowed', $decision->reason);
    }

    public function testCheckAllowsARequestWithNoOrigin(): void
    {
        $cors = new AllowListCorsPolicy(['https://app.milpa.test']);
        $decision = $cors->check(null, 'GET');

        self::assertTrue($decision->allowed);
    }
}
