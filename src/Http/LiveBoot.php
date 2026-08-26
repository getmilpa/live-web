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

/**
 * What a page must embed so the remote runtime can take a component's action over the wire.
 *
 * The client cannot mint a CSRF token (it has no secret) and should not invent a session id: the
 * SERVER issues both when it renders the page, and hands them over in ONE place the runtime reads —
 * `<script id="milpa-live-boot" type="application/json">`. This object is that place, plus the
 * script tags that load the two runtimes and the vendored Alpine in the order they need
 * (greenhouse decisions/0083: the remote runtime is another layer; the page declares its boot).
 *
 * It holds no secret: the CSRF token is opaque to the client and bound to this session id and this
 * route by {@see CsrfGuardInterface::issueToken()}; whoever echoes it must also present the matching
 * session id, and the endpoint verifies both.
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
     * against.
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
     * The boot tag followed by the runtime script tags in load order — local runtime, remote
     * runtime, Alpine — each `defer` so they run after the document, in document order.
     *
     * @param array<string, string>|null $assets the URLs the assets are served at; defaults to {@see ClientRuntime::defaultUrls()}
     */
    public function html(?array $assets = null): string
    {
        $urls = $assets ?? ClientRuntime::defaultUrls();
        $tags = [$this->scriptTag()];
        foreach ([ClientRuntime::LOCAL, ClientRuntime::REMOTE, ClientRuntime::ALPINE] as $name) {
            $tags[] = '<script src="' . Html::escape($urls[$name]) . '" defer></script>';
        }

        return implode("\n", $tags);
    }
}
