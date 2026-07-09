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

namespace Milpa\Live\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * End-to-end proof that `MilpaDesign::path()` resolves correctly once this
 * package is genuinely Composer-vendored, not just run from the monorepo.
 *
 * {@see \Milpa\Live\Tests\Support\RootResolverTest} proves the `RootResolver`
 * mechanism in isolation; this test proves the thing that actually matters:
 * a real consuming application, with `milpa/live-web` installed several
 * directories deep under its own `vendor/`, gets its OWN root back from
 * `MilpaDesign::path()` — not `vendor/milpa/live-web` (this package's own
 * install location), which is what the `dirname(__DIR__, 2)` calculation
 * this replaces would have silently produced.
 *
 * This can't run as a same-process unit test: `Composer\InstalledVersions`
 * is generated at autoload time from whichever `composer.json` is root for
 * *that* install, and this suite's own `vendor/autoload.php` already
 * declares `milpa/live-web` itself as root — loading a second, differently
 * rooted autoloader for the same classes in one PHP process would redeclare
 * them. So this spins up a real, temporary consuming application (its own
 * `composer.json` requiring `milpa/live-web` via a path repository, several
 * directories of `vendor/` nesting, its own `node_modules/@milpa/design`)
 * and asks it, in a subprocess, what `MilpaDesign::path()` resolves to.
 *
 * Requires network access for `composer install` to resolve `milpa/core`'s
 * small Packagist dependencies (`psr/container`, `psr/log`,
 * `symfony/event-dispatcher-contracts`) — skips itself rather than failing
 * the suite when that isn't available, since that's an environment
 * limitation, not a regression in the code under test.
 */
final class MilpaDesignVendorInstallTest extends TestCase
{
    private const CONSUMING_PACKAGES = ['milpa-live-web', 'milpa-core', 'milpa-live'];

    private ?string $appRoot = null;

    protected function tearDown(): void
    {
        if ($this->appRoot !== null && is_dir($this->appRoot)) {
            $this->removeDirectory($this->appRoot);
        }
    }

    public function testPathResolvesToTheConsumingAppRootNotThisPackagesVendorInstallLocation(): void
    {
        if (shell_exec('which composer 2>/dev/null') === null) {
            self::markTestSkipped('composer binary not found on PATH');
        }

        $packagesDir = \dirname(__DIR__, 3); // tests/Support -> tests -> package root -> packages/
        $this->appRoot = sys_get_temp_dir() . '/milpa-live-web-vendor-sim-' . bin2hex(random_bytes(6));

        mkdir($this->appRoot . '/node_modules/@milpa/design/dist', 0o775, true);
        file_put_contents($this->appRoot . '/node_modules/@milpa/design/dist/milpa-tokens.css', '');

        $repositories = array_map(
            static fn (string $package): array => ['type' => 'path', 'url' => $packagesDir . '/' . $package],
            self::CONSUMING_PACKAGES,
        );
        file_put_contents(
            $this->appRoot . '/composer.json',
            json_encode([
                'name' => 'acme/consuming-app-fixture',
                'require' => ['milpa/live-web' => '*'],
                'repositories' => $repositories,
                'minimum-stability' => 'dev',
                'prefer-stable' => true,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        $install = $this->runProcess('composer install --no-interaction --no-dev --no-progress 2>&1', $this->appRoot);
        if ($install['exitCode'] !== 0) {
            self::markTestSkipped("composer install failed (likely no network for milpa/core's Packagist deps):\n" . $install['output']);
        }

        self::assertFileExists(
            $this->appRoot . '/vendor/milpa/live-web/src/Support/MilpaDesign.php',
            'sanity check: milpa/live-web must actually be vendor-installed several directories deep for this simulation to mean anything',
        );

        $probe = $this->appRoot . '/probe.php';
        file_put_contents($probe, <<<'PHP'
            <?php
            putenv('MILPA_DESIGN_PATH');
            require __DIR__ . '/vendor/autoload.php';
            echo \Milpa\Live\Support\MilpaDesign::path();
            PHP);

        $probeRun = $this->runProcess('php ' . escapeshellarg($probe), $this->appRoot);
        self::assertSame(0, $probeRun['exitCode'], 'probe script failed: ' . $probeRun['output']);

        self::assertSame(
            realpath($this->appRoot . '/node_modules/@milpa/design'),
            $probeRun['output'],
            'MilpaDesign::path() must resolve to the CONSUMING APP root (where node_modules/@milpa/design '
            . 'actually lives), not to vendor/milpa/live-web (this package\'s own, unrelated install location) '
            . 'regardless of how many vendor/ directories separate the two.',
        );
    }

    /**
     * @return array{exitCode: int, output: string}
     */
    private function runProcess(string $command, string $cwd): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes, $cwd);
        self::assertIsResource($process, "failed to start: {$command}");

        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return ['exitCode' => $exitCode, 'output' => trim((string) $output)];
    }

    private function removeDirectory(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) && !is_link($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
