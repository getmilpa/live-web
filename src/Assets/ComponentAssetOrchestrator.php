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

namespace Milpa\Live\Assets;

use Milpa\Live\ValueObjects\ComponentContract;

/**
 * Gathers what the components of one page declared, and emits it once.
 *
 * This is the seam a component previously did not have. Registering a component was always possible;
 * DRESSING one was not, because its stylesheet had to be patched into whichever package happened to
 * render it — so a plugin could contribute markup and behaviour but never a look, and the promise
 * that the component system was extensible by registry was false at exactly that point.
 *
 * The page hands over the contracts it is about to render — the same list
 * {@see \Milpa\Live\Contracts\Client\ClientRuntimeAdapterInterface::bootPayload()} already receives —
 * and gets back one `<style>` and one `<script>`. Emission is keyed by `name@version`, so a component
 * used fifty times costs its stylesheet once: the alternative, per-instance styling, was measured at
 * ~954 bytes an instance in a system that shipped it.
 *
 * Nothing here reaches the filesystem for anything a contract did not name, and nothing is written.
 */
final class ComponentAssetOrchestrator
{
    public function __construct(
        private readonly CssScoper $scoper = new CssScoper(),
    ) {
    }

    /**
     * Gathers what this page's components declared: read, scoped, deduplicated, ready to embed.
     *
     * @param array<int, ComponentContract> $contracts Every contract the page may render.
     */
    public function collect(array $contracts): PageAssets
    {
        $styles = [];
        $scripts = [];
        $emitted = [];
        $unreadable = [];
        $seen = [];

        foreach ($contracts as $contract) {
            $key = $contract->name . '@' . $contract->contractVersion;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $presentation = $contract->presentation();

            if (!$presentation->declaresAnything()) {
                continue;
            }

            $contributed = false;

            if ($presentation->styles !== null) {
                $css = $this->read($presentation->styles);

                if ($css === null) {
                    $unreadable[$key] = $presentation->styles;
                } else {
                    $styles[] = $this->scoper->scope($css, $this->scopeFor($contract));
                    $contributed = true;
                }
            }

            if ($presentation->script !== null) {
                $js = $this->read($presentation->script);

                if ($js === null) {
                    $unreadable[$key] = $presentation->script;
                } else {
                    $scripts[] = $js;
                    $contributed = true;
                }
            }

            if ($contributed) {
                $emitted[] = $key;
            }
        }

        return new PageAssets(
            styles: implode("\n", $styles),
            scripts: $scripts,
            emitted: $emitted,
            unreadable: $unreadable,
        );
    }

    /**
     * The selector every rule of this component is confined to.
     *
     * It is the marker the Alpine adapter already puts on every component root, so scoping costs no
     * renderer change and cannot drift from what is actually in the DOM.
     */
    public function scopeFor(ComponentContract $contract): string
    {
        return '[data-milpa-component="' . str_replace(['\\', '"'], ['\\\\', '\\"'], $contract->name) . '"]';
    }

    private function read(string $path): ?string
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents === false ? null : $contents;
    }
}
