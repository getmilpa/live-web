<?php

/**
 * This file is part of milpa/live-web — the HTTP/HTML transport of Milpa Components.
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
 * The design tokens of Milpa, shipped so a surface can look like the house WITHOUT copying them.
 *
 * ── WHY THIS EXISTS, AND WHY HERE ────────────────────────────────────────────────────────────────
 *
 * Until now the tokens only travelled by being copied into a package's own `assets/`. Three packages
 * carried them — `milpa/admin`, `milpa/agent-workspace`, `milpa/desktop-app` — with identical copies
 * that had already DRIFTED from the source: measured by hash, all three were missing `--space-32`,
 * a token added upstream that never came down (greenhouse decisions/0243).
 *
 * The surface that made it visible was the passkey ceremony. `/webauthn/enroll` is served by
 * `milpa/app-runtime`, which vendors nothing, so its page carried `system-ui` and hand-picked hex —
 * not out of neglect, but because there was nowhere to get the house's own vocabulary from. And it
 * could not simply depend on the panel: enrolling is the PRECONDITION of having a panel session, so
 * a house with no panel still has to be able to let somebody in.
 *
 * They live here because this package already ships what a component needs to run in a browser and
 * depends only on `milpa/core` and `milpa/live` — the same reach the tokens need, and no cycle.
 *
 * ── WHAT THIS IS NOT ─────────────────────────────────────────────────────────────────────────────
 *
 * Not the component stylesheet. The tokens are the house's VOCABULARY — its ramps, its type scale,
 * its spacing; the bundle that styles `mui-*` classes is a different artifact with a different
 * lifetime, and it stays where it is until something measured says otherwise.
 *
 * A separate class from {@see ClientRuntime} on purpose: that one's docblock says «two runtimes and
 * one vendored library, ON PURPOSE», naming a decision. A stylesheet is not a runtime, and folding
 * it in would blur a boundary somebody drew deliberately.
 */
final class DesignTokens
{
    public const TOKENS = 'milpa-tokens.css';

    /** The absolute path of the shipped stylesheet, or null when `$name` is not it. */
    public static function path(string $name): ?string
    {
        if ($name !== self::TOKENS) {
            return null;
        }
        $file = \dirname(__DIR__, 2) . '/resources/design/' . self::TOKENS;

        return is_file($file) ? $file : null;
    }

    /**
     * The URL a host serves it at by default.
     *
     * @return array<string, string> name => URL
     */
    public static function defaultUrls(): array
    {
        return [self::TOKENS => '/' . self::TOKENS];
    }

    /** The MIME type a host should serve it with. */
    public static function contentType(): string
    {
        return 'text/css; charset=utf-8';
    }
}
