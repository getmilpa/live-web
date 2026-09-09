<?php

/**
 * This file is part of Milpa Live Web — the HTTP/HTML transport layer of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/live-web
 */

declare(strict_types=1);

namespace Milpa\Live\Rendering;

use Milpa\Live\Components\BrandMarkComponent;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\Contracts\Rendering\ComponentRendererInterface;
use Milpa\Live\Support\Html;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\RenderResult;
use Milpa\Live\ValueObjects\RenderTarget;

/**
 * Draws the house's mark as inline SVG.
 *
 * Inline rather than an `<img>` because the grains have to be individually addressable: the state
 * is a wave travelling thirteen elements, and an image is one element. It is also why the mark
 * cannot simply be the wordmark file — that one identifies, this one reports.
 *
 * The renderer writes NO styling of its own. Everything the mark looks like arrives through the
 * stylesheet the component declares, which is the whole claim under test: if a consumer had to add
 * rules to make this look right, the component would not be travelling whole.
 */
final class BrandMarkHtmlRenderer implements ComponentRendererInterface
{
    /** HTML only: the mark is thirteen SVG elements, and a TUI has nowhere to put them. */
    public function supportsTarget(RenderTarget $target): bool
    {
        return $target === RenderTarget::HTML;
    }

    /**
     * Renders the mark in the state it was mounted with, addressable by `data-state`.
     */
    public function render(ComponentDefinitionInterface $component, RenderRequest $request): RenderResult
    {
        $contract = $component::contract();

        if ($contract->name !== 'brand-mark') {
            throw new \InvalidArgumentException('BrandMarkHtmlRenderer renders brand-mark, not ' . $contract->name . '.');
        }

        $state = $request->state ?? $component->mount($request->props, $request->context);
        $label = \is_string($state->meta['label'] ?? null) ? $state->meta['label'] : 'Milpa';

        $attributes = Html::attrs([
            'data-milpa-component' => 'brand-mark',
            'data-milpa-component-id' => $state->componentId,
            'data-state' => \is_string($state->data['state'] ?? null) ? $state->data['state'] : 'sown',
            'viewBox' => '0 0 60 60',
            'role' => 'img',
            'aria-label' => $label,
        ]);

        $grains = '';

        foreach (BrandMarkComponent::grains() as $grain) {
            $grains .= \sprintf(
                '<rect x="%s" y="%s" width="10" height="10" rx="2.5" style="--i:%d"/>',
                $grain['x'],
                $grain['y'],
                $grain['i'],
            );
        }

        return new RenderResult(output: '<svg ' . $attributes . '>' . $grains . '</svg>', state: $state);
    }
}
