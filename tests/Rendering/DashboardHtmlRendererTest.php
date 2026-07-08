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

use Milpa\Live\Adapters\Alpine\AlpineRuntimeAdapter;
use Milpa\Live\Components\Dashboard\DashboardGridComponent;
use Milpa\Live\Components\Dashboard\DashboardMainComponent;
use Milpa\Live\Components\Dashboard\DashboardPanelComponent;
use Milpa\Live\Components\Dashboard\DashboardShellComponent;
use Milpa\Live\Components\Dashboard\DashboardSidebarComponent;
use Milpa\Live\Components\Dashboard\DashboardTopbarComponent;
use Milpa\Live\Components\Dashboard\DataTableComponent;
use Milpa\Live\Components\Dashboard\MetricCardComponent;
use Milpa\Live\Rendering\DashboardHtmlRenderer;
use Milpa\Live\Rendering\XhtmlComponentCompiler;
use Milpa\Live\Runtime\InMemoryComponentRegistry;
use Milpa\Live\Security\HmacStateSigner;
use Milpa\Live\Security\SignedXhtmlStateTransferCodec;
use Milpa\Live\Transport\XhtmlStateTransferCodec;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\RenderTarget;
use PHPUnit\Framework\TestCase;

/**
 * Converted from tests/smoke.php lines ~1096-1168 — the dashboard component
 * family compiled together through {@see XhtmlComponentCompiler}, rendered
 * to real Milpa Design markup via a signed state codec (matching the lab's
 * real wiring, not the plain unsigned codec).
 */
final class DashboardHtmlRendererTest extends TestCase
{
    public function testSupportsTargetIsHtmlOnly(): void
    {
        $renderer = new DashboardHtmlRenderer(new AlpineRuntimeAdapter(), new XhtmlStateTransferCodec());

        self::assertTrue($renderer->supportsTarget(RenderTarget::HTML));
        self::assertFalse($renderer->supportsTarget(RenderTarget::TUI));
    }

    public function testCompileRendersTheFullDashboardWithMilpaDesignMarkup(): void
    {
        $signedCodec = new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner('lab-secret-for-signatures'));
        $dashboardRenderer = new DashboardHtmlRenderer(new AlpineRuntimeAdapter(), $signedCodec);

        $components = new InMemoryComponentRegistry();
        $components->register('dashboard-shell', new DashboardShellComponent());
        $components->register('dashboard-sidebar', new DashboardSidebarComponent());
        $components->register('dashboard-main', new DashboardMainComponent());
        $components->register('dashboard-topbar', new DashboardTopbarComponent());
        $components->register('dashboard-grid', new DashboardGridComponent());
        $components->register('dashboard-panel', new DashboardPanelComponent());
        $components->register('metric-card', new MetricCardComponent());
        $dataTable = new DataTableComponent();
        $components->register('data-table', $dataTable);

        $compiler = new XhtmlComponentCompiler(
            $components,
            [
                'dashboard-shell' => $dashboardRenderer,
                'dashboard-sidebar' => $dashboardRenderer,
                'dashboard-main' => $dashboardRenderer,
                'dashboard-topbar' => $dashboardRenderer,
                'dashboard-grid' => $dashboardRenderer,
                'dashboard-panel' => $dashboardRenderer,
                'metric-card' => $dashboardRenderer,
                'data-table' => $dashboardRenderer,
            ],
            [
                'dashboard-sidebar' => [
                    'items' => [
                        ['key' => 'overview', 'label' => 'Overview', 'href' => '#overview'],
                    ],
                ],
                'data-table' => [
                    'columns' => [
                        ['key' => 'account', 'label' => 'Account'],
                        ['key' => 'value', 'label' => 'Value', 'align' => 'right'],
                    ],
                    'rows' => [
                        ['id' => 'deal-1', 'account' => 'Maiz Commerce', 'value' => '$42k'],
                    ],
                    'selectable' => true,
                ],
            ],
        );

        $compiled = $compiler->compile(
            <<<'XHTML'
<milpa:dashboard-shell id="ops-shell" main-id="ops-main">
    <milpa:dashboard-sidebar id="ops-sidebar" brand="Milpa" active="overview" />
    <milpa:dashboard-topbar id="ops-topbar" title="Operations" controls="ops-sidebar" />
    <milpa:dashboard-main id="ops-main">
        <milpa:dashboard-grid id="kpi-grid" columns="2">
            <milpa:metric-card id="metric-revenue" title="Revenue" value="$128k" delta="+12%" trend="up" caption="vs last week" />
        </milpa:dashboard-grid>
        <milpa:dashboard-panel id="pipeline-panel" title="Pipeline">
            <milpa:data-table id="pipeline-table" name="pipeline_rows" persist-key="demo.pipeline" />
        </milpa:dashboard-panel>
    </milpa:dashboard-main>
</milpa:dashboard-shell>
XHTML,
            new ComponentContext('dashboard-prototype', route: '/lab/dashboard'),
        );

        self::assertStringContainsString('mui-shell', $compiled->output);
        self::assertStringContainsString('mui-sidebar', $compiled->output);
        self::assertStringContainsString('mui-topbar', $compiled->output);
        self::assertStringContainsString('mui-card', $compiled->output);
        self::assertStringContainsString('mui-stat', $compiled->output);
        self::assertStringContainsString('mui-table-wrap', $compiled->output);
        self::assertStringContainsString('mui-table__sort', $compiled->output);
        self::assertStringContainsString(':aria-sort="sortState(&quot;account&quot;)"', $compiled->output);
        self::assertStringContainsString(':aria-selected="isSelected(&quot;deal-1&quot;)', $compiled->output);
        self::assertStringContainsString('milpaDataTable(', $compiled->output);
        self::assertStringContainsString('security="signed"', $compiled->output, 'dashboard states must be signed when rendered via the signed codec');
        self::assertStringNotContainsString('@click="sort("', $compiled->output, 'sort handlers must be escaped');
        self::assertStringNotContainsString('mui-dashboard-shell', $compiled->output, 'legacy local dashboard shell class must not reappear');
    }
}
