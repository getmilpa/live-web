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

namespace Milpa\Live\Contracts\Rendering;

/**
 * Renders a named template file against a params array into an HTML
 * string. This is the templating adapter seam: an HTML
 * {@see ComponentRendererInterface} composes markup by rendering
 * per-component template files through here rather than concatenating
 * strings itself, so the template engine (Latte, a minimal built-in
 * engine, ...) can be swapped without touching renderer code.
 */
interface TemplateRendererInterface
{
    /**
     * Sets the base directory template paths passed to {@see render()}
     * are resolved against. Implementations MUST reject paths outside
     * this directory (e.g. via `..` traversal) when resolving a template.
     *
     * @throws \InvalidArgumentException If `$path` does not exist or is not a directory.
     */
    public function setViewPath(string $path): void;

    /**
     * Renders the named template (resolved relative to the current view
     * path) with the given params and returns the resulting HTML string.
     *
     * @param array<string, mixed> $params Values the template may reference; implementations decide their
     *                                     own escaping/interpolation rules.
     */
    public function render(string $template, array $params = []): string;
}
