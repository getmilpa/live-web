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

/**
 * Rewrites a component's stylesheet so its rules can only reach that component.
 *
 * A component declares plain CSS against the house tokens and never repeats its own name in a
 * selector; this puts the name in, once, for every top-level rule. That is what lets two components
 * written by two strangers use `.field` for different things on the same page without either author
 * knowing the other exists — the alternative being the convention-and-goodwill scheme that produced
 * one 4,849-line bundle nobody could safely edit.
 *
 * Three rules govern what is rewritten, and the two exceptions matter more than the rule:
 *
 * - A rule's SELECTOR is prefixed; its BODY is copied verbatim. Native CSS nesting therefore keeps
 *   working untouched — a nested rule already inherits its parent's now-scoped selector, so
 *   prefixing it again would scope it twice.
 * - Conditional at-rules (`@media`, `@supports`, `@container`, `@layer` with a block, `@scope`) are
 *   recursed into, because their bodies hold ordinary rules.
 * - Everything else at-rule-shaped is copied verbatim. `@keyframes` is the reason: its "selectors"
 *   are `from`, `to` and percentages, and prefixing those silently kills the animation.
 *
 * `:host` names the component's own root, the way it does in shadow DOM. Its plain-CSS inertness is
 * the point: a component stylesheet that somehow reaches the page unscoped styles nothing, instead
 * of styling the document like a stray `:root` would.
 */
final class CssScoper
{
    /**
     * At-rules whose block contains ordinary rules, so scoping has to continue inside them.
     */
    private const RECURSIVE_AT_RULES = ['media', 'supports', 'container', 'layer', 'scope'];

    /**
     * Confines a stylesheet to one component, returning CSS the page can embed as-is.
     *
     * @param string $css   The component's stylesheet, as authored.
     * @param string $scope A selector matching the component root (e.g. `[data-milpa-component="input"]`).
     */
    public function scope(string $css, string $scope): string
    {
        $out = '';
        $prelude = '';
        $length = \strlen($css);

        for ($i = 0; $i < $length; ++$i) {
            $char = $css[$i];

            if ($char === '/' && ($css[$i + 1] ?? '') === '*') {
                $end = strpos($css, '*/', $i + 2);
                $end = $end === false ? $length : $end + 2;
                $prelude .= substr($css, $i, $end - $i);
                $i = $end - 1;

                continue;
            }

            if ($char === '{') {
                $close = $this->matchingBrace($css, $i);
                $body = substr($css, $i + 1, $close - $i - 1);
                $out .= $this->rewriteBlock($prelude, $body, $scope);
                $prelude = '';
                $i = $close;

                continue;
            }

            if ($char === ';' && str_starts_with(ltrim($prelude), '@')) {
                $out .= $prelude . ';';
                $prelude = '';

                continue;
            }

            $prelude .= $char;
        }

        return $out . $prelude;
    }

    private function rewriteBlock(string $prelude, string $body, string $scope): string
    {
        $trimmed = trim($prelude);

        if (str_starts_with($trimmed, '@')) {
            $name = strtolower((string) preg_replace('/^@([a-z-]*).*$/is', '$1', $trimmed));
            $body = \in_array($name, self::RECURSIVE_AT_RULES, true) ? $this->scope($body, $scope) : $body;

            return $prelude . '{' . $body . '}';
        }

        return $this->prefixSelectorList($prelude, $scope) . '{' . $body . '}';
    }

    /**
     * Prefixes every selector in a comma-separated list, preserving the author's leading whitespace.
     */
    private function prefixSelectorList(string $prelude, string $scope): string
    {
        // Comments come out before anything is split: a prose comma inside one would otherwise read
        // as a selector separator, and the comment's own words would be prefixed as if they were
        // selectors. Held aside and re-emitted, so the author keeps the comment and the parser never
        // sees it.
        $comments = '';
        $selectorText = (string) preg_replace_callback(
            '#/\*.*?\*/#s',
            static function (array $match) use (&$comments): string {
                $comments .= $match[0];

                return '';
            },
            $prelude,
        );

        $lead = substr($selectorText, 0, \strlen($selectorText) - \strlen(ltrim($selectorText)));
        $selectors = [];

        foreach ($this->splitTopLevel(trim($selectorText)) as $selector) {
            $selector = trim($selector);

            if ($selector !== '') {
                $selectors[] = $this->prefixSelector($selector, $scope);
            }
        }

        return $comments . $lead . implode(', ', $selectors) . ' ';
    }

    private function prefixSelector(string $selector, string $scope): string
    {
        if (!str_starts_with($selector, ':host')) {
            return $scope . ' ' . $selector;
        }

        $rest = substr($selector, 5);

        // `:host(.compound)` narrows the root itself, so its argument attaches with no combinator.
        if (str_starts_with($rest, '(')) {
            $close = $this->matchingParen($rest, 0);

            return $scope . substr($rest, 1, $close - 1) . substr($rest, $close + 1);
        }

        return $scope . $rest;
    }

    /**
     * Splits on commas that belong to the selector list itself — not to `:is(a, b)` or `[x="a,b"]`.
     *
     * @return array<int, string>
     */
    private function splitTopLevel(string $selectors): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $quote = null;
        $length = \strlen($selectors);

        for ($i = 0; $i < $length; ++$i) {
            $char = $selectors[$i];

            if ($quote !== null) {
                $current .= $char;

                if ($char === $quote && ($selectors[$i - 1] ?? '') !== '\\') {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(' || $char === '[') {
                ++$depth;
            } elseif ($char === ')' || $char === ']') {
                --$depth;
            } elseif ($char === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';

                continue;
            }

            $current .= $char;
        }

        $parts[] = $current;

        return $parts;
    }

    private function matchingBrace(string $css, int $open): int
    {
        $depth = 0;
        $quote = null;
        $length = \strlen($css);

        for ($i = $open; $i < $length; ++$i) {
            $char = $css[$i];

            if ($quote !== null) {
                if ($char === $quote && ($css[$i - 1] ?? '') !== '\\') {
                    $quote = null;
                }

                continue;
            }

            if ($char === '/' && ($css[$i + 1] ?? '') === '*') {
                $end = strpos($css, '*/', $i + 2);
                $i = $end === false ? $length : $end + 1;

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '{') {
                ++$depth;
            } elseif ($char === '}' && --$depth === 0) {
                return $i;
            }
        }

        return $length - 1;
    }

    private function matchingParen(string $subject, int $open): int
    {
        $depth = 0;
        $length = \strlen($subject);

        for ($i = $open; $i < $length; ++$i) {
            if ($subject[$i] === '(') {
                ++$depth;
            } elseif ($subject[$i] === ')' && --$depth === 0) {
                return $i;
            }
        }

        return $length - 1;
    }
}
