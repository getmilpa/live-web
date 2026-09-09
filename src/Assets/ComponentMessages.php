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
 * Resolves a component's declared words for one locale.
 *
 * English is the default AND the fallback, per key rather than per catalogue: a locale that
 * translates half of one renders the other half in English instead of rendering a key name at
 * somebody. That is the difference between a translation being incomplete and a page being broken.
 *
 * A component that declares no catalogue resolves to nothing, and asking for one of its keys returns
 * the key. That is deliberate and it is visible: a key on screen is a bug report anybody can file,
 * where an empty string is a layout that silently lost a word.
 *
 * Catalogues are read once per path per process. They are PHP files returning
 * `['en' => [...], 'es' => [...]]` — the shape the rest of this house already uses for config, which
 * costs no parser and lets a translator read the file without learning a format.
 */
final class ComponentMessages
{
    public const DEFAULT_LOCALE = 'en';

    /** @var array<string, array<string, array<string, string>>> */
    private array $loaded = [];

    /**
     * Every word this component has, in the best language it has them.
     *
     * @return array<string, string>
     */
    public function for(ComponentContract $contract, string $locale = self::DEFAULT_LOCALE): array
    {
        $catalogue = $this->catalogue($contract);

        if ($catalogue === []) {
            return [];
        }

        $default = $catalogue[self::DEFAULT_LOCALE] ?? [];
        $asked = $locale === self::DEFAULT_LOCALE ? [] : ($catalogue[$locale] ?? []);

        // The default first, then the asked-for language over it: a key the translation is missing
        // keeps its English, and a key it invents is not there to overwrite anything.
        return array_merge($default, array_intersect_key($asked, $default));
    }

    /**
     * One word, or the key itself when nothing answers.
     */
    public function one(ComponentContract $contract, string $key, string $locale = self::DEFAULT_LOCALE): string
    {
        return $this->for($contract, $locale)[$key] ?? $key;
    }

    /**
     * The locales this component actually ships, so a surface can offer what exists.
     *
     * @return array<int, string>
     */
    public function locales(ComponentContract $contract): array
    {
        return array_keys($this->catalogue($contract));
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function catalogue(ComponentContract $contract): array
    {
        $path = $contract->presentation()->messages;

        if ($path === null) {
            return [];
        }

        if (isset($this->loaded[$path])) {
            return $this->loaded[$path];
        }

        if (!is_file($path) || !is_readable($path)) {
            return $this->loaded[$path] = [];
        }

        $catalogue = require $path;
        $clean = [];

        foreach (\is_array($catalogue) ? $catalogue : [] as $locale => $messages) {
            if (!\is_string($locale) || !\is_array($messages)) {
                continue;
            }

            foreach ($messages as $key => $message) {
                if (\is_string($key) && \is_scalar($message)) {
                    $clean[$locale][$key] = (string) $message;
                }
            }
        }

        return $this->loaded[$path] = $clean;
    }
}
