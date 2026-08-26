<?php

declare(strict_types=1);

namespace Milpa\Live\Tests\Http;

use Milpa\Live\Http\LiveBoot;
use Milpa\Live\Security\HmacCsrfGuard;
use PHPUnit\Framework\TestCase;

/**
 * The page embeds what the remote runtime needs — endpoint, session, CSRF token — in ONE place the
 * server issued, and the token it hands out verifies for exactly that session and route
 * (greenhouse decisions/0083).
 */
final class LiveBootTest extends TestCase
{
    public function testItIssuesASessionAndATokenTheGuardVerifiesForThatSessionAndRoute(): void
    {
        $csrf = new HmacCsrfGuard('test-csrf-secret');
        $boot = LiveBoot::issue($csrf, '/live');

        self::assertStringStartsWith('live-', $boot->sessionId);
        self::assertTrue($csrf->verifyToken($boot->csrfToken, $boot->sessionId, '/live'));
        self::assertFalse($csrf->verifyToken($boot->csrfToken, 'another-session', '/live'), 'bound to the session');
        self::assertFalse($csrf->verifyToken($boot->csrfToken, $boot->sessionId, '/elsewhere'), 'bound to the route');
    }

    public function testTheScriptTagCarriesThePayloadAndCannotBreakOutOfItself(): void
    {
        $boot = new LiveBoot('/live', 's-1', 'tok</script><script>alert(1)', 'Bearer abc');
        $tag = $boot->scriptTag();

        self::assertStringStartsWith('<script id="milpa-live-boot" type="application/json">', $tag);
        self::assertStringNotContainsString('</script><script>', $tag, '< is hex-escaped inside the JSON');
        $json = json_decode(substr($tag, strpos($tag, '>') + 1, -\strlen('</script>')), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(['endpoint' => '/live', 'sessionId' => 's-1', 'csrfToken' => 'tok</script><script>alert(1)', 'authorization' => 'Bearer abc'], $json);
    }

    public function testTheHtmlLoadsTheRuntimesInOrderAfterTheBoot(): void
    {
        $html = (new LiveBoot('/live', 's-1', 'tok'))->html();

        $boot = strpos($html, 'milpa-live-boot');
        $local = strpos($html, '/milpa-live.js');
        $remote = strpos($html, '/milpa-live-remote.js');
        $alpine = strpos($html, '/vendor/alpine.min.js');
        self::assertTrue($boot < $local && $local < $remote && $remote < $alpine, 'boot, local, remote, Alpine — in that order');
        self::assertSame(3, substr_count($html, ' defer>'));
        self::assertStringNotContainsString('authorization', $html, 'no authorization unless the page authorised one');
    }

    public function testABootWithoutItsPartsIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LiveBoot('/live', '', 'tok');
    }
}
