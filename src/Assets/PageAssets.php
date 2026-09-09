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
 * Everything the components on one page declared, gathered once and ready to embed.
 *
 * `unreadable` is the reason this is a value object and not two strings. A component that names a
 * stylesheet it does not ship is a packaging defect that otherwise shows up as "the thing renders
 * but looks wrong" — a symptom no page can tell apart from a styling mistake. Naming it here keeps
 * the page rendering (a missing look is not worth a 500) while leaving the defect machine-readable
 * for whoever asks.
 */
final readonly class PageAssets
{
    /**
     * @param array<int, string>    $scripts    Client scripts, in declaration order.
     * @param array<string, string> $messages   Resolved words, keyed `component.key`.
     * @param array<int, string>    $emitted    `name@version` of every component that contributed.
     * @param array<string, string> $unreadable `name@version` => the path it declared and does not ship.
     */
    public function __construct(
        public string $styles = '',
        public array $scripts = [],
        public array $messages = [],
        public array $emitted = [],
        public array $unreadable = [],
    ) {
    }

    /**
     * Every contributed stylesheet, already scoped, as one tag — or nothing at all when no component
     * on this page declared one, because an empty `<style>` is noise in the source of every page.
     */
    public function styleTag(): string
    {
        if (trim($this->styles) === '') {
            return '';
        }

        return '<style data-milpa-assets="components">' . $this->styles . '</style>';
    }

    /**
     * Every contributed script as one tag, in declaration order, or nothing when there is none.
     */
    public function scriptTag(): string
    {
        if ($this->scripts === []) {
            return '';
        }

        return '<script data-milpa-assets="components">' . implode("\n", $this->scripts) . '</script>';
    }

    /**
     * The page's words as a payload the client can read, or nothing when there are none.
     *
     * JSON in a `type="application/json"` script rather than a JS assignment: the browser will not
     * execute it, so a translation carrying an apostrophe or a `</script>` cannot become code.
     */
    public function messagesTag(): string
    {
        if ($this->messages === []) {
            return '';
        }

        $json = json_encode($this->messages, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT);

        return '<script type="application/json" id="milpa-messages">' . $json . '</script>';
    }

    /**
     * Whether this page has nothing to embed — including the case where every component that
     * declared something declared a file it does not ship, which is a defect, not an absence.
     */
    public function isEmpty(): bool
    {
        return $this->styleTag() === '' && $this->scriptTag() === '' && $this->messagesTag() === '';
    }
}
