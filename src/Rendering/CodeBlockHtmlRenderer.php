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

use Milpa\Live\Components\CodeBlockComponent;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\Contracts\Rendering\ComponentRendererInterface;
use Milpa\Live\Support\Html;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\RenderResult;
use Milpa\Live\ValueObjects\RenderTarget;

/**
 * Paints {@see CodeBlockComponent} as a terminal: a chrome strip, prompted lines, and a copy button.
 *
 * ── WHY IT LOOKS LIKE A TERMINAL AND NOT LIKE A QUOTE ───────────────────────────────────────────────
 *
 * «El área de code se debe parecer MÁS a una terminal […] para el humano tiene que quedar claro
 * visualmente que hablamos de código» (Rod). A reader scanning a page decides what a block IS before
 * reading a character of it, and a tinted rectangle of prose-width text says «quotation». The three
 * things that say «terminal» instead are the chrome strip with a name, the prompt glyph before each
 * line, and a monospaced line that does not wrap — all of them structure, none of them a new colour.
 *
 * Every value comes from the design tokens, including the darkest ground the palette has. A terminal
 * look invented per page is how the welcome page ended up with an invisible command
 * (greenhouse decisions/0299).
 *
 * ── THE COPY BUTTON IS IN THE MARKUP, NOT ADDED BY SCRIPT ───────────────────────────────────────────
 *
 * It is a real `<button>` the server printed, revealed by CSS on hover and always present for the
 * keyboard. A button a script has to inject is a button that does not exist until the script runs, and
 * a page whose script failed then shows chrome with nothing behind it — the shape this house has
 * measured more than once. The script only teaches the existing button what to do
 * (greenhouse decisions/0272).
 */
final class CodeBlockHtmlRenderer implements ComponentRendererInterface
{
    /** HTML only: a copy button and a hover affordance have nowhere to live in a TUI frame. */
    public function supportsTarget(RenderTarget $target): bool
    {
        return $target === RenderTarget::HTML;
    }

    /** The block: chrome, one prompted line per command, and the button that takes them. */
    public function render(ComponentDefinitionInterface $component, RenderRequest $request): RenderResult
    {
        $contract = $component::contract();
        if ($contract->name !== 'code-block') {
            throw new \InvalidArgumentException('CodeBlockHtmlRenderer renders code-block, not ' . $contract->name . '.');
        }

        $state = $request->state ?? $component->mount($request->props, $request->context);
        $command = \is_string($request->props['command'] ?? null) ? $request->props['command'] : '';
        $lines = CodeBlockComponent::lines($command);
        $label = \is_string($state->meta['label'] ?? null) ? $state->meta['label'] : 'terminal';
        $prompt = \is_string($state->meta['prompt'] ?? null) ? $state->meta['prompt'] : '$';
        $copyable = ($state->meta['copy'] ?? true) !== false && $lines !== [];

        $rows = '';
        foreach ($lines as $line) {
            $rows .= '<span class="line">'
                . ($prompt !== '' ? '<span class="prompt" aria-hidden="true">' . Html::escape($prompt) . '</span>' : '')
                . '<span class="text">' . Html::escape($line) . '</span></span>';
        }

        // The clipboard payload is the COMMANDS, never the prompt. A `$` pasted into a shell is the one
        // error this affordance exists to prevent, and it is the error every hand-rolled copy button
        // makes when it reads `textContent` off the whole block.
        $payload = implode("\n", $lines);

        $button = $copyable
            ? '<button type="button" class="copy" data-milpa-copy="' . Html::escape($payload) . '"'
                . ' aria-label="' . Html::escape('Copy ' . $label) . '">'
                . '<span class="copy-icon" aria-hidden="true">⧉</span>'
                . '<span class="copy-said" role="status"></span></button>'
            : '';

        $html = '<div ' . Html::attrs([
            // NO CLASS ON THE ROOT. The stylesheet styles it through `:host`, which the scoper turns
            // into this very attribute selector — a class here would be a second name for one element,
            // and the version of this file that had one styled nothing at all.
            'data-milpa-component' => 'code-block',
            'data-milpa-component-id' => $state->componentId,
        ]) . '>'
            . '<div class="chrome"><span class="label">' . Html::escape($label) . '</span>' . $button . '</div>'
            . '<pre class="body"><code>' . $rows . '</code></pre>'
            . '</div>';

        return new RenderResult(output: $html, state: $state);
    }
}
