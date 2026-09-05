<?php

declare(strict_types=1);

namespace Milpa\Live\Tests\Http;

use Milpa\Live\Http\LiveBoot;
use Milpa\Live\Security\HmacCsrfGuard;
use Milpa\Live\ValueObjects\ClientAssets;
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

    /** greenhouse decisions/0211: LiveBoot is the ONE emitter — styles, boot, local, remote, plugin modules, Alpine; each once. */
    public function testTheHtmlEmitsDeclaredAssetsOnceInTheDocumentedOrder(): void
    {
        $declared = new ClientAssets(
            scripts: ['/plugins/a/a.js', '/plugins/b/b.js', '/plugins/a/a.js', '/milpa-live.js'],
            styles: ['/plugins/a/a.css', '/plugins/a/a.css', '/plugins/b/b.css'],
        );

        $html = (new LiveBoot('/live', 's-1', 'tok'))->html(null, $declared);

        $positions = [
            'a.css' => strpos($html, '<link rel="stylesheet" href="/plugins/a/a.css">'),
            'b.css' => strpos($html, '<link rel="stylesheet" href="/plugins/b/b.css">'),
            'boot' => strpos($html, 'milpa-live-boot'),
            'local' => strpos($html, '/milpa-live.js'),
            'remote' => strpos($html, '/milpa-live-remote.js'),
            'a.js' => strpos($html, '/plugins/a/a.js'),
            'b.js' => strpos($html, '/plugins/b/b.js'),
            'alpine' => strpos($html, '/vendor/alpine.min.js'),
        ];
        self::assertNotContains(false, $positions, 'every part is emitted');
        $ordered = $positions;
        asort($ordered);
        self::assertSame(array_keys($positions), array_keys($ordered), 'styles, boot, local, remote, plugin scripts in declared order, Alpine last');

        self::assertSame(1, substr_count($html, '/plugins/a/a.js'), 'a script declared twice is emitted once');
        self::assertSame(1, substr_count($html, '/plugins/a/a.css'), 'a style declared twice is emitted once');
        self::assertSame(1, substr_count($html, 'src="/milpa-live.js"'), 'a plugin declaring the runtime itself does not make it load twice');
        self::assertSame(5, substr_count($html, ' defer></script>'), 'local, remote, a.js, b.js, Alpine — all deferred');
        self::assertSame(2, substr_count($html, '<link rel="stylesheet"'));
    }

    public function testTheHtmlWithoutDeclaredAssetsIsTheRuntimeAlone(): void
    {
        $boot = new LiveBoot('/live', 's-1', 'tok');

        self::assertSame($boot->html(), $boot->html(null, ClientAssets::empty()));
        self::assertSame($boot->html(), $boot->html(null, null));
        self::assertStringNotContainsString('<link', $boot->html());
    }

    public function testABootWithoutItsPartsIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LiveBoot('/live', '', 'tok');
    }
}
