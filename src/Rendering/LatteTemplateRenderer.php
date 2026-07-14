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

use Milpa\Live\Contracts\Rendering\TemplateRendererInterface;
use Milpa\Live\Support\Html;

/**
 * Dependency-free {@see TemplateRendererInterface}: a small self-contained
 * subset of Latte syntax (`{layout}`, `{block}`/`{/block}`, `{include}`,
 * `{foreach}`, `{$var}` with auto-escaping and an explicit `|noescape`
 * opt-out) implemented over `preg_replace_callback()`, not the real Latte
 * engine — enough to render this package's shipped templates without
 * pulling in a Composer dependency on `latte/latte`. Every resolved template
 * path is checked against {@see $viewPath} to prevent path traversal outside
 * the configured view root.
 */
final class LatteTemplateRenderer implements TemplateRendererInterface
{
    private string $viewPath;

    public function __construct(?string $viewPath = null)
    {
        $this->setViewPath($viewPath ?? dirname(__DIR__, 2) . '/templates');
    }

    /**
     * Sets the root directory templates are resolved (and traversal-checked)
     * against. Throws `InvalidArgumentException` if `$path` does not exist.
     */
    public function setViewPath(string $path): void
    {
        $real = realpath($path);
        if ($real === false || !is_dir($real)) {
            throw new \InvalidArgumentException("Template view path does not exist: {$path}");
        }

        $this->viewPath = rtrim($real, '/');
    }

    /**
     * Renders `$template` (a path relative to {@see $viewPath}, or a
     * `{layout}`-linked template it references) with `$params` available as
     * `{$key}` / `{$key.nested}` placeholders.
     */
    public function render(string $template, array $params = []): string
    {
        return $this->renderFile($this->resolve($template, $this->viewPath), $params);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function renderFile(string $path, array $params): string
    {
        $source = file_get_contents($path);
        if ($source === false) {
            throw new \RuntimeException("Template is not readable: {$path}");
        }

        [$layout, $source] = $this->extractLayout($source);
        $blocks = $this->extractBlocks($source);

        if ($layout !== null) {
            $body = $blocks['body'] ?? $source;
            $params['body'] = $this->renderSource($body, $params, dirname($path));

            return $this->renderFile($this->resolve($layout, dirname($path)), $params);
        }

        return $this->renderSource($this->stripBlockTags($source), $params, dirname($path));
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private function extractLayout(string $source): array
    {
        if (!preg_match('/\{layout\s+([\'"])(.+?)\1\}/', $source, $match)) {
            return [null, $source];
        }

        return [
            $match[2],
            str_replace($match[0], '', $source),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function extractBlocks(string $source): array
    {
        preg_match_all('/\{block\s+([A-Za-z_][A-Za-z0-9_]*)\}(.*?)\{\/block\}/s', $source, $matches, PREG_SET_ORDER);

        $blocks = [];
        foreach ($matches as $match) {
            $blocks[$match[1]] = $match[2];
        }

        return $blocks;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function renderSource(string $source, array $params, string $baseDir): string
    {
        $source = preg_replace_callback(
            '/\{include\s+([\'"])(.+?)\1\}/',
            fn (array $match): string => $this->renderFile($this->resolve($match[2], $baseDir), $params),
            $source,
        ) ?? $source;

        $source = $this->renderForeach($source, $params, $baseDir);

        return preg_replace_callback(
            '/\{\$([A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*)(\|noescape)?\}/',
            static function (array $match) use ($params): string {
                $value = self::value($params, $match[1]);
                $string = is_scalar($value) || $value === null ? (string) $value : '';

                return ($match[2] ?? '') === '|noescape' ? $string : Html::escape($string);
            },
            $this->stripBlockTags($source),
        ) ?? $source;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function renderForeach(string $source, array $params, string $baseDir): string
    {
        return preg_replace_callback(
            '/\{foreach\s+\$([A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*)\s+as\s+\$?([A-Za-z_][A-Za-z0-9_]*)\}(.*?)\{\/foreach\}/s',
            function (array $match) use ($params, $baseDir): string {
                $items = self::value($params, $match[1]);
                if (!is_array($items)) {
                    return '';
                }

                $html = [];
                foreach ($items as $item) {
                    $html[] = $this->renderSource($match[3], array_merge($params, [
                        $match[2] => $item,
                    ]), $baseDir);
                }

                return implode('', $html);
            },
            $source,
        ) ?? $source;
    }

    private function stripBlockTags(string $source): string
    {
        $source = preg_replace('/\{block\s+[A-Za-z_][A-Za-z0-9_]*\}/', '', $source) ?? $source;

        return str_replace('{/block}', '', $source);
    }

    private function resolve(string $template, string $baseDir): string
    {
        $path = str_starts_with($template, '/')
            ? $template
            : rtrim($baseDir, '/') . '/' . ltrim($template, '/');

        $real = realpath($path);
        if ($real === false || !is_file($real)) {
            throw new \RuntimeException("Template not found: {$template}");
        }

        if (!str_starts_with($real, $this->viewPath . '/')) {
            throw new \RuntimeException("Template is outside view path: {$template}");
        }

        return $real;
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function value(array $params, string $path): mixed
    {
        $value = $params;
        foreach (explode('.', $path) as $part) {
            if (is_array($value) && array_key_exists($part, $value)) {
                $value = $value[$part];
                continue;
            }

            return '';
        }

        return $value;
    }
}
