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

namespace Milpa\Live\Support;

use Composer\InstalledVersions;

/**
 * Resolves the CONSUMING APPLICATION's filesystem root — the directory a consuming app runs
 * `npm install` from, and where `node_modules/@milpa/design` therefore lands — the one piece of
 * topology {@see MilpaDesign} needs and cannot safely assume about its own location.
 *
 * Ported from `milpa/devtools`' `Milpa\DevTools\Support\RootResolver` (same resolution order,
 * same failure mode) rather than depended on, to keep this package's own dependency graph free
 * of devtools' Doctrine coupling. Prior to this class, `MilpaDesign` computed its answer as
 * `dirname(__DIR__, 2)` from its own file — correct only when this package's `src/Support/`
 * happens to sit exactly two levels under the consuming application's root, which is true in
 * this monorepo's dev layout but false the moment `milpa/live-web` is Composer-vendored
 * (`vendor/milpa/live-web/...`, any install depth): the walk lands under `vendor/`, not at the
 * consuming app's root, so `node_modules/@milpa/design` is looked for in the wrong place and the
 * failure is silent — a same-named directory could even exist there by coincidence.
 *
 * Resolution order (first hit wins):
 *   1. an explicit root passed to the constructor — host wiring always wins (e.g. a container
 *      binding the app root once from a known-good source, or a test fixture);
 *   2. `Composer\InstalledVersions::getRootPackage()['install_path']` — the Composer-canonical
 *      answer to "where is the application that required me", correct regardless of install
 *      depth or path-repo vs. registry install, valid the instant Composer's generated
 *      autoloader is on the include path (which it always is for any Composer-managed PHP
 *      process — this is not an optional dependency, see `composer-runtime-api` in
 *      `composer.json`);
 *   3. walk up from `getcwd()` looking for the nearest ancestor `composer.json` — a last-resort
 *      fallback for the pathological case where Composer's own runtime API is unavailable (e.g.
 *      this package used outside a Composer-managed process entirely).
 *
 * Throws {@see RootNotFoundException} instead of returning a plausible-looking wrong path when
 * none of the three resolves — callers get an honest, loud failure.
 */
final class RootResolver
{
    public function __construct(private readonly ?string $explicitRoot = null)
    {
    }

    /** Resolves the consuming application's root directory per the three-tier order documented above. */
    public function resolve(): string
    {
        if ($this->explicitRoot !== null) {
            return $this->realOrFail($this->explicitRoot, 'explicit root');
        }

        $viaComposer = $this->fromInstalledVersions();
        if ($viaComposer !== null) {
            return $viaComposer;
        }

        $viaWalk = $this->fromCwdWalk();
        if ($viaWalk !== null) {
            return $viaWalk;
        }

        throw new RootNotFoundException(
            'could not resolve the consuming application root: no explicit root was given, '
            . 'Composer\\InstalledVersions is unavailable or reports no root package install_path, '
            . 'and no composer.json was found walking up from ' . (getcwd() ?: '(unknown cwd)'),
        );
    }

    private function fromInstalledVersions(): ?string
    {
        if (!class_exists(InstalledVersions::class)) {
            return null;
        }

        $root = InstalledVersions::getRootPackage()['install_path'];
        if ($root === '') {
            return null;
        }

        $real = realpath($root);

        return $real !== false ? $real : null;
    }

    private function fromCwdWalk(): ?string
    {
        $dir = getcwd();
        if ($dir === false) {
            return null;
        }

        while (true) {
            if (is_file($dir . '/composer.json')) {
                return $dir;
            }
            $parent = \dirname($dir);
            if ($parent === $dir) {
                return null;
            }
            $dir = $parent;
        }
    }

    private function realOrFail(string $path, string $source): string
    {
        $real = realpath($path);
        if ($real === false || !is_dir($real)) {
            throw new RootNotFoundException("{$source} '{$path}' does not resolve to a real directory");
        }

        return $real;
    }
}
