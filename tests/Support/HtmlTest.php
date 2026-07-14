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

namespace Milpa\Live\Tests\Support;

use Milpa\Live\Support\Html;
use PHPUnit\Framework\TestCase;

final class HtmlTest extends TestCase
{
    public function testAttrsRendersScalarAttributesAsKeyValuePairs(): void
    {
        self::assertSame('id="x" class="mui-field"', Html::attrs(['id' => 'x', 'class' => 'mui-field']));
    }

    public function testAttrsRendersTrueAsABooleanAttribute(): void
    {
        self::assertSame('disabled', Html::attrs(['disabled' => true]));
    }

    public function testAttrsOmitsFalseAndNullValues(): void
    {
        self::assertSame('', Html::attrs(['hidden' => false, 'value' => null]));
    }

    public function testAttrsEscapesValues(): void
    {
        self::assertSame('data-x="&lt;script&gt;"', Html::attrs(['data-x' => '<script>']));
    }

    public function testEscapeEscapesHtmlSpecialCharacters(): void
    {
        self::assertSame('&lt;b&gt;&amp;&lt;/b&gt;', Html::escape('<b>&</b>'));
    }
}
