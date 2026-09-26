<?php

/**
 * This file is part of Milpa Live Web — the HTTP/HTML transport of Milpa Live.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/live-web
 */

declare(strict_types=1);

namespace Milpa\Live\Tests\Rendering;

use Milpa\Live\Components\Autocomplete\AutocompleteComponent;
use Milpa\Live\Components\CodeBlockComponent;
use Milpa\Live\Components\ContentComponent;
use Milpa\Live\Components\Dashboard\DashboardActionButtonComponent;
use Milpa\Live\Components\Dashboard\DashboardAlertListComponent;
use Milpa\Live\Components\Dashboard\DashboardGridComponent;
use Milpa\Live\Components\Dashboard\DashboardMainComponent;
use Milpa\Live\Components\Dashboard\DashboardPageHeaderComponent;
use Milpa\Live\Components\Dashboard\DashboardPanelComponent;
use Milpa\Live\Components\Dashboard\DashboardShellComponent;
use Milpa\Live\Components\Dashboard\DashboardSidebarComponent;
use Milpa\Live\Components\Dashboard\DashboardTopbarComponent;
use Milpa\Live\Components\Dashboard\DataTableComponent;
use Milpa\Live\Components\Dashboard\MetricCardComponent;
use Milpa\Live\Components\Form\CheckboxComponent;
use Milpa\Live\Components\Form\InputComponent;
use Milpa\Live\Components\Form\SelectComponent;
use Milpa\Live\Components\Form\TextareaComponent;
use PHPUnit\Framework\TestCase;

/**
 * What a renderer reads from a declaration, its component's contract declares (greenhouse decisions/0481).
 *
 * An agent discovers a component by its contract. A prop the renderer reads and the contract does not declare
 * is a prop no agent can find — measured: autocomplete's staticItems, the form fields' remote and
 * dashboard-shell's mainId were read and undeclared. This reads each renderer's source, collects the props it
 * takes from the declaration, and fails naming any its components do not declare.
 *
 * Out of scope, by name: what the HOST sets when it paints (`endpoint`) and what the COMPILER sets
 * (`childrenHtml`, `childrenOutput`, `id`) — neither is the declarer's.
 */
final class RenderersReadOnlyWhatContractsDeclareTest extends TestCase
{
    /** @var list<string> */
    private const NOT_THE_DECLARERS = ['endpoint', 'childrenHtml', 'childrenOutput', 'id'];

    /** @return iterable<string, array{0: string, 1: list<class-string>}> */
    public static function renderers(): iterable
    {
        yield 'autocomplete' => ['AutocompleteHtmlRenderer.php', [AutocompleteComponent::class]];
        yield 'form fields' => ['FormPrimitiveHtmlRenderer.php', [InputComponent::class, TextareaComponent::class, SelectComponent::class, CheckboxComponent::class]];
        yield 'dashboard' => ['DashboardHtmlRenderer.php', [DashboardShellComponent::class, DashboardSidebarComponent::class, DashboardMainComponent::class,
            DashboardTopbarComponent::class, DashboardGridComponent::class, DashboardPanelComponent::class, DashboardPageHeaderComponent::class,
            DashboardAlertListComponent::class, DashboardActionButtonComponent::class, MetricCardComponent::class, DataTableComponent::class]];
        yield 'content' => ['ContentHtmlRenderer.php', [ContentComponent::class]];
        yield 'code block' => ['CodeBlockHtmlRenderer.php', [CodeBlockComponent::class]];
    }

    /**
     * @param list<class-string> $components
     *
     * @dataProvider renderers
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('renderers')]
    public function testEveryPropTheRendererReadsIsDeclared(string $file, array $components): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Rendering/' . $file);
        preg_match_all('~\$(?:request->)?props\[[\'"]([a-zA-Z_]+)[\'"]\]~', $source, $read);
        $declared = [];
        foreach ($components as $component) {
            $declared = [...$declared, ...array_keys($component::contract()->propsSchema)];
        }

        $undeclared = array_values(array_diff(array_unique($read[1]), $declared, self::NOT_THE_DECLARERS));
        sort($undeclared);
        self::assertSame([], $undeclared, $file . ' reads props its components do not declare');
    }
}
