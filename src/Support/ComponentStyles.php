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

/**
 * The design-system CSS required by this transport's renderers, shipped without an npm install or panel.
 * Generated from @milpa/design by scripts/build-component-styles.php (Greenhouse decisions/0326).
 * Fonts remain the local files supplied by DesignTokens; this bundle makes no external requests.
 */
final class ComponentStyles
{
    public const FILE = 'milpa-components.css';

    /** Resolve only the shipped bundle, never a caller-provided filesystem path. */
    public static function path(string $name = self::FILE): ?string
    {
        return $name === self::FILE ? \dirname(__DIR__, 2) . '/resources/design/' . self::FILE : null;
    }

    /** The URL under the host's own asset mount. */
    public static function url(string $prefix): string
    {
        return rtrim($prefix, '/') . '/' . self::FILE;
    }
}
