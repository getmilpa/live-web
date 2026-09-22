/**
 * Milpa Remote Runtime — milpa-live-remote.js
 *
 * The OTHER layer (ADR#9, greenhouse decisions/0083): the client half of the live wire. Where
 * milpa-live.js only activates local UX, this file takes an action a server-rendered component
 * declared (`@click="sort(key)"`, `@change="toggleRow(id)"`), sends it to the LiveEndpoint, and
 * applies what the server answers. It decides NOTHING: the server holds the truth and the signed
 * state; this runtime only echoes the envelope it was handed and paints the HTML it gets back.
 *
 * Wire (see Milpa\Live\Http\LiveEndpoint): POST {endpoint} with JSON
 *   { action, payload, state: <the last envelope the server signed>, sessionId, csrfToken }
 * and apply `html` + the new `state` from the response. The envelope is never built here — the
 * client cannot hold the signing secret — it is read from the `<script type="application/milpa+xhtml"
 * data-milpa-state="<componentId>">` the renderer embedded, and replaced with the one the server
 * returns. Boot data (endpoint, sessionId, csrfToken, optional authorization) comes from the
 * `<script id="milpa-live-boot" type="application/json">` the page embeds (Milpa\Live\Http\LiveBoot).
 *
 * Client-local persistence: a component with a `persistKey` remembers its selection/sort in
 * localStorage (or sessionStorage when `storage: 'session'`) so a reload keeps what the human chose.
 * That is UX memory, not truth: the server re-validates every action against the signed state.
 *
 * One runtime per page (greenhouse decisions/0211): this module registers its factories through the local
 * runtime's `MilpaLive.register()` — the same path a plugin module uses — and REQUIRES the local runtime to
 * have loaded first (it throws otherwise; LiveBoot::html() emits both in order). It is the one module allowed
 * to REPLACE a built-in: `milpaDataTable` becomes the over-the-wire variant via the explicit
 * `register(name, factory, { replace: true })`, which is reserved for runtime modules — a plugin never
 * replaces anything. Loading this file twice is refused like the local runtime: the second copy warns and
 * returns, so the replacement happens once and the other factories are never re-registered (which would throw).
 *
 * No-build (ADR#10): hand-written, readable, served as-is. Loads after milpa-live.js and before
 * alpine.min.js; all three `defer`, so they run in document order.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency · Apache-2.0
 */
(function () {
  'use strict';

  var runtime = window.MilpaLive;
  if (!runtime || typeof runtime.register !== 'function') {
    throw new Error('[milpa-live-remote] the local runtime (milpa-live.js) must load first — LiveBoot::html() emits both in order (greenhouse decisions/0211)');
  }

  // DOUBLE-LOAD GUARD, mirrored from the local runtime: a second copy of this module (a guest shipping its own
  // at another URL) would replace `milpaDataTable` again and then throw on `milpaAutocomplete` — half-applied.
  // It is refused whole, loudly, and the first copy stands.
  if (runtime.__remoteLoaded) {
    console.warn('[milpa-live-remote] runtime loaded twice; ignoring the second copy');
    return;
  }

  function bootData() {
    var el = document.getElementById('milpa-live-boot');
    if (!el) { return null; }
    try { return JSON.parse(el.textContent || '{}'); } catch (e) { return null; }
  }

  // CSRF refresh (greenhouse decisions/0211): when the endpoint answers with a fresh `csrfToken` (the one
  // presented had a tenth of its life left), keep it in the boot — the ONE place this runtime reads the
  // token from — so the NEXT action presents the fresh one. The session id never changes.
  function refreshCsrf(data) {
    if (!data || typeof data.csrfToken !== 'string' || data.csrfToken === '') { return; }
    var el = document.getElementById('milpa-live-boot');
    var boot = bootData();
    if (!el || !boot) { return; }
    boot.csrfToken = data.csrfToken;
    el.textContent = JSON.stringify(boot);
  }

  // The signed envelope is keyed by componentId and unique in the document. The renderer may place
  // it INSIDE the component root or as an adjacent sibling (the shared component layout appends it
  // after the body), so look in the root first, then fall back to the whole document.
  function envelopeScript(root, componentId) {
    return root.querySelector('script[data-milpa-state="' + componentId + '"]')
      || document.querySelector('script[data-milpa-state="' + componentId + '"]');
  }

  function envelopeOf(root, componentId) {
    var el = envelopeScript(root, componentId);
    return el ? el.textContent : null;
  }

  function storageFor(kind) {
    try { return kind === 'session' ? window.sessionStorage : window.localStorage; } catch (e) { return null; }
  }

  // The default transport: a same-origin fetch to the endpoint. Returns { status, data }.
  function fetchTransport(boot, requestBody) {
    var headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
    if (boot.authorization) { headers['Authorization'] = boot.authorization; }
    return fetch(boot.endpoint, {
      method: 'POST',
      headers: headers,
      credentials: 'same-origin',
      body: JSON.stringify(requestBody),
    }).then(function (r) {
      return r.json().then(function (data) { return { status: r.status, data: data }; });
    });
  }

  // Send one declared action and hand back the parsed response. The transport is PLUGGABLE: a host
  // that cannot make a same-origin fetch — a native shell (Electron/WebView) whose page is file:// but
  // whose backend is a container, for instance — sets `window.MilpaLive.transport` to route the POST
  // through its own bridge (e.g. an IPC channel). It receives (boot, requestBody) and returns a
  // Promise<{ status, data }>. Left unset, the runtime uses a plain fetch (a normal web page).
  function send(boot, componentRoot, componentId, action, payload) {
    var envelope = envelopeOf(componentRoot, componentId);
    if (!boot || !envelope) {
      return Promise.reject(new Error('live: no boot data or no signed state on this component'));
    }
    var requestBody = {
      action: action,
      payload: payload || {},
      state: envelope,
      sessionId: boot.sessionId,
      csrfToken: boot.csrfToken,
    };
    var transport = (window.MilpaLive && typeof window.MilpaLive.transport === 'function') ? window.MilpaLive.transport : fetchTransport;
    return Promise.resolve(transport(boot, requestBody)).then(function (result) {
      if (result && result.status >= 200 && result.status < 300) { refreshCsrf(result.data); }
      return result;
    });
  }

  // A live render can introduce a component that was not present in the initial document. The server
  // returns resolved bytes (never package paths) for those contracts plus URL assets from renderers.
  // Seed from the SSR tags, then remember every addition so repeated renders cost each asset once.
  var installedComponentAssets = null;
  var installedClientStyles = null;
  var installedClientScripts = null;

  function elements(selector) {
    if (!document || typeof document.querySelectorAll !== 'function') { return []; }
    try { return Array.prototype.slice.call(document.querySelectorAll(selector)); } catch (e) { return []; }
  }

  function attribute(el, name) {
    return el && typeof el.getAttribute === 'function' ? el.getAttribute(name) : null;
  }

  function mark(el, name, value) {
    if (el && typeof el.setAttribute === 'function') { el.setAttribute(name, value); }
  }

  function appendTarget() {
    return document.head || document.body || document.documentElement || null;
  }

  function seedInstalledAssets() {
    if (installedComponentAssets !== null) { return; }
    installedComponentAssets = {};
    installedClientStyles = {};
    installedClientScripts = {};
    elements('[data-milpa-components]').forEach(function (el) {
      String(attribute(el, 'data-milpa-components') || '').split(/\s+/).forEach(function (key) {
        if (key) { installedComponentAssets[key] = true; }
      });
    });
    elements('link[rel="stylesheet"][href]').forEach(function (el) {
      var href = attribute(el, 'href');
      if (href) { installedClientStyles[href] = true; }
    });
    elements('script[src]').forEach(function (el) {
      var src = attribute(el, 'src');
      if (src) { installedClientScripts[src] = true; }
    });
  }

  function installClientStyle(href) {
    if (!href || installedClientStyles[href]) { return; }
    installedClientStyles[href] = true;
    var target = appendTarget();
    if (!target || typeof target.appendChild !== 'function' || typeof document.createElement !== 'function') { return; }
    var link = document.createElement('link');
    mark(link, 'rel', 'stylesheet');
    mark(link, 'href', href);
    mark(link, 'data-milpa-live-asset', 'style');
    target.appendChild(link);
  }

  function installClientScript(src) {
    if (!src || installedClientScripts[src]) { return Promise.resolve(); }
    installedClientScripts[src] = true;
    var target = appendTarget();
    if (!target || typeof target.appendChild !== 'function' || typeof document.createElement !== 'function') { return Promise.resolve(); }
    return new Promise(function (resolve, reject) {
      var script = document.createElement('script');
      script.async = false;
      mark(script, 'src', src);
      mark(script, 'data-milpa-live-asset', 'script');
      script.onload = resolve;
      script.onerror = function () {
        delete installedClientScripts[src];
        reject(new Error('live: failed to load declared script ' + src));
      };
      target.appendChild(script);
    });
  }

  function mergeMessages(words, componentKey) {
    if (!words || typeof words !== 'object' || Object.keys(words).length === 0) { return; }
    var el = document.getElementById('milpa-messages');
    var current = {};
    if (el) {
      try { current = JSON.parse(el.textContent || '{}') || {}; } catch (e) { current = {}; }
    } else if (typeof document.createElement === 'function') {
      el = document.createElement('script');
      mark(el, 'type', 'application/json');
      mark(el, 'id', 'milpa-messages');
      var target = document.body || appendTarget();
      if (target && typeof target.appendChild === 'function') { target.appendChild(el); }
    }
    if (!el) { return; }
    Object.keys(words).forEach(function (key) { current[key] = words[key]; });
    el.textContent = JSON.stringify(current);
    var present = String(attribute(el, 'data-milpa-components') || '').split(/\s+/).filter(Boolean);
    if (present.indexOf(componentKey) === -1) { present.push(componentKey); }
    mark(el, 'data-milpa-components', present.join(' '));
  }

  function installComponentAssets(components) {
    if (!components || typeof components !== 'object') { return; }
    var target = appendTarget();
    Object.keys(components).forEach(function (key) {
      if (installedComponentAssets[key]) { return; }
      installedComponentAssets[key] = true;
      var contribution = components[key] || {};
      if (contribution.styles && target && typeof target.appendChild === 'function' && typeof document.createElement === 'function') {
        var style = document.createElement('style');
        mark(style, 'data-milpa-assets', 'components');
        mark(style, 'data-milpa-components', key);
        style.textContent = contribution.styles;
        target.appendChild(style);
      }
      if (Array.isArray(contribution.scripts) && contribution.scripts.length && target && typeof target.appendChild === 'function' && typeof document.createElement === 'function') {
        var script = document.createElement('script');
        mark(script, 'data-milpa-assets', 'components');
        mark(script, 'data-milpa-components', key);
        script.textContent = contribution.scripts.join('\n');
        target.appendChild(script);
      }
      mergeMessages(contribution.messages, key);
    });
  }

  function installAssets(assets) {
    seedInstalledAssets();
    var client = assets && assets.client ? assets.client : {};
    var styles = Array.isArray(client.styles) ? client.styles : [];
    var scripts = Array.isArray(client.scripts) ? client.scripts : [];
    styles.forEach(installClientStyle);
    var loaded = Promise.resolve();
    scripts.forEach(function (src) { loaded = loaded.then(function () { return installClientScript(src); }); });
    return loaded.then(function () { installComponentAssets(assets && assets.components); });
  }

  function selectorValue(value) {
    return String(value).replace(/\\/g, '\\\\').replace(/"/g, '\\"');
  }

  function activeFieldOf(root) {
    var active = document.activeElement;
    if (!active || !root || typeof root.contains !== 'function' || !root.contains(active)) { return null; }
    var owner = typeof active.closest === 'function' ? active.closest('[data-milpa-component-id]') : null;
    return {
      owner: owner && typeof owner.getAttribute === 'function' ? owner.getAttribute('data-milpa-component-id') : '',
      id: active.id || '',
      name: active.name || '',
      type: active.type || '',
      start: typeof active.selectionStart === 'number' ? active.selectionStart : null,
      end: typeof active.selectionEnd === 'number' ? active.selectionEnd : null,
      direction: active.selectionDirection || undefined,
    };
  }

  function restoreActiveField(root, remembered) {
    if (!root || !remembered || typeof root.querySelector !== 'function') { return; }
    var owner = remembered.owner ? '[data-milpa-component-id="' + selectorValue(remembered.owner) + '"] ' : '';
    var field = null;
    var selectors = [];
    if (remembered.id) { selectors.push(owner + '[id="' + selectorValue(remembered.id) + '"]'); }
    if (remembered.name) { selectors.push(owner + '[name="' + selectorValue(remembered.name) + '"]'); }
    if (remembered.id) { selectors.push('[id="' + selectorValue(remembered.id) + '"]'); }
    if (remembered.name) { selectors.push('[name="' + selectorValue(remembered.name) + '"]'); }
    selectors.some(function (selector) {
      try { field = root.querySelector(selector); } catch (e) { field = null; }
      return Boolean(field);
    });
    if (!field || typeof field.focus !== 'function') { return; }
    field.focus({ preventScroll: true });
    if (remembered.start !== null && remembered.end !== null && typeof field.setSelectionRange === 'function') {
      try { field.setSelectionRange(remembered.start, remembered.end, remembered.direction); } catch (e) { /* not a text control */ }
    }
  }

  // Insert the new root explicitly, then remove the old one. This gives Alpine one final DOM shape to
  // observe, lets us tear down the old reactive tree, and gives focus a stable element to return to.
  function replaceComponent(componentRoot, html, componentId) {
    var remembered = activeFieldOf(componentRoot);
    var stale = componentId ? document.querySelector('script[data-milpa-state="' + componentId + '"]') : null;
    if (stale && !componentRoot.contains(stale) && typeof stale.remove === 'function') { stale.remove(); }
    if (window.Alpine && typeof window.Alpine.destroyTree === 'function') { window.Alpine.destroyTree(componentRoot); }
    if (typeof componentRoot.insertAdjacentHTML !== 'function' || typeof componentRoot.remove !== 'function') {
      componentRoot.outerHTML = html;
      return swapById(componentId);
    }
    componentRoot.insertAdjacentHTML('afterend', html);
    var next = componentRoot.nextElementSibling;
    componentRoot.remove();
    var rendered = next || swapById(componentId);
    restoreActiveField(rendered, remembered);
    return rendered;
  }

  // Apply the server's answer: the re-rendered HTML replaces the component root (the new root
  // carries the new signed envelope and its own x-data, so Alpine mounts it fresh), or the
  // component shows the error the server returned.
  function apply(result, componentRoot, self, componentId) {
    if (result.status >= 200 && result.status < 300 && result.data) {
      return installAssets(result.data.assets).then(function () {
        if (result.data.html) { replaceComponent(componentRoot, result.data.html, componentId); }
        applyEffects(result.data.effects);
      });
    }
    var err = (result.data && (result.data.message || result.data.error)) || ('live: HTTP ' + result.status);
    self.error = err;
    return Promise.resolve();
  }

  // Cross-component render effects (greenhouse decisions/0189): a handler DECLARED that ANOTHER component
  // re-paints; the server rendered it, and here the client swaps that target component's root by its id.
  // A handler declares behaviour — "on this interaction, re-paint that component" — with no imperative JS.
  function swapById(id) {
    return document.querySelector('[data-milpa-component-id="' + id + '"]');
  }
  // Alpine resolves a parent method from a nested x-data scope, but its `$root` magic then names the
  // nested scope's element. The signed component id is the owner: always act on that root, with `$root`
  // only as the fallback for a non-DOM host.
  function rootOf(component) {
    return swapById(component.componentId) || component.$root;
  }
  // Deliver `dispatch` effects only AFTER the DOM the server just swapped in has been re-initialised by
  // Alpine (greenhouse #49 / decisions/0389). A component's own re-render replaces its root's outerHTML
  // (see `apply`), and a cross-component `render` effect replaces another root's — and Alpine binds a new
  // root's `x-on:` listeners ASYNCHRONOUSLY, from the MutationObserver microtask its swap queues. A
  // `dispatch` fired synchronously in the same task reaches the freshly-swapped element BEFORE its
  // listener exists, so the event is lost — the "seed a node, then navigate to it" click that silently
  // did nothing while the write succeeded. `requestAnimationFrame` runs after that microtask (before the
  // next paint), so every swapped root is bound by the time the event is delivered; `setTimeout` is the
  // fallback on a non-browser host that has no rAF.
  function afterRebind(cb) {
    if (typeof window.requestAnimationFrame === 'function') { window.requestAnimationFrame(cb); }
    else { setTimeout(cb, 0); }
  }
  function applyEffects(effects) {
    if (!Array.isArray(effects)) { return; }
    // `render` and `state` effects apply now; `dispatch` effects are held until after the swapped DOM
    // is re-bound (see afterRebind), because a dispatch may target a component this very batch — or the
    // acting component `apply` just swapped — whose listeners Alpine has not re-attached yet.
    var dispatches = [];
    effects.forEach(function (effect) {
      if (!effect) { return; }
      // render: the server rendered the target — swap its root by id.
      if (effect.type === 'render' && effect.target && effect.html) {
        var target = swapById(effect.target);
        if (!target) { return; }
        replaceComponent(target, effect.html, effect.target);
        return;
      }
      // dispatch: SIGNAL the target — deliver a `milpa:<event>` CustomEvent it can react to (no re-render).
      // Deferred until every render swap above (and the acting component's own) is re-bound.
      if (effect.type === 'dispatch' && effect.to && effect.event) {
        dispatches.push(effect);
        return;
      }
      // state: set a SHARED signal — one truth projected to every element that reads it (no target needed).
      if (effect.type === 'state' && effect.key) {
        if (window.MilpaLive && typeof window.MilpaLive.signal === 'function') { window.MilpaLive.signal(effect.key, effect.value); }
      }
    });
    if (dispatches.length > 0) {
      afterRebind(function () {
        dispatches.forEach(function (effect) {
          var el = swapById(effect.to);
          if (el) { el.dispatchEvent(new CustomEvent('milpa:' + effect.event, { detail: effect.payload || {}, bubbles: true })); }
        });
      });
    }
  }

  // A registered application component uses the same signed transport and HTML reconciliation as the
  // built-in widgets. Its renderer declares actions; the browser never implements their domain logic.
  function milpaComponent(config) {
    var cfg = config || {};
    return {
      componentId: cfg.componentId || '',
      busy: false,
      error: null,
      act: function (action, payload) {
        if (this.busy) { return Promise.resolve(); }
        var self = this;
        var root = rootOf(this);
        this.busy = true;
        this.error = null;
        return send(bootData(), root, this.componentId, action, payload)
          .then(function (result) {
            return apply(result, root, self, self.componentId).then(function () {
              if (result.status >= 200 && result.status < 300 && result.data && !result.data.html) {
                refreshEnvelope(root, self.componentId, result.data.state);
              }
            });
          })
          .catch(function (e) { self.error = e.message; })
          .then(function () { self.busy = false; });
      },
    };
  }

  // milpaDataTable: selection, sort and paging over the wire; selection remembered locally.
  function milpaDataTable(config) {
    var cfg = config || {};
    var initial = cfg.initialState || {};
    return {
      componentId: cfg.componentId || '',
      selectedRows: Array.isArray(initial.selectedRows) ? initial.selectedRows.slice() : [],
      sortBy: initial.sortBy != null ? initial.sortBy : '',
      sortDirection: initial.sortDirection != null ? initial.sortDirection : 'asc',
      page: initial.page != null ? initial.page : 1,
      error: initial.error != null ? initial.error : null,
      busy: false,
      init: function () {
        var store = cfg.persistKey ? storageFor(cfg.storage) : null;
        if (store) {
          try {
            var saved = JSON.parse(store.getItem('milpa-live:' + cfg.persistKey) || 'null');
            if (saved && Array.isArray(saved.selectedRows)) { this.selectedRows = saved.selectedRows; }
          } catch (e) { /* a broken memory is an empty memory */ }
        }
      },
      remember: function () {
        var store = cfg.persistKey ? storageFor(cfg.storage) : null;
        if (store) {
          try { store.setItem('milpa-live:' + cfg.persistKey, JSON.stringify({ selectedRows: this.selectedRows })); } catch (e) { /* ignore */ }
        }
      },
      isSelected: function (rowId) { return this.selectedRows.indexOf(rowId) !== -1; },
      sortState: function (key) {
        if (this.sortBy !== key) { return 'none'; }
        return this.sortDirection === 'desc' ? 'descending' : 'ascending';
      },
      act: function (action, payload) {
        var self = this;
        var root = rootOf(this);
        this.busy = true;
        this.error = null;
        return send(bootData(), root, this.componentId, action, payload)
          .then(function (result) { return apply(result, root, self, self.componentId); })
          .catch(function (e) { self.error = e.message; })
          .then(function () { self.busy = false; self.remember(); });
      },
      sort: function (key) { return this.act('sort', { key: key }); },
      setPage: function (page) { return this.act('page', { page: page }); },
      toggleRow: function (rowId) {
        // optimistic local echo, then the server's word replaces it
        var i = this.selectedRows.indexOf(rowId);
        if (i === -1) { this.selectedRows.push(rowId); } else { this.selectedRows.splice(i, 1); }
        return this.act('toggle-row', { rowId: rowId });
      },
      clearSelection: function () { this.selectedRows = []; return this.act('clear-selection', {}); },
    };
  }

  // Write the server's freshly signed envelope back into the DOM so the NEXT action echoes it. A
  // component that renders its own dynamic lists client-side (Alpine x-for) does NOT swap its
  // outerHTML — so, unlike milpaDataTable, it must refresh the envelope in place or the next action
  // would replay a stale nonce (409).
  function refreshEnvelope(root, componentId, newState) {
    if (!newState) { return; }
    var el = envelopeScript(root, componentId);
    if (el) { el.textContent = newState; }
  }

  // milpaAutocomplete: server-search over the wire. The listbox and chips are rendered client-side
  // (x-for over `items`/`selected`), so each action updates state DATA from the response and keeps the
  // input focused — it never swaps the component. The server holds the truth: it re-validates every
  // action against the signed envelope and hands back the authoritative items/selection + a new envelope.
  function milpaAutocomplete(config) {
    var cfg = config || {};
    var initial = cfg.initialState || {};
    var multiple = cfg.multiple === true;
    function keyOf(item) { return String((item && (item.value != null ? item.value : item.label)) || ''); }
    function has(list, item) { return list.some(function (c) { return keyOf(c) === keyOf(item); }); }
    function without(items, selected) { return items.filter(function (i) { return !has(selected, i); }); }

    return {
      componentId: cfg.componentId || '',
      query: initial.query || '',
      selected: Array.isArray(initial.selected) ? initial.selected.slice() : [],
      items: Array.isArray(initial.items) ? initial.items.slice() : [],
      open: false,
      loading: false,
      error: null,
      activeIndex: -1,

      submit: function (action, payload, sync) {
        var self = this;
        var root = rootOf(this);
        this.loading = true;
        this.error = null;
        return send(bootData(), root, this.componentId, action, payload)
          .then(function (result) {
            if (result.status >= 200 && result.status < 300 && result.data) {
              return installAssets(result.data.assets).then(function () {
                refreshEnvelope(root, self.componentId, result.data.state);
                sync(result.data.data || {});
              });
            } else {
              self.error = (result.data && (result.data.message || result.data.error)) || ('live: HTTP ' + result.status);
            }
          })
          .catch(function (e) { self.error = e.message; })
          .then(function () { self.loading = false; });
      },

      search: function () {
        var self = this;
        this.open = true;
        return this.submit('search', { query: this.query }, function (data) {
          self.items = without(Array.isArray(data.items) ? data.items : [], self.selected);
          self.activeIndex = self.items.length ? 0 : -1;
        });
      },

      move: function (delta) {
        if (!this.items.length) { return; }
        var n = this.activeIndex + delta;
        this.activeIndex = n < 0 ? this.items.length - 1 : (n >= this.items.length ? 0 : n);
      },

      selectActive: function () {
        if (this.activeIndex >= 0 && this.items[this.activeIndex]) { this.select(this.items[this.activeIndex]); }
      },

      select: function (item) {
        var self = this;
        return this.submit('select', { item: item }, function (data) {
          if (Array.isArray(data.selected)) { self.selected = multiple ? data.selected : data.selected.slice(-1); }
          self.items = [];
          self.query = '';
          self.open = false;
        });
      },

      remove: function (item) {
        var self = this;
        return this.submit('remove', { item: item }, function (data) {
          if (Array.isArray(data.selected)) { self.selected = data.selected; }
        });
      },

      clear: function () {
        var self = this;
        return this.submit('clear', {}, function (data) {
          self.selected = Array.isArray(data.selected) ? data.selected : [];
          self.items = [];
          self.query = '';
        });
      },
    };
  }

  // milpaFieldRemote: input / textarea / select whose value is local (typing is zero-network) but which
  // VALIDATES on the server on blur — and applies whatever cross-component effects the handler declared. It
  // never swaps its own root (focus/value stay put); it refreshes its signed envelope and shows the server's
  // error. Use it (via `remote`) when a field must be authoritative, not just locally reactive.
  function milpaFieldRemote(config) {
    var cfg = config || {};
    var initial = cfg.initialState || {};
    return {
      componentId: cfg.componentId || '',
      value: initial.value != null ? initial.value : (cfg.value || ''),
      error: initial.error != null ? initial.error : null,
      busy: false,
      init: function () {},
      change: function (v) { this.value = v; },
      blur: function () {
        var self = this;
        var root = rootOf(this);
        this.busy = true;
        send(bootData(), root, this.componentId, 'blur', { value: this.value })
          .then(function (result) {
            if (result.status >= 200 && result.status < 300 && result.data) {
              return installAssets(result.data.assets).then(function () {
                refreshEnvelope(root, self.componentId, result.data.state);
                var data = result.data.data || {};
                if ('error' in data) { self.error = data.error; }
                applyEffects(result.data.effects);
              });
            } else {
              self.error = (result.data && (result.data.message || result.data.error)) || ('live: HTTP ' + result.status);
            }
          })
          .catch(function (e) { self.error = e.message; })
          .then(function () { self.busy = false; });
      },
    };
  }

  // Through the local runtime's registry: the data table REPLACES the local variant (the one explicit,
  // runtime-reserved override); the other two are new names and register like any plugin module would.
  runtime.register('milpaDataTable', milpaDataTable, { replace: true });
  runtime.register('milpaAutocomplete', milpaAutocomplete);
  runtime.register('milpaFieldRemote', milpaFieldRemote);
  runtime.register('milpaComponent', milpaComponent);

  // Merge, never replace: keep any transport a host set and the local runtime's storage helpers.
  window.MilpaLive = Object.assign(runtime, { send: send, bootData: bootData, __remoteLoaded: true });
}());
