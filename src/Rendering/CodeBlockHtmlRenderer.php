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
        // NO DEFAULT LABEL. An absent label means NO CHROME STRIP, and the strip is what a block gets
        // when it has something to say about itself. Measured on the framework's welcome page: eight
        // blocks all labelled the identical word «terminal», 43px of a 91px block — 47% — and 344px of
        // a 2263px scroll spent on a word that teaches nothing after the first one. What says «terminal»
        // is the ground, the mono face and the prompt; a label is for a block that is something ELSE
        // (an output, a file, a response), which is exactly when it earns its 47%.
        $label = \is_string($state->meta['label'] ?? null) ? $state->meta['label'] : '';
        $prompt = \is_string($state->meta['prompt'] ?? null) ? $state->meta['prompt'] : '$';
        $copyable = ($state->meta['copy'] ?? true) !== false && $lines !== [];
        $compact = ($state->meta['compact'] ?? false) === true;

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

        // 🚨 THE ACCESSIBLE NAME IS THE COMMAND, NOT THE LABEL. With the label it was «Copy terminal» on
        // all eight buttons of one page: a screen reader user tabbing through them heard the same words
        // eight times and could not tell which command any button would take. The command is the thing
        // the button actually copies, so it is the honest name — and the first line alone, because a
        // name is read aloud in one breath.
        $names = $lines === [] ? '' : $lines[0] . (\count($lines) > 1 ? ' and ' . (\count($lines) - 1) . ' more' : '');

        $button = $copyable
            ? '<button type="button" class="copy" data-milpa-copy="' . Html::escape($payload) . '"'
                . ' aria-label="' . Html::escape('Copy ' . $names) . '">'
                . '<span class="copy-icon" aria-hidden="true">⧉</span>'
                . '<span class="copy-said" role="status"></span></button>'
            : '';

        $attributes = [
            // NO CLASS ON THE ROOT. The stylesheet styles it through `:host`, which the scoper turns
            // into this very attribute selector — a class here would be a second name for one element,
            // and the version of this file that had one styled nothing at all.
            'data-milpa-component' => 'code-block',
            'data-milpa-component-id' => $state->componentId,
        ];
        if ($compact) {
            $attributes['data-compact'] = 'true';
        }

        // COMPACT IS ONE ROW: the command and its button side by side, no strip above them. A chrome
        // strip on a chip-sized block is the 47% overhead that made the strip opt-in in the first place.
        if ($compact) {
            return new RenderResult(
                output: '<div ' . Html::attrs($attributes) . '>'
                    . '<pre class="body"><code>' . $rows . '</code></pre>' . $button . '</div>',
                state: $state,
            );
        }

        $html = '<div ' . Html::attrs($attributes) . '>'
            // The strip exists when there is a label OR a button to hold; with neither it is 43px of
            // nothing. When only the button is there it floats at the block's top right, which is where
            // it already sat.
            . ($label !== '' || $button !== ''
                ? '<div class="chrome"' . ($label === '' ? ' data-bare="true"' : '') . '>'
                    . ($label !== '' ? '<span class="label">' . Html::escape($label) . '</span>' : '')
                    . $button . '</div>'
                : '')
            . '<pre class="body"><code>' . $rows . '</code></pre>'
            . '</div>';

        return new RenderResult(output: $html, state: $state);
    }
}
