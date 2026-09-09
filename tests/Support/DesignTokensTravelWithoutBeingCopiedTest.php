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

    /** A stylesheet is served as one. */
    public function testItIsServedAsAStylesheet(): void
    {
        self::assertSame('text/css; charset=utf-8', DesignTokens::contentType());
        self::assertSame(['milpa-tokens.css' => '/milpa-tokens.css'], DesignTokens::defaultUrls());
    }
}
