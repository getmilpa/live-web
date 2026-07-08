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

namespace Milpa\Live\Tests\Rendering;

use Milpa\Live\Rendering\LatteTemplateRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Converted from tests/smoke.php lines ~1034-1058. Uses this package's own
 * `templates/` directory (the runtime resource `LatteTemplateRenderer`
 * defaults to two levels above `src/Rendering/`), proving the package is
 * standalone-renderable without the lab present.
 */
final class LatteTemplateRendererTest extends TestCase
{
    public function testDefaultViewPathResolvesToThePackageTemplatesDirectory(): void
    {
        $renderer = new LatteTemplateRenderer();

        $probe = $renderer->render('components/input.latte', [
            'componentId' => 'latte-probe',
            'stateEnvelope' => '<milpa-state component-id="latte-probe"></milpa-state>',
            'rootAttrs' => 'class="mui-field"',
            'labelHtml' => '<label class="mui-field__label" for="latte-probe-field">Probe</label>',
            'controlAttrs' => 'class="mui-input" id="latte-probe-field"',
            'hintHtml' => '',
            'errorId' => 'latte-probe-error',
        ]);

        self::assertStringContainsString('<input class="mui-input"', $probe);
        self::assertStringContainsString('type="application/milpa+xhtml"', $probe, 'the shared component layout must embed the state envelope partial');
    }

    public function testForeachRendersOnceyPerItem(): void
    {
        $renderer = new LatteTemplateRenderer();

        $probe = $renderer->render('components/dashboard-alert-list.latte', [
            'componentId' => 'alerts',
            'stateEnvelope' => '<milpa-state component-id="alerts"></milpa-state>',
            'items' => [
                ['count' => '2', 'text' => 'contracts need legal review'],
            ],
        ]);

        self::assertStringContainsString('<strong>2</strong> contracts need legal review', $probe);
    }

    public function testConstructorRejectsANonexistentViewPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LatteTemplateRenderer('/does/not/exist/at/all');
    }

    public function testResolveRejectsATemplateOutsideTheViewPath(): void
    {
        $renderer = new LatteTemplateRenderer(__DIR__ . '/../../templates/components');

        $this->expectException(\RuntimeException::class);
        $renderer->render('../../../composer.json', []);
    }
}
