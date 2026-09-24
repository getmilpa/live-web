<?php

/**
 * This file is part of milpa/live-web.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/live-web
 */

declare(strict_types=1);

namespace Milpa\Live\Rendering;

use Milpa\Live\Assets\ComponentMessages;
use Milpa\Live\Components\ContentComponent;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\Contracts\Rendering\ComponentRendererInterface;
use Milpa\Live\Support\Html;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\RenderResult;
use Milpa\Live\ValueObjects\RenderTarget;

/**
 * Paints {@see ContentComponent}: one `<article>` per entry, read through the roles the declaration named
 * (greenhouse decisions/0464).
 *
 * The title is a heading, the lead a lead paragraph, the body paragraphs, the metadata a list of field
 * and value. Every value is escaped — the body is text, and a title a stranger typed is still only text.
 * The heading level follows the page: with a `heading` the entries sit one level below it.
 *
 * Its only words are the empty state, read from the component's catalogue in the page's language: an
 * entry list with nothing in it says so, instead of painting a blank region that reads as broken.
 */
final class ContentHtmlRenderer implements ComponentRendererInterface
{
    public function __construct(
        private readonly string $locale = ComponentMessages::DEFAULT_LOCALE,
        private readonly ComponentMessages $words = new ComponentMessages(),
    ) {
    }

    /** HTML only: articles and headings are this renderer's whole vocabulary. */
    public function supportsTarget(RenderTarget $target): bool
    {
        return $target === RenderTarget::HTML;
    }

    /** The entries as articles, or the empty state — refused by name when the roles do not fit the rows. */
    public function render(ComponentDefinitionInterface $component, RenderRequest $request): RenderResult
    {
        $contract = $component::contract();
        if ($contract->name !== 'content') {
            throw new \InvalidArgumentException('ContentHtmlRenderer renders content, not ' . $contract->name . '.');
        }

        $state = $request->state ?? $component->mount($request->props, $request->context);
        $roles = ContentComponent::roles($request->props['roles'] ?? ($state->meta['roles'] ?? null));
        $rows = ContentComponent::rows($request->props['rows'] ?? [], $roles);
        $heading = \is_string($request->props['heading'] ?? null) ? $request->props['heading'] : '';
        $t = $this->words->for($contract, $this->locale);

        $level = $heading === '' ? 2 : 3;
        $html = $heading === '' ? '' : '<h2 class="heading">' . Html::escape($heading) . '</h2>';

        if ($rows === []) {
            $html .= '<p class="empty">' . Html::escape($t['empty'] ?? '') . '</p>';
        }
        foreach ($rows as $row) {
            $html .= '<article class="entry">'
                . sprintf('<h%d class="title">%s</h%1$d>', $level, Html::escape(self::text($row[$roles['title']])));
            if ($roles['lead'] !== null && self::text($row[$roles['lead']]) !== '') {
                $html .= '<p class="lead">' . Html::escape(self::text($row[$roles['lead']])) . '</p>';
            }
            $paragraphs = ContentComponent::paragraphs($row[$roles['body']]);
            if ($paragraphs !== []) {
                $html .= '<div class="body">';
                foreach ($paragraphs as $lines) {
                    $html .= '<p>' . implode('<br>', array_map(Html::escape(...), $lines)) . '</p>';
                }
                $html .= '</div>';
            }
            if ($roles['meta'] !== []) {
                $html .= '<dl class="meta">';
                foreach ($roles['meta'] as $field) {
                    $html .= '<div><dt>' . Html::escape(ucfirst(str_replace('_', ' ', $field))) . '</dt>'
                        . '<dd>' . Html::escape(self::text($row[$field])) . '</dd></div>';
                }
                $html .= '</dl>';
            }
            $html .= '</article>';
        }

        return new RenderResult(
            output: '<section ' . Html::attrs([
                // NO CLASS ON THE ROOT: the stylesheet reaches it through `:host`, which the scoper turns
                // into this very attribute selector (see code-block.css for what a second name cost).
                'data-milpa-component' => 'content',
                'data-milpa-component-id' => $state->componentId,
            ]) . '>' . $html . '</section>',
            state: $state,
            assets: [],
            format: RenderTarget::HTML,
        );
    }

    /** A scalar field as text; anything else paints as nothing rather than as «Array». */
    private static function text(mixed $value): string
    {
        return match (true) {
            \is_bool($value) => $value ? 'true' : 'false',
            \is_scalar($value) => (string) $value,
            default => '',
        };
    }
}
