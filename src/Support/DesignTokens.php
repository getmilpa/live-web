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

    public const FONTS = 'milpa-fonts.css';

    /**
     * The wordmark, as vector art, in its two theme variants.
     *
     * The logo kit states it as a hard rule: the wordmark is never assembled from type plus CSS
     * tricks — a displaced span or pseudo-element standing in for the grain leaves the grain
     * floating between letters and the `i` keeping its own dot, so the mark reads with two. It
     * ships as a file for that reason, rather than as markup a surface could reconstruct.
     *
     * Two variants because the kit draws the letters in `currentColor` while these are the
     * resolved bicolour marks: the grain stays gold in both and the letters change with the ground.
     * A surface that pins `data-theme="dark"` needs only the first; one that honours the reader's
     * theme needs both.
     */
    /**
     * The mark's gold, stated once.
     *
     * It is deliberately NOT a CSS custom property: the mark is brand, not UI, so it stays constant
     * in both themes while every token in the system is free to change with the ground. Anything
     * that needs this colour reads it here, which is what keeps "one place states it" true.
     */
    public const MARK_GOLD = '#E8B14C';

    /**
     * THE APP ICON — what a browser puts in a tab, and what it asks for at `/favicon.ico` when no page
     * says otherwise.
     *
     * 🚨 IT SHIPS HERE BECAUSE FIVE PAGES NEEDED IT AND NONE DECLARED ONE. Measured on a rendered
     * panel: `GET /favicon.ico` answered 404 on every load, because a page that declares no icon makes
     * the browser guess at the origin root — a root no plugin owns. The mark is the logo kit's own
     * (`logo/favicon/milpa-app-icon.svg`): the grain on the house's dark ground, which reads on either
     * theme without a second file (greenhouse decisions/0286).
     */
    public const APP_ICON = 'milpa-app-icon.svg';

    public const WORDMARK = 'milpa-wordmark.svg';

    public const WORDMARK_LIGHT = 'milpa-wordmark-light.svg';

    /**
     * The faces, shipped rather than fetched — Rod's decision (greenhouse decisions/0243).
     *
     * The tokens named `Space Grotesk` and `Space Mono` and NOTHING loaded them: zero `@font-face`
     * in the tokens, in the panel's bundle or in the design kit, so every Milpa surface rendered in
     * whatever the viewer's machine happened to have. A self-hosted panel should not have to reach
     * the network to look like itself, and an offline deployment cannot.
     *
     * Space Grotesk is VARIABLE (300–700): one file covers regular, medium, semibold and bold.
     * Space Mono is static, and only the two weights the tokens name ship.
     *
     * OFL-1.1, with the licence beside the files — the same way this package already vendors Alpine.
     */
    private const FACES = [
        'space-grotesk-latin.woff2',
        'space-grotesk-latin-ext.woff2',
        'space-mono-400-latin.woff2',
        'space-mono-400-latin-ext.woff2',
        'space-mono-700-latin.woff2',
        'space-mono-700-latin-ext.woff2',
    ];

    /**
     * The absolute path of a shipped file, or null when `$name` is not one of them.
     *
     * A name this package does not own answers `null` and never a path: a host serves whatever this
     * returns, so anything looser would be a file read with extra steps.
     */
    public static function path(string $name): ?string
    {
        $dir = \dirname(__DIR__, 2) . '/resources/design/';
        $file = match (true) {
            $name === self::TOKENS, $name === self::FONTS,
            $name === self::WORDMARK, $name === self::WORDMARK_LIGHT,
            $name === self::APP_ICON => $dir . $name,
            \in_array($name, self::FACES, true) => $dir . 'fonts/' . $name,
            default => null,
        };

        return $file !== null && is_file($file) ? $file : null;
    }

    /**
     * THE URLS ONE HOST SERVES THESE AT, UNDER ITS OWN PREFIX — the canon, called instead of typed.
     *
     * 🚨 EACH HOST STILL SERVES THEM ITSELF. That is a decision, written where it is made
     * ({@see iconLink()}: «each serves this file from its own asset route, with its own cache
     * policy»), and this method does not touch it: a plugin whose pages work the moment it is
     * installed cannot depend on another plugin's routes being mounted. What it replaces is the
     * TYPING.
     *
     * Measured before writing it: nine sites across three packages spelled these names into route
     * declarations and `<link>` tags by hand — five routes in `milpa/app-runtime`'s passkey plugin,
     * three `<link>`s in the framework's welcome page, a wordmark `<img>` in the gate renderer — while
     * `defaultUrls()`, which exists for exactly this, was called by nothing in any `src/`. Only its
     * own test called it. The canon was written and never wired, which is how a fourth prefix
     * (`/design/`) got invented in one afternoon without its author seeing the other three
     * (greenhouse decisions/0308).
     *
     * The `fonts/` segment is the reason a shape authority is worth having at all: `milpa-fonts.css`
     * names its faces RELATIVELY, so a host that flattens them serves a stylesheet whose every `src`
     * is a 404 — a defect that shows up as missing type, not as an error.
     *
     * @param string $prefix the host's own mount point, with no trailing slash (`''` serves at the root)
     *
     * @return array<string, string> name => URL
     */
    public static function urls(string $prefix = ''): array
    {
        $at = rtrim($prefix, '/');

        $urls = [
            self::TOKENS => $at . '/' . self::TOKENS,
            self::FONTS => $at . '/' . self::FONTS,
            self::WORDMARK => $at . '/' . self::WORDMARK,
            self::WORDMARK_LIGHT => $at . '/' . self::WORDMARK_LIGHT,
            self::APP_ICON => $at . '/' . self::APP_ICON,
        ];
        foreach (self::FACES as $face) {
            $urls[$face] = $at . '/fonts/' . $face;
        }

        return $urls;
    }

    /**
     * The same set at the root — {@see urls()} with no prefix.
     *
     * Kept because it is published API and two tests name it; it is now one call deep so the two can
     * never disagree about the shape.
     *
     * @return array<string, string> name => URL
     */
    public static function defaultUrls(): array
    {
        return self::urls();
    }

    /**
     * The `<link>` a page declares its icon with — ONE tag, because five pages needed it.
     *
     * 🚨 THE POINT IS THAT THE PAGE DECLARES IT, not that a route exists. A browser only asks for
     * `/favicon.ico` when the document says nothing, and that root belongs to no plugin — so five
     * hosts writing five copies of this tag was the duplication, and five 404s was the symptom
     * (greenhouse decisions/0286).
     *
     * `$href` is the host's: each serves this file from its own asset route, with its own cache policy.
     */
    public static function iconLink(string $href): string
    {
        return '<link rel="icon" type="' . self::contentType(self::APP_ICON) . '" href="'
            . htmlspecialchars($href, ENT_QUOTES) . '">';
    }

    /**
     * The MIME type a host should serve `$name` with — by extension, not by default.
     *
     * Falling back to `text/css` for anything unrecognised is how an image gets served as a
     * stylesheet: the `<img>` renders nothing, no error is raised, and the page looks styled while
     * it is not. Same failure shape this class exists to end, one level down.
     */
    public static function contentType(string $name = self::TOKENS): string
    {
        return match (true) {
            str_ends_with($name, '.woff2') => 'font/woff2',
            str_ends_with($name, '.svg') => 'image/svg+xml',
            default => 'text/css; charset=utf-8',
        };
    }
}
