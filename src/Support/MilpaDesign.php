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

namespace Milpa\Live\Support;

/**
 * Locates the `@milpa/design` package on disk and resolves paths within it.
 * Consuming applications install `@milpa/design` via npm at the project
 * root (`node_modules/@milpa/design`); a lab/test checkout without an npm
 * install can instead point `MILPA_DESIGN_PATH` at a local `milpa-design`
 * checkout — every method here checks the env override first and falls back
 * to the npm layout.
 */
final class MilpaDesign
{
    private const PACKAGE_PATH = 'node_modules/@milpa/design';

    /**
     * The design system's stylesheet files this package's templates expect,
     * keyed by their path relative to the design package root and resolved
     * to absolute filesystem paths under {@see path()}.
     *
     * @return array<string, string>
     */
    public static function cssFiles(): array
    {
        $basePath = self::path();

        return [
            'dist/milpa-tokens.css' => $basePath . '/dist/milpa-tokens.css',
            'motion/milpa-motion.css' => $basePath . '/motion/milpa-motion.css',
            'primitives/milpa-primitives.css' => $basePath . '/primitives/milpa-primitives.css',
            'components/milpa-components.css' => $basePath . '/components/milpa-components.css',
            'artifacts/milpa-artifacts.css' => $basePath . '/artifacts/milpa-artifacts.css',
            'layouts/milpa-layouts.css' => $basePath . '/layouts/milpa-layouts.css',
        ];
    }

    /**
     * Resolves the `@milpa/design` package root: `MILPA_DESIGN_PATH` when
     * set, otherwise `node_modules/@milpa/design` under the consuming
     * application's root (see {@see projectRoot()} / {@see RootResolver} —
     * this is NOT this package's own install location, which may sit under
     * `vendor/` at any depth). Throws `RuntimeException` if the resolved
     * directory does not exist.
     */
    public static function path(): string
    {
        $override = getenv('MILPA_DESIGN_PATH');
        if (is_string($override) && $override !== '') {
            return self::normalizeExistingPath($override, 'MILPA_DESIGN_PATH');
        }

        return self::normalizeExistingPath(self::projectRoot() . '/' . self::PACKAGE_PATH, 'npm package @milpa/design');
    }

    /**
     * A human-readable label for where {@see path()} resolved its answer
     * from — `"env:MILPA_DESIGN_PATH"` or `"npm:@milpa/design"` — useful in
     * diagnostics and error messages.
     */
    public static function source(): string
    {
        $override = getenv('MILPA_DESIGN_PATH');

        return is_string($override) && $override !== '' ? 'env:MILPA_DESIGN_PATH' : 'npm:@milpa/design';
    }

    /**
     * A stable identifier for a path within the design package, e.g.
     * `"@milpa/design:dist/milpa-tokens.css"`, for use in contract/error
     * references that name a design-system asset without hardcoding a
     * filesystem path.
     */
    public static function contract(string $relativePath): string
    {
        return '@milpa/design:' . ltrim($relativePath, '/');
    }

    /**
     * The consuming application's root, resolved vendor-depth-independently
     * via {@see RootResolver} (Composer `InstalledVersions`' root package,
     * falling back to a `composer.json` walk from `getcwd()`) — NOT
     * `dirname(__DIR__, N)` from this file, which would answer "where is
     * `milpa/live-web` installed" instead of "where is the app that
     * installed it", and breaks silently once this package is
     * Composer-vendored.
     */
    private static function projectRoot(): string
    {
        return (new RootResolver())->resolve();
    }

    private static function normalizeExistingPath(string $path, string $source): string
    {
        $normalized = rtrim($path, '/');

        if (!is_dir($normalized)) {
            throw new \RuntimeException(
                "Milpa Design source not found for {$source}: {$normalized}. "
                . "Run npm install or set MILPA_DESIGN_PATH to a local milpa-design checkout.",
            );
        }

        return $normalized;
    }
}
