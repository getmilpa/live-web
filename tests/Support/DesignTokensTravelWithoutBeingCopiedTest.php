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

namespace Milpa\Live\Tests\Support;

use Milpa\Live\Support\DesignTokens;
use PHPUnit\Framework\TestCase;

/**
 * The tokens ship so a surface can look like the house without copying them (greenhouse
 * decisions/0243).
 *
 * Three packages carried identical copies that had already drifted from the source — all three
 * missing `--space-32`. The surface that made it visible was the passkey ceremony, served by a
 * package that vendors nothing and therefore had nowhere to get the house's vocabulary from.
 */
final class DesignTokensTravelWithoutBeingCopiedTest extends TestCase
{
    /** The stylesheet ships, and it is the one this package names. */
    public function testTheStylesheetShipsWithThePackage(): void
    {
        $path = DesignTokens::path(DesignTokens::TOKENS);

        self::assertNotNull($path, 'a token file that is not shipped is a promise nobody can keep');
        self::assertFileExists($path);
        self::assertStringEndsWith('/resources/design/milpa-tokens.css', $path);
    }

    /**
     * IT CARRIES THE TOKEN THE COPIES LOST.
     *
     * `--space-32` is what proved the drift: three vendored copies, identical to each other and all
     * three missing it. If what ships here were taken from one of those copies, this is a fourth.
     */
    public function testItCarriesTheTokenTheVendoredCopiesLost(): void
    {
        $css = (string) file_get_contents((string) DesignTokens::path(DesignTokens::TOKENS));

        self::assertStringContainsString('--space-32', $css);
        self::assertStringContainsString('--tierra-950', $css, 'and the ramps the house is built on');
    }

    /**
     * THE ONE THAT CAN SAY NO: it resolves ITS file and nothing else.
     *
     * A host serves whatever this returns, so a name it does not own must come back null rather than
     * a path — otherwise the seam is a file read with extra steps.
     */
    public function testItResolvesOnlyItsOwnFile(): void
    {
        self::assertNull(DesignTokens::path('evil.css'));
        self::assertNull(DesignTokens::path('../../composer.json'));
        self::assertNull(DesignTokens::path(''));
    }

    /** A stylesheet is served as one, and a face as a face. */
    public function testEachFileIsServedAsWhatItIs(): void
    {
        self::assertSame('text/css; charset=utf-8', DesignTokens::contentType());
        self::assertSame('text/css; charset=utf-8', DesignTokens::contentType(DesignTokens::FONTS));
        self::assertSame('font/woff2', DesignTokens::contentType('space-grotesk-latin.woff2'));
    }

    /**
     * THE FACES SHIP, because naming a family nothing loads is what this fixes.
     *
     * The tokens said `Space Grotesk` and `Space Mono` while zero `@font-face` existed anywhere in
     * the family, so every surface rendered in whatever the viewer's machine had. Shipped rather
     * than fetched: a self-hosted panel should not reach the network to look like itself.
     */
    public function testTheFacesShipAndAreRealWoff2(): void
    {
        $faces = array_filter(array_keys(DesignTokens::defaultUrls()), static fn (string $n): bool => str_ends_with($n, '.woff2'));

        self::assertCount(6, $faces, 'Space Grotesk variable + Space Mono 400/700, latin and latin-ext');
        foreach ($faces as $face) {
            $path = DesignTokens::path($face);
            self::assertNotNull($path, $face . ' is named and not shipped');
            self::assertSame('wOF2', (string) file_get_contents($path, false, null, 0, 4), $face . ' is not a woff2');
        }
    }

    /**
     * THE STYLESHEET AND THE FILES AGREE — every `src` it names is a file that ships.
     *
     * A `@font-face` pointing at a missing file fails silently: the browser falls back and the page
     * looks styled while it is not, which is the exact bug this slice exists to end.
     */
    public function testEverySrcInTheStylesheetIsAFileThatShips(): void
    {
        $css = (string) file_get_contents((string) DesignTokens::path(DesignTokens::FONTS));
        preg_match_all("#url\('fonts/([^']+)'\)#", $css, $m);

        self::assertNotSame([], $m[1], 'a font stylesheet that names no file is not one');
        foreach ($m[1] as $file) {
            self::assertNotNull(DesignTokens::path($file), $file . ' is named by the stylesheet and does not ship');
        }
        // Counted as RULES and not as mentions: the file's own header explains why zero `@font-face`
        // existed before, so counting the word finds seven and the test lies about the sixth face.
        self::assertSame(6, preg_match_all('/@font-face\s*\{/', $css), 'six faces, counted as rules');
        self::assertCount(6, $m[1]);
    }

    /**
     * EL WORDMARK VIAJA COMO VECTOR, y es el del kit byte a byte.
     *
     * El kit lo exige: «El wordmark NUNCA se construye con tipografía + trucos CSS… Usá SIEMPRE el
     * vector». Y byte a byte porque una copia que diverge es lo que este paquete existe para acabar:
     * si esto se hubiera pegado a mano o retocado, el hash lo dice.
     */
    public function testTheWordmarkShipsAsTheKitsVector(): void
    {
        foreach ([DesignTokens::WORDMARK, DesignTokens::WORDMARK_LIGHT] as $name) {
            $path = DesignTokens::path($name);
            self::assertNotNull($path, $name . ' is named and not shipped');
            $svg = (string) file_get_contents($path);
            self::assertStringStartsWith('<svg', $svg);
            self::assertStringContainsString('viewBox="0 0 2406.90 900.00"', $svg, 'the kit\'s own viewBox');
            self::assertStringContainsString('#E8B14C', $svg, 'the grano stays the kit gold in both variants');
        }
    }

    /**
     * THE ONE THAT CAN SAY NO: a vector is served as a vector.
     *
     * `contentType()` used to answer `text/css` for anything that was not a woff2. Serving image
     * bytes as a stylesheet renders NOTHING and raises no error — the page looks styled while it is
     * not, which is the exact failure shape this class exists to end.
     */
    public function testEachKindIsServedAsItsOwnKind(): void
    {
        self::assertSame('image/svg+xml', DesignTokens::contentType(DesignTokens::WORDMARK));
        self::assertSame('image/svg+xml', DesignTokens::contentType(DesignTokens::WORDMARK_LIGHT));
        self::assertSame('font/woff2', DesignTokens::contentType('space-mono-400-latin.woff2'));
        self::assertSame('text/css; charset=utf-8', DesignTokens::contentType(DesignTokens::TOKENS));
    }

    /** The licence travels with what it licenses. */
    public function testTheLicenceShipsBesideTheFaces(): void
    {
        $dir = \dirname((string) DesignTokens::path('space-grotesk-latin.woff2'));

        self::assertFileExists($dir . '/OFL.txt');
        self::assertStringContainsString('SIL Open Font License', (string) file_get_contents($dir . '/OFL.txt'));
    }

    /** The default URLs keep the `fonts/` segment the stylesheet asks for. */
    public function testTheUrlsKeepThePathTheStylesheetNames(): void
    {
        $urls = DesignTokens::defaultUrls();

        self::assertSame('/milpa-tokens.css', $urls[DesignTokens::TOKENS]);
        self::assertSame('/milpa-fonts.css', $urls[DesignTokens::FONTS]);
        self::assertSame('/fonts/space-grotesk-latin.woff2', $urls['space-grotesk-latin.woff2'], 'flattening this serves a stylesheet whose every src is a 404');
    }
}
