<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\Live\Tests\Support;

use Milpa\Live\Support\ComponentStyles;
use PHPUnit\Framework\TestCase;

final class ComponentStylesTest extends TestCase
{
    public function testTheShippedRendererStylesNeedNoPanelNpmOrExternalFonts(): void
    {
        $path = ComponentStyles::path();
        self::assertNotNull($path);
        self::assertFileExists($path);
        $css = file_get_contents($path);
        foreach (['.mui-input', '.mui-checkbox', '.mui-table', '.mui-card', '.mui-btn', 'prefers-reduced-motion', '@layer'] as $selector) {
            self::assertStringContainsString($selector, $css);
        }
        self::assertDoesNotMatchRegularExpression('/@import|url\s*\(/i', $css);
        self::assertSame('/ui/assets/milpa-components.css', ComponentStyles::url('/ui/assets/'));
        self::assertNull(ComponentStyles::path('../composer.json'));
        $source = json_decode(file_get_contents(dirname($path) . '/milpa-components.source.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('@milpa/design', $source['package']);
        self::assertCount(5, $source['sha256']);
    }
}
