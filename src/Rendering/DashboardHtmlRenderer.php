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

namespace Milpa\Live\Rendering;

use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Live\Contracts\Client\ClientRuntimeAdapterInterface;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\Contracts\Rendering\ComponentRendererInterface;
use Milpa\Live\Contracts\Rendering\TemplateRendererInterface;
use Milpa\Live\Contracts\Transport\StateTransferCodecInterface;
use Milpa\Live\Events\LiveEventEmitter;
use Milpa\Live\Rendering\ViewModel\DashboardViewModelFields;
use Milpa\Live\Support\Html;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\RenderResult;
use Milpa\Live\ValueObjects\RenderTarget;

/**
 * HTML {@see ComponentRendererInterface} for the dashboard-primitive
 * contract family — shell, sidebar, main, topbar, grid, panel, page header,
 * action button, alert list, metric card, and data table. One renderer
 * fans out to a private per-primitive method (`shell()`, `sidebar()`, …)
 * that builds the Latte params for that primitive's template; the public
 * surface stays the single {@see render()} entrypoint every primitive
 * shares via its contract name.
 */
final readonly class DashboardHtmlRenderer implements ComponentRendererInterface
{
    private TemplateRendererInterface $templates;

    /**
     * @var array<int, string>
     */
    private const SUPPORTED = [
        'dashboard-shell',
        'dashboard-sidebar',
        'dashboard-main',
        'dashboard-topbar',
        'dashboard-grid',
        'dashboard-panel',
        'dashboard-page-header',
        'dashboard-action-button',
        'dashboard-alert-list',
        'metric-card',
        'data-table',
    ];

    public function __construct(
        private ClientRuntimeAdapterInterface $client,
        private StateTransferCodecInterface $codec,
        ?TemplateRendererInterface $templates = null,
        private ?MilpaEventDispatcherInterface $dispatcher = null,
    ) {
        $this->templates = $templates ?? new LatteTemplateRenderer();
    }

    /**
     * True only for {@see RenderTarget::HTML} — this renderer produces
     * server-rendered markup, not another target format.
     */
    public function supportsTarget(RenderTarget $target): bool
    {
        return $target === RenderTarget::HTML;
    }

    /**
     * Renders one dashboard-primitive component to HTML, dispatching on the
     * component's contract name to the matching private builder (`shell()`,
     * `sidebar()`, `main()`, …). Throws `InvalidArgumentException` if the
     * contract name is outside {@see self::SUPPORTED}.
     */
    public function render(ComponentDefinitionInterface $component, RenderRequest $request): RenderResult
    {
        $contract = $component::contract();
        if (!in_array($contract->name, self::SUPPORTED, true)) {
            throw new \InvalidArgumentException('DashboardHtmlRenderer only renders dashboard primitives.');
        }

        return LiveEventEmitter::withRendering(
            $this->dispatcher,
            $contract->name,
            $request,
            function () use ($component, $request, $contract): RenderResult {
                $state = $request->state ?? $component->mount($request->props, $request->context);
                $stateEnvelope = $this->codec->encodeState($state);
                $html = match ($contract->name) {
                    'dashboard-shell' => $this->shell($state->componentId, $stateEnvelope, $state->meta, $request->props),
                    'dashboard-sidebar' => $this->sidebar($state->componentId, $stateEnvelope, $state->meta, $request->props),
                    'dashboard-main' => $this->main($state->componentId, $stateEnvelope, $state->meta, $request->props),
                    'dashboard-topbar' => $this->topbar($state->componentId, $stateEnvelope, $state->meta, $request->props),
                    'dashboard-grid' => $this->grid($state->componentId, $stateEnvelope, $state->meta, $request->props),
                    'dashboard-panel' => $this->panel($state->componentId, $stateEnvelope, $state->meta, $request->props),
                    'dashboard-page-header' => $this->pageHeader($state->componentId, $stateEnvelope, $state->meta, $request->props),
                    'dashboard-action-button' => $this->actionButton($state->componentId, $stateEnvelope, $state->meta, $request->props),
                    'dashboard-alert-list' => $this->alertList($state->componentId, $stateEnvelope, $state->meta, $request->props),
                    'metric-card' => $this->metric($state->componentId, $stateEnvelope, $state->data, $state->meta, $request->props),
                    'data-table' => $this->table($state->componentId, $stateEnvelope, $state->data, $state->meta, $request->props),
                };

                return new RenderResult(
                    output: $html,
                    state: $state,
                    assets: $this->client->assets(),
                    format: RenderTarget::HTML,
                );
            },
        );
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $props
     */
    private function shell(string $id, string $stateEnvelope, array $meta, array $props): string
    {
        $mainId = (string) ($props['mainId'] ?? $id . '-main');

        return $this->templates->render('components/dashboard-shell.latte', [
            'componentId' => $id,
            'stateEnvelope' => $stateEnvelope,
            'rootAttrs' => Html::attrs([
                'class' => 'mui-shell',
                'data-milpa-component-id' => $id,
                'data-density' => (string) ($meta['density'] ?? 'comfortable'),
                'x-data' => '{ navOpen: false }',
                ':class' => "navOpen ? 'mui-shell--nav-open' : ''",
            ]),
            'mainId' => $mainId,
            'childrenHtml' => $this->children($props),
        ]);
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $props
     */
    private function sidebar(string $id, string $stateEnvelope, array $meta, array $props): string
    {
        $items = DashboardViewModelFields::list($meta, $props, 'items');
        $active = DashboardViewModelFields::string($meta, $props, 'active');
        $html = [];
        // DashboardViewModelFields::list() already filters non-array entries
        // (see its own @return array<int, array<string, mixed>>, enforced via
        // array_filter(..., 'is_array') internally) -- every $item here is
        // guaranteed to already be an array, so no redundant guard is needed.
        foreach ($items as $item) {
            $html[] = sprintf(
                '<a %s><span class="mui-sidebar__item-icon" aria-hidden="true">%s</span><span class="mui-sidebar__item-label">%s</span></a>',
                Html::attrs([
                    'class' => 'mui-sidebar__item',
                    'href' => (string) ($item['href'] ?? '#'),
                    'aria-current' => (string) ($item['key'] ?? '') === $active ? 'page' : null,
                ]),
                Html::escape((string) ($item['icon'] ?? '')),
                Html::escape((string) ($item['label'] ?? $item['key'] ?? 'Item')),
            );
        }

        return $this->templates->render('components/dashboard-sidebar.latte', [
            'componentId' => $id,
            'stateEnvelope' => $stateEnvelope,
            'rootAttrs' => Html::attrs([
                'class' => 'mui-sidebar',
                'id' => (string) ($meta['id'] ?? $id),
                'aria-label' => 'principal',
                'data-milpa-component-id' => $id,
            ]),
            'brand' => DashboardViewModelFields::string($meta, $props, 'brand', 'Milpa'),
            'sectionId' => $id . '-section',
            'itemsHtml' => implode("\n", $html),
            'childrenHtml' => $this->children($props),
        ]);
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $props
     */
    private function main(string $id, string $stateEnvelope, array $meta, array $props): string
    {
        return $this->templates->render('components/dashboard-main.latte', [
            'componentId' => $id,
            'stateEnvelope' => $stateEnvelope,
            'rootAttrs' => Html::attrs([
                'class' => 'mui-shell__main mui-shell__main--wide',
                'id' => (string) ($meta['id'] ?? $id),
                'data-milpa-component-id' => $id,
            ]),
            'childrenHtml' => $this->children($props),
        ]);
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $props
     */
    private function topbar(string $id, string $stateEnvelope, array $meta, array $props): string
    {
        $eyebrow = DashboardViewModelFields::string($meta, $props, 'eyebrow');
        $title = DashboardViewModelFields::string($meta, $props, 'title');
        $controls = (string) ($props['controls'] ?? $meta['controls'] ?? 'ops-sidebar');
        $placeholder = (string) ($props['searchPlaceholder'] ?? $meta['searchPlaceholder'] ?? 'Buscar');

        return $this->templates->render('components/dashboard-topbar.latte', [
            'componentId' => $id,
            'stateEnvelope' => $stateEnvelope,
            'rootAttrs' => Html::attrs(['class' => 'mui-topbar', 'data-milpa-component-id' => $id]),
            'toggleAttrs' => Html::attrs([
                'class' => 'mui-btn mui-btn--ghost mui-btn--icon mui-topbar__nav-toggle',
                'type' => 'button',
                'aria-label' => 'Abrir navegacion',
                'aria-controls' => $controls,
                ':aria-expanded' => "navOpen ? 'true' : 'false'",
                '@click' => 'navOpen = !navOpen',
            ]),
            'eyebrowHtml' => $eyebrow !== '' ? '<span class="mui-badge mui-badge--accent">' . Html::escape($eyebrow) . '</span>' : '',
            'titleHtml' => $title !== '' ? '<span class="mui-kbd">' . Html::escape($title) . '</span>' : '',
            'searchId' => $id . '-search',
            'searchAttrs' => Html::attrs([
                'class' => 'mui-input mui-input--sm',
                'id' => $id . '-search',
                'type' => 'search',
                'aria-label' => 'Buscar',
                'placeholder' => $placeholder,
            ]),
            'childrenHtml' => $this->children($props),
        ]);
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $props
     */
    private function grid(string $id, string $stateEnvelope, array $meta, array $props): string
    {
        return $this->templates->render('components/dashboard-grid.latte', [
            'componentId' => $id,
            'stateEnvelope' => $stateEnvelope,
            'rootAttrs' => Html::attrs([
                'class' => 'mcl-dashboard-grid',
                'style' => '--mcl-grid-columns: ' . max(1, (int) ($meta['columns'] ?? 3)),
                'data-milpa-component-id' => $id,
            ]),
            'childrenHtml' => $this->children($props),
        ]);
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $props
     */
    private function panel(string $id, string $stateEnvelope, array $meta, array $props): string
    {
        $title = DashboardViewModelFields::string($meta, $props, 'title');
        $description = DashboardViewModelFields::string($meta, $props, 'description');
        $span = max(1, (int) ($meta['span'] ?? 1));
        $header = [];
        if ($title !== '' || $description !== '') {
            $header[] = '<header class="mui-card__header">';
            $header[] = '<div>';
            if ($title !== '') {
                $header[] = '<h2 class="mui-card__title">' . Html::escape($title) . '</h2>';
            }
            if ($description !== '') {
                $header[] = '<p class="mcl-card-description">' . Html::escape($description) . '</p>';
            }
            $header[] = '</div>';
            $header[] = '</header>';
        }

        return $this->templates->render('components/dashboard-panel.latte', [
            'componentId' => $id,
            'stateEnvelope' => $stateEnvelope,
            'rootAttrs' => Html::attrs([
                'class' => 'mui-card mui-card--compact mcl-panel',
                'style' => '--mcl-panel-span: ' . $span,
                'data-tone' => (string) ($meta['tone'] ?? 'default'),
                'data-milpa-component-id' => $id,
            ]),
            'headerHtml' => implode("\n", $header),
            'childrenHtml' => $this->children($props),
        ]);
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $props
     */
    private function pageHeader(string $id, string $stateEnvelope, array $meta, array $props): string
    {
        $eyebrow = DashboardViewModelFields::string($meta, $props, 'eyebrow');
        $description = DashboardViewModelFields::string($meta, $props, 'description');
        $title = DashboardViewModelFields::string($meta, $props, 'title');

        return $this->templates->render('components/dashboard-page-header.latte', [
            'componentId' => $id,
            'stateEnvelope' => $stateEnvelope,
            'rootAttrs' => Html::attrs([
                'class' => 'mui-page-header',
                'data-milpa-component-id' => $id,
            ]),
            'eyebrowHtml' => $eyebrow !== '' ? '<p class="mui-page-header__eyebrow">' . Html::escape($eyebrow) . '</p>' : '',
            'title' => $title,
            'descriptionHtml' => $description !== '' ? '<p class="mui-page-header__description">' . Html::escape($description) . '</p>' : '',
        ]);
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $props
     */
    private function actionButton(string $id, string $stateEnvelope, array $meta, array $props): string
    {
        $variant = $this->buttonToken(DashboardViewModelFields::string($meta, $props, 'variant', 'ghost'), ['ghost', 'primary'], 'ghost');
        $size = $this->buttonToken((string) ($meta['size'] ?? 'sm'), ['sm'], 'sm');
        $type = $this->buttonToken((string) ($meta['type'] ?? 'button'), ['button', 'submit', 'reset'], 'button');

        return $this->templates->render('components/dashboard-action-button.latte', [
            'componentId' => $id,
            'stateEnvelope' => $stateEnvelope,
            'buttonAttrs' => Html::attrs([
                'class' => 'mui-btn mui-btn--' . $variant . ' mui-btn--' . $size,
                'type' => $type,
                'data-milpa-component-id' => $id,
            ]),
            'label' => DashboardViewModelFields::string($meta, $props, 'label'),
        ]);
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $props
     */
    private function alertList(string $id, string $stateEnvelope, array $meta, array $props): string
    {
        return $this->templates->render('components/dashboard-alert-list.latte', [
            'componentId' => $id,
            'stateEnvelope' => $stateEnvelope,
            'items' => DashboardViewModelFields::list($meta, $props, 'items'),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $props
     */
    private function metric(string $id, string $stateEnvelope, array $data, array $meta, array $props): string
    {
        $trend = (string) ($data['trend'] ?? 'neutral');
        $deltaClass = match ($trend) {
            'up' => 'mui-stat__delta mui-stat__delta--up mui-stat__delta--positive',
            'down' => 'mui-stat__delta mui-stat__delta--down mui-stat__delta--negative',
            default => 'mui-stat__delta',
        };

        return $this->templates->render('components/metric-card.latte', [
            'componentId' => $id,
            'stateEnvelope' => $stateEnvelope,
            'rootAttrs' => Html::attrs([
                'class' => 'mui-card mui-card--compact',
                'data-milpa-component-id' => $id,
            ]),
            'title' => DashboardViewModelFields::string($meta, $props, 'title', 'Metric'),
            'value' => (string) ($data['value'] ?? ''),
            'delta' => (string) ($data['delta'] ?? ''),
            'deltaClass' => $deltaClass,
            'caption' => DashboardViewModelFields::string($meta, $props, 'caption'),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $props
     */
    private function table(string $id, string $stateEnvelope, array $data, array $meta, array $props): string
    {
        $columns = is_array($meta['columns'] ?? null) ? $meta['columns'] : [];
        $rows = is_array($meta['rows'] ?? null) ? $meta['rows'] : [];
        $name = (string) ($meta['name'] ?? $id);
        $selectable = (bool) ($meta['selectable'] ?? false);
        $options = [
            'componentId' => $id,
            'name' => $name,
            'persistKey' => (string) ($meta['persistKey'] ?? ''),
            'storage' => (string) ($meta['storage'] ?? 'local'),
            'initialState' => $data,
        ];

        $caption = (string) ($meta['caption'] ?? $name);
        $captionId = $id . '-caption';
        $header = [];
        if ($selectable) {
            $header[] = '<th class="mui-table__check"><span class="mui-sr-only">Seleccion</span></th>';
        }
        foreach ($columns as $column) {
            if (!is_array($column)) {
                continue;
            }
            $key = (string) ($column['key'] ?? '');
            $encodedKey = json_encode($key, JSON_THROW_ON_ERROR);
            $escapedKey = Html::escape($encodedKey);
            $alignClass = (string) ($column['align'] ?? 'left') === 'right' ? 'mui-table__num' : '';
            $header[] = sprintf(
                '<th %s><button type="button" class="mui-table__sort" @click="sort(%s)">%s</button></th>',
                Html::attrs([
                    'class' => $alignClass !== '' ? $alignClass : null,
                    ':aria-sort' => 'sortState(' . $encodedKey . ')',
                ]),
                $escapedKey,
                Html::escape((string) ($column['label'] ?? $key)),
            );
        }

        $body = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $rowId = (string) ($row['id'] ?? $index);
            $encodedRowId = Html::escape(json_encode($rowId, JSON_THROW_ON_ERROR));
            $rowLabel = (string) ($row['label'] ?? $row['account'] ?? $row['name'] ?? $rowId);
            $body[] = '<tr :aria-selected="isSelected(' . $encodedRowId . ') ? \'true\' : \'false\'">';
            if ($selectable) {
                $body[] = '<td class="mui-table__check"><input class="mui-checkbox" type="checkbox" aria-label="Seleccionar '
                    . Html::escape($rowLabel)
                    . '" :checked="isSelected('
                    . $encodedRowId
                    . ')" @change="toggleRow('
                    . $encodedRowId
                    . ')"></td>';
            }
            foreach ($columns as $column) {
                if (!is_array($column)) {
                    continue;
                }
                $key = (string) ($column['key'] ?? '');
                $classes = [];
                if ((string) ($column['align'] ?? 'left') === 'right') {
                    $classes[] = 'mui-table__num';
                }
                if ($key === (string) ($columns[0]['key'] ?? '')) {
                    $classes[] = 'mui-table__lead';
                }
                $body[] = sprintf(
                    '<td%s>%s</td>',
                    $classes !== [] ? ' class="' . Html::escape(implode(' ', $classes)) . '"' : '',
                    Html::escape((string) ($row[$key] ?? '')),
                );
            }
            $body[] = '</tr>';
        }

        return $this->templates->render('components/data-table.latte', [
            'componentId' => $id,
            'stateEnvelope' => $stateEnvelope,
            'rootAttrs' => Html::attrs([
                'class' => 'mui-table-wrap',
                'role' => 'region',
                'aria-labelledby' => $captionId,
                'tabindex' => '0',
                'data-milpa-component-id' => $id,
                'x-data' => 'milpaDataTable(' . json_encode($options, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ')',
                'x-init' => 'init()',
            ]),
            'name' => $name,
            'captionId' => $captionId,
            'caption' => $caption,
            'headerHtml' => implode("\n", $header),
            'rowsHtml' => implode("\n", $body),
        ]);
    }

    /**
     * @param array<string, mixed> $props
     */
    private function children(array $props): string
    {
        return trim((string) ($props['childrenHtml'] ?? ''));
    }

    /**
     * @param array<int, string> $allowed
     */
    private function buttonToken(string $value, array $allowed, string $fallback): string
    {
        return in_array($value, $allowed, true) ? $value : $fallback;
    }
}
