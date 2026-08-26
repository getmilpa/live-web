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
 * The client-side files this package ships, by name — where they live on disk and where a host
 * serves them by default.
 *
 * Two runtimes and one vendored library, on purpose (ADR#9/#10, greenhouse decisions/0083): the
 * LOCAL runtime (`milpa-live.js`, frozen: activates what the server declared, never touches the
 * network), the REMOTE runtime (`milpa-live-remote.js`: takes declared actions to the LiveEndpoint
 * and applies the answer), and Alpine.js (vendored, MIT — see resources/vendor/README.md). A host
 * that mounts the live routes serves these three files; it never inlines or rewrites them.
 */
final class ClientRuntime
{
    public const LOCAL = 'milpa-live.js';

    public const REMOTE = 'milpa-live-remote.js';

    public const ALPINE = 'alpine.min.js';

    /** The Alpine.js version vendored under resources/vendor (bump with the file). */
    public const ALPINE_VERSION = '3.14.3';

    /** The absolute path of a shipped file, or null when `$name` is not one of the three. */
    public static function path(string $name): ?string
    {
        $file = match ($name) {
            self::LOCAL, self::REMOTE => self::resources() . '/' . $name,
            self::ALPINE => self::resources() . '/vendor/' . $name,
            default => null,
        };

        return $file !== null && is_file($file) ? $file : null;
    }

    /**
     * The URLs a host serves the files at by default — the local runtime keeps the `/milpa-live.js`
     * URL the Alpine adapter already promised.
     *
     * @return array<string, string> name => URL
     */
    public static function defaultUrls(): array
    {
        return [
            self::LOCAL => '/milpa-live.js',
            self::REMOTE => '/milpa-live-remote.js',
            self::ALPINE => '/vendor/alpine.min.js',
        ];
    }

    /** The MIME type a host should serve the files with. */
    public static function contentType(): string
    {
        return 'application/javascript; charset=utf-8';
    }

    private static function resources(): string
    {
        return \dirname(__DIR__, 2) . '/resources';
    }
}
