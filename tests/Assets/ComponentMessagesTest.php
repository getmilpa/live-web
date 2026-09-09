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

namespace Milpa\Live\Tests\Assets;

use Milpa\Live\Adapters\Alpine\AlpineRuntimeAdapter;
use Milpa\Live\Assets\ComponentAssetOrchestrator;
use Milpa\Live\Assets\ComponentMessages;
use Milpa\Live\Components\Dashboard\DashboardShellComponent;
use Milpa\Live\Rendering\DashboardHtmlRenderer;
use Milpa\Live\Transport\XhtmlStateTransferCodec;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\ComponentContract;
use Milpa\Live\ValueObjects\ComponentPresentation;
use Milpa\Live\ValueObjects\RenderRequest;
use PHPUnit\Framework\TestCase;

/**
 * A component's words travel with the component, by the same seam its styles do.
 *
 * The failure this guards against is not a missing translation — it is a SECOND system. Today
 * `milpa/admin` holds one component system's look in a 4,849-line bundle and its words in a
 * 414-string `private const`, and the two have already drifted apart. One declaration, three
 * payloads, is the whole point.
 */
final class ComponentMessagesTest extends TestCase
{
    private const FOREIGN = __DIR__ . '/../Fixtures/ForeignPackage';

    private function rating(): ComponentContract
    {
        return new ComponentContract(
            name: 'rating',
            contractVersion: '1',
            presentation: new ComponentPresentation(messages: self::FOREIGN . '/rating.messages.php'),
        );
    }

    private function badge(): ComponentContract
    {
        return new ComponentContract(
            name: 'badge',
            contractVersion: '1',
            presentation: new ComponentPresentation(messages: self::FOREIGN . '/badge.messages.php'),
        );
    }

    /**
     * A stranger's words arrive on the page, without a line changing in any consumer package.
     */
    public function testAForeignComponentBringsItsOwnWords(): void
    {
        $assets = (new ComponentAssetOrchestrator())->collect([$this->rating()]);

        self::assertSame('Rate this', $assets->messages['rating.label'] ?? null);
        self::assertSame('Pick a star', $assets->messages['rating.hint'] ?? null);
        self::assertSame(['rating@1'], $assets->emitted);
    }

    /**
     * The message analogue of scoping: two strangers may both call a key `label`.
     *
     * Control: both catalogues really do use `label`, so keeping them apart is a result and not a
     * coincidence of the fixtures.
     */
    public function testTwoComponentsUsingTheSameKeyDoNotOverwriteEachOther(): void
    {
        $rating = require self::FOREIGN . '/rating.messages.php';
        $badge = require self::FOREIGN . '/badge.messages.php';
        self::assertArrayHasKey('label', $rating['en']);
        self::assertArrayHasKey('label', $badge['en']);

        $assets = (new ComponentAssetOrchestrator())->collect([$this->rating(), $this->badge()], 'es');

        self::assertSame('Califica esto', $assets->messages['rating.label'] ?? null);
        self::assertSame('Nuevo', $assets->messages['badge.label'] ?? null);
    }

    /**
     * The fallback is PER KEY. A half-translated catalogue renders the other half in English, not a
     * key name — which is the difference between an incomplete translation and a broken page.
     */
    public function testAHalfTranslatedCatalogueKeepsEnglishForTheRest(): void
    {
        $words = (new ComponentMessages())->for($this->rating(), 'es');

        self::assertSame('Califica esto', $words['label']);
        self::assertSame('Pick a star', $words['hint'], 'the untranslated key must stay readable');
    }

    /**
     * A key that exists only in the translation answers to nothing, so it does not travel.
     */
    public function testAKeyInventedByATranslationNeverReachesThePage(): void
    {
        $words = (new ComponentMessages())->for($this->rating(), 'es');

        self::assertArrayNotHasKey('invented', $words);
    }

    public function testAnUnknownLocaleIsEnglishRatherThanEmpty(): void
    {
        $words = (new ComponentMessages())->for($this->rating(), 'de');

        self::assertSame(['label' => 'Rate this', 'hint' => 'Pick a star'], $words);
    }

    /**
     * A component with no words declares no catalogue, and resolves to nothing at all.
     *
     * A template that then asks for a key prints the key, which is deliberate and visible: a key on
     * screen is a bug anybody can report, where a blank is a layout that quietly lost a word.
     */
    public function testAComponentWithoutWordsResolvesToNothing(): void
    {
        self::assertSame([], (new ComponentMessages())->for(new ComponentContract(name: 'bare', contractVersion: '1')));
    }

    /**
     * The payload is JSON the browser will not execute, so a translation cannot become code.
     */
    public function testATranslationCannotBreakOutOfThePayload(): void
    {
        $hostile = new ComponentContract(
            name: 'hostile',
            contractVersion: '1',
            presentation: new ComponentPresentation(messages: self::FOREIGN . '/hostile.messages.php'),
        );

        $tag = (new ComponentAssetOrchestrator())->collect([$hostile])->messagesTag();

        self::assertStringContainsString('type="application/json"', $tag);
        self::assertStringNotContainsString('</script>', substr($tag, 0, -9), 'a translation closed the script tag');
    }

    /**
     * THE ONE THAT DECIDES — measured on RENDERED MARKUP, not on the catalogue.
     *
     * A catalogue can be perfect while the template still prints its own hardcoded sentence, which
     * is exactly the state this arc found the house in. So this renders the real component through
     * its real renderer, twice, and reads the words off the markup.
     */
    public function testTheHouseSOwnComponentRendersEnglishByDefaultAndSpanishWhenAsked(): void
    {
        $english = $this->shell(ComponentMessages::DEFAULT_LOCALE);
        $spanish = $this->shell('es');

        self::assertStringContainsString('Skip to content', $english);
        self::assertStringNotContainsString('Saltar al contenido', $english, 'English is the default and it was not');

        self::assertStringContainsString('Saltar al contenido', $spanish);
        self::assertStringNotContainsString('Skip to content', $spanish);
    }

    /**
     * The template subset prints an expression it does not support VERBATIM, with no error.
     *
     * That is how the first attempt at this shipped `{$t['skip_to_content']}` onto the page as text.
     * The subset takes dot notation; this holds the templates to it, because the failure is silent
     * and looks exactly like a page that rendered fine.
     */
    public function testNoTemplatePrintsAnExpressionTheSubsetCannotRead(): void
    {
        $found = [];

        foreach ((array) glob(\dirname(__DIR__, 2) . '/templates/components/*.latte') as $template) {
            if (preg_match('/\{\$[A-Za-z_][A-Za-z0-9_]*\[/', (string) file_get_contents((string) $template)) === 1) {
                $found[] = basename((string) $template);
            }
        }

        self::assertSame([], $found, 'array-access syntax renders as its own source text');
    }

    private function shell(string $locale): string
    {
        return (new DashboardHtmlRenderer(new AlpineRuntimeAdapter(), new XhtmlStateTransferCodec(), null, null, $locale))
            ->render(new DashboardShellComponent(), new RenderRequest(
                context: new ComponentContext('shell', route: '/lab'),
                props: [],
            ))->output;
    }
}
