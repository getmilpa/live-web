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
 * HTML-escaping helpers shared by every renderer and the template engine in
 * this package: safe attribute-string assembly and a single escaping
 * primitive both are built on.
 */
final class Html
{
    /**
     * Renders `$attributes` as a space-joined, HTML-escaped attribute
     * string. A `null` or `false` value omits the attribute entirely; `true`
     * emits the bare attribute name (a boolean HTML attribute, e.g.
     * `required`); any other value is escaped and quoted.
     *
     * @param array<string, string|null|bool|int|float> $attributes
     */
    public static function attrs(array $attributes): string
    {
        $out = [];

        foreach ($attributes as $name => $value) {
            if ($value === false || $value === null) {
                continue;
            }

            if ($value === true) {
                $out[] = self::escape($name);
                continue;
            }

            $out[] = sprintf('%s="%s"', self::escape($name), self::escape((string) $value));
        }

        return implode(' ', $out);
    }

    /**
     * HTML-escapes `$value` for safe interpolation into markup: quotes,
     * ampersands, and angle brackets are all escaped (`ENT_QUOTES`), and an
     * invalid UTF-8 byte sequence is substituted rather than aborting
     * (`ENT_SUBSTITUTE`).
     */
    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
