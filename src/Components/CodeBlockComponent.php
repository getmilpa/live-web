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

namespace Milpa\Live\Components;

use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\ComponentContract;
use Milpa\Live\ValueObjects\ComponentPresentation;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\InteractionResult;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * A COMMAND, SHOWN AS A TERMINAL SHOWS IT, WITH A WAY TO TAKE IT — the primitive the catalogue lacked.
 *
 * Measured before writing it: `components:catalogue` listed seventeen components and not one of them
 * was for code. So every page that had to show a command wrote its own `<pre>`, and the framework's own
 * welcome page wrote six hand-picked hex values in the process — two of which rendered at 1.07:1 and
 * 1.02:1, invisible (greenhouse decisions/0298, 0299).
 *
 * Rod's words on seeing it: «esa página TAMBIÉN tendría que estar hecha de Milpa Components», and
 * «el área de code se debe parecer MÁS a una terminal […] para el humano tiene que quedar claro
 * visualmente que hablamos de código». Both are the same instruction: the look belongs to a primitive
 * the family owns, not to whichever page needed it first.
 *
 * ── WHAT IT IS AND WHAT IT REFUSES ──────────────────────────────────────────────────────────────────
 *
 * It shows ONE command per line, with a prompt glyph, and offers to copy it. It does no syntax
 * highlighting: a shell line is not a language, and a highlighter is a dependency, a grammar and a
 * palette decision this primitive does not need to be useful.
 *
 * `actions` is empty and that is the design. Copying is a LOCAL act — the clipboard is in the browser,
 * nothing is persisted and no principal is involved — so it needs no round trip and declares no server
 * action. A component that posted to copy would be asking a governed runtime for permission to use the
 * clipboard.
 */
final class CodeBlockComponent implements ComponentDefinitionInterface
{
    /**
     * The contract: the command, what it is called, and whether it can be taken.
     *
     * `prompt` is a prop rather than a constant because a block can be showing a shell line, a SQL
     * statement or a URL, and `$` on a URL is a lie about what the reader is looking at. Its default is
     * the shell's, because that is what a house shows most.
     */
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: 'code-block',
            contractVersion: '1',
            summary: 'A command shown the way a terminal shows it, with a copy affordance that needs no server.',
            propsSchema: [
                'command' => ['type' => 'string', 'required' => true, 'description' => 'The command itself; newlines are separate lines, each with its own prompt'],
                'label' => ['type' => 'string', 'required' => false, 'description' => 'What this block is, shown in its chrome — «terminal» when absent'],
                'prompt' => ['type' => 'string', 'default' => '$', 'description' => 'The glyph before each line; empty for content that is not a shell command'],
                'copy' => ['type' => 'boolean', 'default' => true, 'description' => 'Whether to offer taking the command; false for output nobody would paste'],
            ],
            stateSchema: [
                'lines' => ['type' => 'integer'],
            ],
            actions: [],
            presentation: new ComponentPresentation(
                styles: \dirname(__DIR__, 2) . '/resources/components/code-block.css',
                script: \dirname(__DIR__, 2) . '/resources/components/code-block.js',
            ),
        );
    }

    /**
     * The lines are the state, so a re-render says what it is showing.
     *
     * A command that arrives empty mounts with zero lines rather than one blank prompt: an empty
     * terminal row reads as «run nothing», which is not what an absent prop means.
     */
    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        return new StateSnapshot(
            $context->componentId,
            'code-block',
            '1',
            ['lines' => \count(self::lines($props['command'] ?? ''))],
            [
                'label' => \is_string($props['label'] ?? null) && $props['label'] !== '' ? $props['label'] : 'terminal',
                'prompt' => \is_string($props['prompt'] ?? null) ? $props['prompt'] : '$',
                'copy' => ($props['copy'] ?? true) !== false,
            ],
        );
    }

    /** Inert: copying happens in the browser, so there is nothing here to handle. */
    public function handle(InteractionRequest $request): InteractionResult
    {
        return new InteractionResult(state: $request->state);
    }

    /**
     * The command split into the lines a terminal would show, with blank ones dropped.
     *
     * @return list<string>
     */
    public static function lines(mixed $command): array
    {
        if (!\is_string($command)) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', preg_split('/\R/', $command) ?: []),
            static fn (string $line): bool => $line !== '',
        ));
    }
}
