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

namespace Milpa\Live\Http;

use Milpa\Live\Contracts\Security\CsrfGuardInterface;
use Milpa\Live\Support\ClientRuntime;
use Milpa\Live\Support\Html;
use Milpa\Live\ValueObjects\ClientAssets;

/**
 * What a page must embed so the remote runtime can take a component's action over the wire.
 *
 * The client cannot mint a CSRF token (it has no secret) and should not invent a session id: the
 * SERVER issues both when it renders the page, and hands them over in ONE place the runtime reads —
 * `<script id="milpa-live-boot" type="application/json">`. This object is that place, plus the
 * script tags that load the two runtimes and the vendored Alpine in the order they need
 * (greenhouse decisions/0083: the remote runtime is another layer; the page declares its boot).
 *
 * It is also the ONE emitter of the client runtime (greenhouse decisions/0211): a host never
 * hand-writes runtime `<script>` tags — it hands {@see html()} the {@see ClientAssets} its compiled
 * page declared, and gets back the stylesheets, the boot, the local runtime, the remote runtime, the
 * plugin modules and Alpine, each once and in that order. One runtime per page: a guest never loads
 * Alpine or `milpa-live.js` itself.
 *
 * It holds no secret: the CSRF token is opaque to the client and bound to this session id and this
 * route by {@see CsrfGuardInterface::issueToken()}; whoever echoes it must also present the matching
 * session id, and the endpoint verifies both. The session id travels in this payload: the runtime
 * echoes it in every request body, and the host's adapter is to fill `LiveHttpRequest::$sessionId`
 * from there — not from a cookie another page set.
 */
final readonly class LiveBoot
{
    /**
     * @param string      $endpoint      the route the endpoint is mounted on, e.g. `/live`
     * @param string      $sessionId     the page session the CSRF token is bound to
     * @param string      $csrfToken     the token {@see CsrfGuardInterface::issueToken()} issued for `$sessionId` + `$endpoint`
     * @param string|null $authorization an `Authorization` header value the page authorises the runtime to send (e.g. `Bearer …`), or null
     */
    public function __construct(
        public string $endpoint,
        public string $sessionId,
        public string $csrfToken,
        public ?string $authorization = null,
    ) {
        if (trim($endpoint) === '' || trim($sessionId) === '' || trim($csrfToken) === '') {
            throw new \InvalidArgumentException('a live boot names its endpoint, its session and its CSRF token — a boot without them cannot take an action');
        }
    }

    /**
     * Issues a fresh page session and its CSRF token for `$endpoint`.
     *
     * The session id is random and per page load: it is not an identity (the principal comes from
     * the request's authentication, never from here), only the binding the CSRF token is checked
     * against. It is what the runtime echoes as `sessionId` on every action, so a host adapter needs
     * no cookie to know which session a token was issued for.
     */
    public static function issue(CsrfGuardInterface $csrf, string $endpoint, ?string $authorization = null): self
    {
        $sessionId = 'live-' . bin2hex(random_bytes(12));

        return new self($endpoint, $sessionId, $csrf->issueToken($sessionId, $endpoint), $authorization);
    }

    /**
     * The boot payload as data — what the runtime's `bootData()` reads.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $data = [
            'endpoint' => $this->endpoint,
            'sessionId' => $this->sessionId,
            'csrfToken' => $this->csrfToken,
        ];
        if ($this->authorization !== null) {
            $data['authorization'] = $this->authorization;
        }

        return $data;
    }

    /**
     * The `<script id="milpa-live-boot" type="application/json">` element, JSON-encoded so it can
     * never break out of its tag (`<` is escaped).
     */
    public function scriptTag(): string
    {
        $json = json_encode($this->toArray(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_HEX_TAG | \JSON_HEX_AMP);

        return '<script id="milpa-live-boot" type="application/json">' . $json . '</script>';
    }

    /**
     * Everything the page loads, in the one order that works — each URL once:
     *
     * 1. every declared stylesheet, as `<link rel="stylesheet">` (before any script, so the first
     *    paint is styled);
     * 2. the boot tag;
     * 3. the local runtime (`milpa-live.js`) — it owns `MilpaLive.register()` and the built-in
     *    factories;
     * 4. the remote runtime (`milpa-live-remote.js`) — it registers through the same path;
     * 5. every declared plugin script, in declared order — each calls `MilpaLive.register(...)`;
     * 6. Alpine, last — it starts and flushes the registrations.
     *
     * Every script is `defer`, so they run after the document in document order. A declared script
     * that names one of the three runtime files is skipped: the host emits the runtime, a plugin
     * never does (greenhouse decisions/0211).
     *
     * @param array<string, string>|null $assets       the URLs the runtime files are served at; defaults to {@see ClientRuntime::defaultUrls()}
     * @param ClientAssets|null          $clientAssets what the compiled page declared (see `RenderResult::clientAssets()`); null or empty for none
     */
    public function html(?array $assets = null, ?ClientAssets $clientAssets = null): string
    {
        $urls = $assets ?? ClientRuntime::defaultUrls();
        $declared = $clientAssets ?? ClientAssets::empty();
        $runtime = [$urls[ClientRuntime::LOCAL], $urls[ClientRuntime::REMOTE], $urls[ClientRuntime::ALPINE]];
        $script = static fn (string $src): string => '<script src="' . Html::escape($src) . '" defer></script>';

        $tags = [];
        foreach ($declared->styles as $href) {
            $tags[] = '<link rel="stylesheet" href="' . Html::escape($href) . '">';
        }
        $tags[] = $this->scriptTag();
        $tags[] = $script($urls[ClientRuntime::LOCAL]);
        $tags[] = $script($urls[ClientRuntime::REMOTE]);
        foreach ($declared->scripts as $src) {
            if (!\in_array($src, $runtime, true)) {
                $tags[] = $script($src);
            }
        }
        $tags[] = $script($urls[ClientRuntime::ALPINE]);

        return implode("\n", $tags);
    }
}
