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

**One signing key per page.** `HmacStateSigner` and `HmacCsrfGuard` take the house's `live.secret` —
the host configures it, and every endpoint a page talks to verifies with that same key. A guest plugin
never derives its own (no per-directory fallback): an envelope signed under another key is, to the
host's endpoint, a tampered one. The session id is issued with the boot (`LiveBoot::issue()`), travels
in the boot payload, and is echoed by the runtime in every request body; the host contract is that its
adapter fills `LiveHttpRequest::$sessionId` from that body's `sessionId` — never from a cookie another
page set. `LiveEndpoint` checks the token against whatever the adapter put there; it cannot see where
it came from, so the contract is the adapter's to keep (see the residue below for the host that does
not yet). When the guard implements `CsrfTokenLifetimeInterface`
(`HmacCsrfGuard` does: `remaining($token)`, `ttl()`), a token presented with less than a tenth of its
TTL left comes back refreshed as `csrfToken` in the OK response, and the remote runtime stores it into
the boot for the next action.

**Shipped component styles.** `Milpa\Live\Support\ComponentStyles` resolves the CSS required by the
HTML renderers without installing npm packages or a panel. Serve `ComponentStyles::path()` at
`ComponentStyles::url($assetPrefix)` alongside `DesignTokens::urls($assetPrefix)`. The bundle makes no
external font requests; `DesignTokens` supplies local fonts and their relative `fonts/` paths.

The bundle is generated from `@milpa/design` and records source-file hashes in
`resources/design/milpa-components.source.json`. Maintainers regenerate it with
`php scripts/build-component-styles.php /path/to/@milpa/design`; add `--check` to compare without writing.
`MilpaDesign` remains available for tooling that explicitly needs the upstream npm layout and its
`MILPA_DESIGN_PATH` override.

## Declared views — one runtime per page

A plugin **declares** its view and the host's single runtime **reconciles** it: one Alpine, one
`milpa-live`, one boot, one endpoint, one signing key per page (greenhouse `decisions/0211`; the
render-agnostic half — `ClientAssets`, `DeclaresClientAssets`, `CompositeComponentRegistry` — lives in
`milpa/live`).

**What a plugin declares.** Its components (a `ComponentRegistryInterface` layer), an HTML renderer per
component that implements `DeclaresClientAssets` — the `.js` module and the `.css` it serves from its
own routes — and a client module that binds the component's Alpine factory:

```js
// /plugins/billing/invoice-list.js — served by the plugin, declared by its renderer
MilpaLive.register('billingInvoiceList', function (config) { return { /* … */ }; });
```

**What the host emits.** It composes the registries, compiles the page, and hands `LiveBoot::html()`
what the compile declared — **`LiveBoot` is the ONE emitter; a host never hand-writes runtime
`<script>` tags**:

```php
use Milpa\Live\Http\LiveBoot;
use Milpa\Live\Http\LiveEndpoint;
use Milpa\Live\Rendering\ComponentRendererRegistry;
use Milpa\Live\Rendering\XhtmlComponentCompiler;
use Milpa\Live\Runtime\CompositeComponentRegistry;

$components = new CompositeComponentRegistry(['host' => $hostComponents, 'billing' => $billingComponents], writable: 'host');
$renderers = new ComponentRendererRegistry();
$renderers->registerFor('invoice-list', $billingHtmlRenderer); // implements DeclaresClientAssets

$compiled = (new XhtmlComponentCompiler($components, $renderers))->compileFragment($markup, $context);
$boot = LiveBoot::issue($csrf, '/live');
echo $compiled->output;
echo $boot->html(null, $compiled->clientAssets()); // styles → boot → milpa-live.js → milpa-live-remote.js → plugin modules → alpine.min.js

$endpoint = new LiveEndpoint($components, $codec, $authorizer, $csrf, '/live', renderers: $renderers);
```

`html()` emits every declared stylesheet as `<link rel="stylesheet">` before any script, then the boot
tag, the local runtime, the remote runtime, every declared plugin script in declared order, and Alpine
last — each `defer`, each URL once. A plugin declaring one of the runtime files is skipped: the host
emits the runtime. `LiveEndpoint` takes the same composite registry (it only needs `has`/`get`) and
the same `ComponentRendererRegistry` in place of the name-keyed `renderers` array, so one endpoint
serves every plugin's components and a `RenderEffect` from a host component re-paints a guest's. The
registry answers per name (`registerFor()`), exactly as the array did: a target-wide `register()`ed
renderer is not consulted for a name, because every shipped HTML renderer is single-family and throws
for the rest — a host with a general renderer registers it for each name it serves.

**The rules the runtime enforces.**

- **No override.** `MilpaLive.register(name, factory)` throws when `name` is already bound — a
  plugin never shadows another, nor a built-in (`milpaField`, `milpaCheckbox`, `milpaDataTable`).
  Before Alpine starts a registration is queued and flushed inside the runtime's own `alpine:init`,
  after the built-ins, in registration order; after Alpine has started it binds at once.
  `MilpaLive.registered(name)` answers whether a name is bound.
- **Double load.** A second copy of `milpa-live.js` warns (`runtime loaded twice; ignoring the
  second copy`) and returns; the first registry stands. A second copy of `milpa-live-remote.js` is
  refused the same way (it would replace `milpaDataTable` again and then throw half-applied).
  `milpa-live-remote.js` throws when the local runtime has not loaded first.
- **Alpine first is detected.** If `alpine.min.js` ran before `milpa-live.js`, the runtime warns
  (`Alpine loaded before the runtime`) and binds its factories at once instead of queueing for an
  `alpine:init` that will never fire — `registered()` never says yes for a factory Alpine never got.
  The `x-data` Alpine already walked has failed by then; the fix is the emit order, not the runtime.
- **Replace is reserved.** `register(name, factory, { replace: true })` exists for runtime modules
  only: the remote runtime replaces `milpaDataTable` with its over-the-wire variant through it. A
  plugin module never passes it.
- **Conflicts fail fast.** `CompositeComponentRegistry` throws `ComponentNameConflictException` at
  construction when two layers bind one name to different definitions, naming the component and both
  layers.
- **Current state wins over a fresh mount.** A `RenderEffect` may carry `state` — the target's
  current signed envelope — and the endpoint re-renders the target from it instead of mounting it
  fresh from `props`; a tampered, replayed, or foreign envelope is ignored and the fresh mount stands.

**Residue.** The client half of that last rule — the remote runtime collecting the target's envelope
from the page and sending it back so the handler can put it in the effect — is not shipped in this
slice: the endpoint accepts `state`, a handler that has it (from its payload, for instance) may use it.
Two consequences of the server half belong with that client half: whether or not the target renders,
the effect goes back without its `state` (the envelope is the client's own and never travels back
raw), and decoding it spends the target's nonce — if the client then fails to swap the target, the
page's copy is a replayed envelope (409 on its next action). Two hosts also predate the rules above
and are the migration slice: `milpa/desktop-app`'s `LiveController` fills the session id from a
cookie (`milpa_live_sid`) instead of the request body (app-runtime's reads the body), and both
`milpa/admin`'s `AdminPage` (local runtime + Alpine, no boot, no remote) and `milpa/desktop-app`'s
`ShellController` (all three, no `defer`) hand-write runtime `<script>` tags instead of calling
`LiveBoot::html()`.

The client contract is measured by execution: `npm test` (or
`node --test 'tests/js/**/*.test.mjs'`, Node ≥ 22, nothing to install) loads the shipped files into a
stub page and asserts the guards (both runtimes), the Alpine-first path, the queue order, the
no-override error, the replace path and the CSRF refresh.

## What's inside

| Namespace | What it provides |
|-----------|------------------|
| `Milpa\Live\Http` | `LiveEndpoint` — the hardened HTTP live-loop entrypoint; `LiveBoot` — the one emitter of the boot, the runtime and the declared assets |
| `Milpa\Live\Security` | `HmacStateSigner`, `HmacCsrfGuard`, `FileNonceStore`, `SignedXhtmlStateTransferCodec`, `ContractInteractionAuthorizer`, `AllowListCorsPolicy`, `StaticBearerTokenVerifier` |
| `Milpa\Live\Transport` | `XhtmlStateTransferCodec` — the unsigned inner transport codec |
| `Milpa\Live\Rendering` | `AutocompleteHtmlRenderer`, `FormPrimitiveHtmlRenderer`, `DashboardHtmlRenderer`, `LatteTemplateRenderer`, `XhtmlComponentCompiler` |
| `Milpa\Live\Effects` | `RenderEffect`, `DispatchEffect`, `StateEffect` — the declared cross-component effects |
| `Milpa\Live\Adapters\Alpine` | `AlpineRuntimeAdapter` — the shipped `ClientRuntimeAdapterInterface` |
| `Milpa\Live\Support` | `Html` (escaping helpers), `MilpaDesign` (design-system path resolution), `ClientRuntime` (the shipped client files) |
| `Milpa\Live\Contracts\*` | `Security`, `Rendering`, and `Transport` seams — `CsrfGuardInterface`, `CsrfTokenLifetimeInterface`, `StateSignerInterface`, `NonceStoreInterface`, `StateTransferCodecInterface`, `ComponentRendererInterface`, `MarkupCompilerInterface`, `TemplateRendererInterface`, `CorsPolicyInterface`, `InteractionAuthorizerInterface`, `TokenVerifierInterface` |
| `Milpa\Live\ValueObjects` | `StateSignature`, `AuthorizationResult`, `CorsDecision` |

Every public symbol carries a DocBlock.

### Declared events

This package holds **no `dispatch()` site of its own**: every event a live request emits
(`component.mounting`/`mounted`, `component.handling`/`handled`, `component.rendering`/`rendered`,
`live.request`, `live.responded`) is dispatched by `milpa/live`'s `LiveEventEmitter`, which owns
their names and their declarations. What this package does is declare that catalogue **where the
dispatcher enters it** — the constructors of `LiveEndpoint` and of the four HTML renderers each
call `LiveEventEmitter::declareTo($dispatcher)`, so a dispatcher implementing `DeclaredEvents`
(`milpa/core` ≥ 0.11) can be asked «what events exist?» at boot, before the first request. A
dispatcher without the contract is asked nothing, and no dispatcher at all changes nothing
(greenhouse decisions/0228).

## Requirements

- PHP **≥ 8.3** with the **`ext-dom`** extension
- [`milpa/core`](https://packagist.org/packages/milpa/core) **≥ 0.11, < 1.0**
- [`milpa/live`](https://packagist.org/packages/milpa/live) **≥ 0.22, < 1.0**

## Documentation

**Full API reference: [getmilpa.github.io/live-web](https://getmilpa.github.io/live-web/)** —
generated straight from the source DocBlocks and dressed with the Milpa design system.

## Contributing

Contributions are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md). Please report security
issues via [SECURITY.md](SECURITY.md), and note that this project follows a
[Code of Conduct](CODE_OF_CONDUCT.md).

## License

[Apache-2.0](LICENSE) © Rodrigo Vicente - TeamX Agency.

---

Milpa is designed, built, and maintained by **[Rodrigo Vicente - TeamX Agency](https://teamx.agency/?utm_source=github&utm_medium=readme&utm_campaign=milpa&utm_content=live-web)**.
