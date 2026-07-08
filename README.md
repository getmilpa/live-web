<p align="center">
  <a href="https://github.com/getmilpa">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-dark.svg">
      <img src="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-light.svg" alt="Milpa" width="300">
    </picture>
  </a>
</p>

# Milpa Live Web

> The **web surface** for `milpa/live` — HTML renderers over the design system, XHTML state/interaction codecs, and a security-hardened `LiveEndpoint` (HMAC-signed state, CSRF, single-use nonce/replay protection).

[![CI](https://github.com/getmilpa/live-web/actions/workflows/ci.yml/badge.svg)](https://github.com/getmilpa/live-web/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/milpa/live-web.svg)](https://packagist.org/packages/milpa/live-web)
[![PHP](https://img.shields.io/badge/php-%E2%89%A5%208.3-777bb4.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](LICENSE)
[![Docs](https://img.shields.io/badge/docs-API%20reference-blue.svg)](https://getmilpa.github.io/live-web/)

`milpa/live` defines the render-target-agnostic component lifecycle (mount / handle / render,
contracts, data sources). `milpa/live-web` is what makes that lifecycle reachable over HTTP and
renderable to a browser: HTML renderers for the form, dashboard, and autocomplete component
families, a dependency-free XHTML state/interaction transport codec, and the security package —
HMAC state signing, CSRF, and single-use nonce/replay protection — that `LiveEndpoint` composes
into one hardened HTTP entrypoint.

## Install

```bash
composer require milpa/live-web
```

## What it is

- **`LiveEndpoint`** — the HTTP live-loop terminus. Verifies the request method, the CSRF token,
  and the signed state envelope; authorizes the requested action against the component's own
  contract; dispatches to the component's `handle()`; and returns freshly rendered HTML plus a
  freshly signed state envelope for the client to hold onto. Every ordinary failure (bad method,
  missing fields, invalid signature, replay, unauthorized action) returns a typed error response —
  it never throws for expected bad input.
- **HTML renderers** — `AutocompleteHtmlRenderer`, `FormPrimitiveHtmlRenderer` (`input`,
  `textarea`, `select`, `checkbox`), and `DashboardHtmlRenderer` (shell, sidebar, topbar, grid,
  panel, metric card, data table, …) each turn a component's state into Alpine-bound HTML over the
  `@milpa/design` system. `XhtmlComponentCompiler` lets you author `<milpa:*>` component trees
  directly in markup and compiles them through the same renderer pipeline.
- **Transport** — `XhtmlStateTransferCodec` encodes a component's state/interaction as a single
  `<milpa-state>` / `<milpa-interaction>` XHTML element with a base64 JSON payload. It carries no
  security guarantees on its own — it is the `$inner` codec `SignedXhtmlStateTransferCodec` wraps.
- **Security** — `HmacStateSigner`, `HmacCsrfGuard`, `FileNonceStore`, `SignedXhtmlStateTransferCodec`,
  `ContractInteractionAuthorizer`, and `AllowListCorsPolicy` are the concrete, production classes
  `LiveEndpoint` is built to trust — see [Security](#security) below.
- **`AlpineRuntimeAdapter`** — the `Milpa\Live\Contracts\Client\ClientRuntimeAdapterInterface`
  implementation the shipped renderers target; it marks root elements with `data-milpa-*`
  attributes and describes the boot payload/asset the Alpine runtime script consumes.

## Quick example

Wiring the real security classes and issuing one interaction through `LiveEndpoint` — this is a
trimmed version of what `tests/Http/LiveEndpointTest.php` exercises, and runs as-is against this
package's own `vendor/`:

```php
use Milpa\Live\Adapters\Alpine\AlpineRuntimeAdapter;
use Milpa\Live\Components\Autocomplete\AutocompleteComponent;
use Milpa\Live\DataSource\ArrayDataSource;
use Milpa\Live\DataSource\InMemoryDataSourceRegistry;
use Milpa\Live\Http\LiveEndpoint;
use Milpa\Live\Http\LiveHttpRequest;
use Milpa\Live\Rendering\AutocompleteHtmlRenderer;
use Milpa\Live\Runtime\InMemoryComponentRegistry;
use Milpa\Live\Security\ContractInteractionAuthorizer;
use Milpa\Live\Security\FileNonceStore;
use Milpa\Live\Security\HmacCsrfGuard;
use Milpa\Live\Security\HmacStateSigner;
use Milpa\Live\Security\SignedXhtmlStateTransferCodec;
use Milpa\Live\Transport\XhtmlStateTransferCodec;
use Milpa\Live\ValueObjects\ComponentContext;

// A real component (milpa/live) over a real data source.
$sources = new InMemoryDataSourceRegistry();
$sources->register(new ArrayDataSource('customers.search', [
    ['value' => 'acme', 'label' => 'Acme Studio', 'search' => 'agency design'],
    ['value' => 'milpa', 'label' => 'Milpa Labs', 'search' => 'framework components'],
]));
$components = new InMemoryComponentRegistry();
$components->register('autocomplete', new AutocompleteComponent($sources));

// The real security wiring: HMAC-signed state + single-use replay nonce + CSRF.
$codec = new SignedXhtmlStateTransferCodec(
    new XhtmlStateTransferCodec(),
    new HmacStateSigner($_ENV['LIVE_STATE_SECRET']),
    new FileNonceStore(sys_get_temp_dir() . '/milpa-live-nonces.json'),
);
$csrf = new HmacCsrfGuard($_ENV['LIVE_CSRF_SECRET']);

$endpoint = new LiveEndpoint(
    components: $components,
    codec: $codec,
    authorizer: new ContractInteractionAuthorizer($components),
    csrf: $csrf,
    route: '/live/autocomplete',
    renderers: ['autocomplete' => new AutocompleteHtmlRenderer(new AlpineRuntimeAdapter(), $codec)],
);

// Mount the initial state (server-rendered on the page) and issue a CSRF token for the session.
$context = new ComponentContext('customer-picker', route: '/autocomplete-demo');
$state = $components->get('autocomplete')->mount(['name' => 'customer', 'source' => 'customers.search'], $context);
$sessionId = 'demo-session'; // however your app tracks sessions (e.g. the PHP session id)
$csrfToken = $csrf->issueToken($sessionId, '/live/autocomplete');
$envelope = $codec->encodeState($state); // embed both in the SSR'd page

// The client echoes $envelope + $csrfToken back on every interaction.
$response = $endpoint->handle(new LiveHttpRequest(
    method: 'POST',
    action: 'search',
    stateEnvelope: $envelope,
    payload: ['query' => 'mil'],
    sessionId: $sessionId,
    csrfToken: $csrfToken,
));

$response->status;             // 200
$response->body['data'];       // ['items' => [['value' => 'milpa', ...]]]
$response->body['html'];       // freshly rendered <input x-data="milpaAutocomplete(...)">…
$response->body['state'];      // a freshly signed <milpa-state> envelope for the next round-trip
```

## Security

`LiveEndpoint`'s trust model, in one line: **the client cannot hold the signing secret, so it
never builds a state envelope itself** — it only ever echoes back, byte for byte, the last
`<milpa-state>` envelope this server signed and handed it (first embedded in the SSR'd page, then
refreshed on every response). Verifying that envelope is what proves it hasn't been tampered with:

- **Tamper → reject.** Any change to the signed envelope — the component id, the state payload,
  a claim — fails HMAC-SHA256 verification and `LiveEndpoint` returns `400 invalid_signature`
  before the request ever reaches a component's `handle()`.
- **Replay → 409, not silently reused.** When a `FileNonceStore` (or another
  `NonceStoreInterface`) is wired into `SignedXhtmlStateTransferCodec`, every signature carries a
  single-use nonce; decoding the exact same signed envelope a second time throws
  `ReplayedNonceException` and `LiveEndpoint` answers `409 replay_detected` — a conflict with the
  server's current state, not a permissions failure, because the request was genuinely authentic
  the first time.
- **CSRF is a separate, independent gate.** `HmacCsrfGuard` binds a token to the exact
  `sessionId`/`route` pair it was issued for; a token issued for one session or route never
  verifies for another, and CSRF failure (`403 csrf`) is checked before the state envelope is even
  decoded.
- **Authorization is contract-based, not ambient.** `ContractInteractionAuthorizer` only allows an
  action that the component's own contract declares, checks that the state's owning principal (if
  any) matches the caller, and requires the derived `milpa:component:{name}:{action}` scope — an
  attacker who forges a plausible-looking action name still gets `403 action_not_allowed`.

None of this is optional wiring you have to remember: `LiveEndpoint::handle()` runs method → CSRF →
signature/replay → authorization, in that order, and turns every failure into the matching HTTP
status instead of throwing.

**The `@milpa/design` topology caveat.** The HTML renderers' CSS comes from `Milpa\Live\Support\MilpaDesign`,
which resolves the `@milpa/design` npm package at `node_modules/@milpa/design` under your project
root. If your layout differs (a monorepo, a lab checkout without `npm install`), set
`MILPA_DESIGN_PATH` to the design package's directory — it takes priority over the npm-relative
lookup and is checked first by every method on `MilpaDesign`.

## What's inside

| Namespace | What it provides |
|-----------|------------------|
| `Milpa\Live\Http` | `LiveEndpoint` — the hardened HTTP live-loop entrypoint |
| `Milpa\Live\Security` | `HmacStateSigner`, `HmacCsrfGuard`, `FileNonceStore`, `SignedXhtmlStateTransferCodec`, `ContractInteractionAuthorizer`, `AllowListCorsPolicy`, `StaticBearerTokenVerifier` |
| `Milpa\Live\Transport` | `XhtmlStateTransferCodec` — the unsigned inner transport codec |
| `Milpa\Live\Rendering` | `AutocompleteHtmlRenderer`, `FormPrimitiveHtmlRenderer`, `DashboardHtmlRenderer`, `LatteTemplateRenderer`, `XhtmlComponentCompiler` |
| `Milpa\Live\Adapters\Alpine` | `AlpineRuntimeAdapter` — the shipped `ClientRuntimeAdapterInterface` |
| `Milpa\Live\Support` | `Html` (escaping helpers), `MilpaDesign` (design-system path resolution) |
| `Milpa\Live\Contracts\*` | `Security`, `Rendering`, and `Transport` seams — `CsrfGuardInterface`, `StateSignerInterface`, `NonceStoreInterface`, `StateTransferCodecInterface`, `ComponentRendererInterface`, `MarkupCompilerInterface`, `TemplateRendererInterface`, `CorsPolicyInterface`, `InteractionAuthorizerInterface`, `TokenVerifierInterface` |
| `Milpa\Live\ValueObjects` | `StateSignature`, `AuthorizationResult`, `CorsDecision` |

Every public symbol carries a DocBlock.

## Requirements

- PHP **≥ 8.3** with the **`ext-dom`** extension
- [`milpa/core`](https://packagist.org/packages/milpa/core) **^0.5**
- [`milpa/live`](https://packagist.org/packages/milpa/live) **^0.1**

## Documentation

**Full API reference: [getmilpa.github.io/live-web](https://getmilpa.github.io/live-web/)** —
generated straight from the source DocBlocks and dressed with the Milpa design system.

## Contributing

Contributions are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md). Please report security
issues via [SECURITY.md](SECURITY.md), and note that this project follows a
[Code of Conduct](CODE_OF_CONDUCT.md).

## License

[Apache-2.0](LICENSE) © TeamX Agency.

---

Milpa is designed, built, and maintained by **[TeamX Agency](https://teamx.agency/?utm_source=github&utm_medium=readme&utm_campaign=milpa&utm_content=live-web)**.
