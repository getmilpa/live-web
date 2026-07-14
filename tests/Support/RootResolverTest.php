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

namespace Milpa\Live\Tests\Support;

use Milpa\Live\Support\RootNotFoundException;
use Milpa\Live\Support\RootResolver;
use PHPUnit\Framework\TestCase;

final class RootResolverTest extends TestCase
{
    public function testExplicitRootWins(): void
    {
        $resolver = new RootResolver(__DIR__);

        self::assertSame(realpath(__DIR__), $resolver->resolve());
    }

    public function testExplicitRootThatDoesNotExistThrows(): void
    {
        $resolver = new RootResolver('/definitely/does/not/exist/' . uniqid());

        $this->expectException(RootNotFoundException::class);
        $resolver->resolve();
    }

    public function testInstalledVersionsResolvesToARealDirectoryWithAComposerJson(): void
    {
        // No explicit root: falls through to Composer\InstalledVersions::getRootPackage() (this
        // package's own composer.json when the suite runs standalone) or, failing that, the
        // getcwd()-walk fallback. Either way the answer must be a real directory that actually
        // owns a composer.json — the one topology guarantee callers rely on.
        $root = (new RootResolver())->resolve();

        self::assertDirectoryExists($root);
        self::assertFileExists($root . '/composer.json');
    }

    /**
     * Proves the getcwd()-walk fallback in isolation (not just as a side effect of the test
     * above, which is satisfied by the InstalledVersions branch alone since this package always
     * has ITS OWN composer.json as the Composer root package during its own test run): a
     * synthetic root with its own composer.json, invoked from three levels below it, must
     * resolve to THAT root — not a parent's, not a sibling's — regardless of how deep the caller
     * is nested. This is the mechanism that makes {@see RootResolver} — and therefore
     * `MilpaDesign::path()` — correct at ANY vendor install depth
     * (`vendor/milpa/live-web/src/Support/...`, no matter how the consuming app nests its own
     * vendor tree), unlike the `dirname(__DIR__, N)` calculation it replaces.
     */
    public function testCwdWalkFindsTheNearestAncestorComposerJsonRegardlessOfNestingDepth(): void
    {
        $tmp = sys_get_temp_dir() . '/milpa-live-web-root-' . uniqid();
        $nested = $tmp . '/vendor/milpa/live-web/src/Support';
        mkdir($nested, 0o775, true);
        file_put_contents($tmp . '/composer.json', '{}');
        $expected = realpath($tmp); // captured before cleanup removes the directory below

        $previousCwd = getcwd();
        self::assertNotFalse($previousCwd);
        chdir($nested);

        try {
            $method = new \ReflectionMethod(RootResolver::class, 'fromCwdWalk');
            $method->setAccessible(true);
            /** @var string|null $found */
            $found = $method->invoke(new RootResolver());
        } finally {
            chdir($previousCwd);
            $this->removeDirectory($tmp);
        }

        self::assertSame($expected, $found);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
