<?php

declare(strict_types=1);

namespace Milpa\Live\Tests\Support;

use Milpa\Live\Support\ClientRuntime;
use PHPUnit\Framework\TestCase;

/**
 * The three client files ship with the package, are found by name, and keep the URL the Alpine
 * adapter already promised (greenhouse decisions/0083).
 */
final class ClientRuntimeTest extends TestCase
{
    public function testTheThreeFilesShipAndAreFoundByName(): void
    {
        foreach ([ClientRuntime::LOCAL, ClientRuntime::REMOTE, ClientRuntime::ALPINE] as $name) {
            $path = ClientRuntime::path($name);
            self::assertNotNull($path, $name);
            self::assertFileExists($path);
            self::assertGreaterThan(0, filesize($path));
        }
        self::assertNull(ClientRuntime::path('../composer.json'), 'only the three names resolve — never a path');
        self::assertNull(ClientRuntime::path('nope.js'));
    }

    public function testTheLocalRuntimeKeepsItsFrozenContract(): void
    {
        $local = (string) file_get_contents((string) ClientRuntime::path(ClientRuntime::LOCAL));
        self::assertStringNotContainsString('fetch(', $local, 'the local runtime never touches the network (ADR#9)');
        self::assertStringContainsString("Alpine.data('milpaField'", $local);
        self::assertStringContainsString("Alpine.data('milpaCheckbox'", $local);
        // One owner (greenhouse decisions/0145): the local runtime backs every LOCAL factory its
        // renderers emit — milpaDataTable included (kept in storage, never over the wire).
        self::assertStringContainsString("Alpine.data('milpaDataTable'", $local);

        $remote = (string) file_get_contents((string) ClientRuntime::path(ClientRuntime::REMOTE));
        self::assertStringContainsString('fetch(', $remote, 'the remote runtime is the layer that takes actions over the wire');
        self::assertStringContainsString("Alpine.data('milpaDataTable'", $remote);
        self::assertStringContainsString('milpa-live-boot', $remote);
        self::assertStringContainsString('data-milpa-state', $remote, 'it echoes the envelope the server signed');
    }

    public function testTheDefaultUrlsKeepTheAdaptersPromise(): void
    {
        $urls = ClientRuntime::defaultUrls();
        self::assertSame('/milpa-live.js', $urls[ClientRuntime::LOCAL]);
        self::assertSame('/milpa-live-remote.js', $urls[ClientRuntime::REMOTE]);
        self::assertSame('/vendor/alpine.min.js', $urls[ClientRuntime::ALPINE]);
        self::assertSame('application/javascript; charset=utf-8', ClientRuntime::contentType());
        self::assertStringContainsString(ClientRuntime::ALPINE_VERSION, (string) file_get_contents(\dirname(__DIR__, 2) . '/resources/vendor/README.md'));
    }
}
