/**
 * Milpa Client Runtime Entry — milpa-live.js
 *
 * The LOCAL runtime (ADR#9). It activates capabilities the server ALREADY declared in the HTML: it
 * registers, as Alpine factories, the components whose markup names them —
 * `milpaField`, `milpaCheckbox`, `milpaDataTable` — and reveals JS-only controls once Alpine is alive.
 *
 * Client Runtime Entry Contract (the frozen boundary is THE NETWORK): the local runtime NEVER makes
 * network requests, opens realtime channels, signs with HMAC, handles authentication, dispatches
 * remote events, or validates business rules. That is the Remote Runtime (milpa-live-remote.js +
 * LiveEndpoint), another layer. It MAY keep client-local UX memory in localStorage/sessionStorage —
 * what the human sorted or selected, so a reload remembers it — exactly as the remote runtime does:
 * that is UX memory, never truth. The native `<form method=post>` stays the authority.
 *
 * One owner (greenhouse decisions/0145): this runtime is the single home of the local Alpine
 * factories the milpa/live-web renderers emit as `x-data="milpaField(…)"` / `milpaCheckbox(…)` /
 * `milpaDataTable(…)`. It absorbed the richer factory set the components-lab had grown
 * (lab/milpa-components/public/milpa-live.js) so a page never ships its own copy. `milpaAutocomplete`
 * is inherently a server-search component (it fetches) and therefore belongs to the REMOTE runtime,
 * not here.
 *
 * One runtime per page (greenhouse decisions/0211): a plugin DECLARES its view — components, their client
 * behaviour, their CSS — and the host's single runtime reconciles it. This file is that runtime's registry:
 *   - `MilpaLive.register(name, factory)` binds an Alpine data factory. Before Alpine starts it is QUEUED and
 *     flushed inside this runtime's own `alpine:init` listener, AFTER the built-in factories (`milpaField`,
 *     `milpaCheckbox`, `milpaDataTable`); after Alpine has started it is handed to `Alpine.data` at once.
 *     Registering a name that is already registered THROWS — a plugin never overrides another, and never a
 *     built-in. The one exception is `register(name, factory, { replace: true })`, reserved for runtime
 *     modules (milpa-live-remote.js replaces `milpaDataTable` with its over-the-wire variant).
 *   - `MilpaLive.registered(name)` answers whether a factory is bound.
 *   - Loading this file twice is refused: the second copy warns and returns, so a guest that ships its own
 *     copy of the runtime never gets a second registry (and the host emits Alpine once — LiveBoot::html()).
 *   - Alpine already on the page when this file runs (someone emitted alpine.min.js first) is detected: the
 *     runtime warns and binds at once instead of queueing for an `alpine:init` that will never fire — so
 *     `registered()` never says yes for a factory Alpine never received. The `x-data` Alpine already walked
 *     has failed by then; the fix is the emit order, not the runtime.
 * Order, in full: styles → boot → milpa-live.js (built-ins bound) → milpa-live-remote.js (registers through
 * register()) → plugin modules (register()) → alpine.min.js (starts: store, computed, built-ins, then the
 * queue in registration order).
 *
 * No-build (ADR#10): hand-written, readable, served as-is. Alpine is vendored separately
 * (vendor/alpine.min.js) and loaded AFTER this file; both `defer`, so they run in document order.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency · Apache-2.0
 */
(function () {
  'use strict';

  // DOUBLE-LOAD GUARD: one runtime per page. A second copy (a guest shipping its own) is ignored, loudly.
  if (window.MilpaLive && window.MilpaLive.__loaded) {
    console.warn('[milpa-live] runtime loaded twice; ignoring the second copy');
    return;
  }

  var root = window.MilpaLive || {};

  // The factory registry (greenhouse decisions/0211). `factories` is the current binding per name; `queue`
  // is the order Alpine receives them in at `alpine:init` (built-ins first, then plugins as they registered);
  // `started` flips inside this runtime's own `alpine:init` listener, after which register() binds at once.
  var factories = {};
  var queue = [];
  var started = false;
  function ownsKey(object, key) { return Object.prototype.hasOwnProperty.call(object, key); }

  root.register = function (name, factory, options) {
    if (typeof name !== 'string' || name === '') { throw new Error('[milpa-live] register() needs a factory name'); }
    if (typeof factory !== 'function') { throw new Error('[milpa-live] register("' + name + '") needs a factory function'); }
    var replace = Boolean(options && options.replace === true);
    if (ownsKey(factories, name) && !replace) {
      throw new Error('[milpa-live] factory "' + name + '" is already registered; a plugin never overrides another (greenhouse decisions/0211)');
    }
    if (!ownsKey(factories, name) && !started) { queue.push(name); } // the queue only exists until alpine:init flushes it
    factories[name] = factory;
    if (started) { window.Alpine.data(name, factory); }
  };
  root.registered = function (name) { return ownsKey(factories, name); };

  // Client-local UX memory (no network): localStorage by default, chrome.storage.sync when a
  // component asks for it (a browser-extension surface). A broken store is an empty store.
  root.storage = {
    // Explicit ephemeral fields: server values win on remount; no browser memory is read or written.
    none: {
      async get() { return null; },
      async set() {},
      async remove() {},
    },
    local: {
      async get(key) {
        try { var value = window.localStorage.getItem(key); return value ? JSON.parse(value) : null; } catch (e) { return null; }
      },
      async set(key, value) {
        try { window.localStorage.setItem(key, JSON.stringify(value)); } catch (e) { /* ignore */ }
      },
      async remove(key) {
        try { window.localStorage.removeItem(key); } catch (e) { /* ignore */ }
      },
    },
    session: {
      async get(key) {
        try { var value = window.sessionStorage.getItem(key); return value ? JSON.parse(value) : null; } catch (e) { return null; }
      },
      async set(key, value) {
        try { window.sessionStorage.setItem(key, JSON.stringify(value)); } catch (e) { /* ignore */ }
      },
      async remove(key) {
        try { window.sessionStorage.removeItem(key); } catch (e) { /* ignore */ }
      },
    },
    chromeSync: {
      async get(key) {
        if (!globalThis.chrome || !globalThis.chrome.storage || !globalThis.chrome.storage.sync) { return null; }
        var data = await chrome.storage.sync.get({ [key]: null });
        return data[key] || null;
      },
      async set(key, value) {
        if (!globalThis.chrome || !globalThis.chrome.storage || !globalThis.chrome.storage.sync) { return; }
        await chrome.storage.sync.set({ [key]: value });
      },
      async remove(key) {
        if (!globalThis.chrome || !globalThis.chrome.storage || !globalThis.chrome.storage.sync) { return; }
        await chrome.storage.sync.remove(key);
      },
    },
  };

  root.pickStorage = function (name) {
    if (name === 'none') { return root.storage.none; }
    if (name === 'chrome.sync') { return root.storage.chromeSync; }
    if (name === 'session') { return root.storage.session; }
    return root.storage.local;
  };

  // Shared reactive signals (greenhouse decisions/0189): one named value, projected to every element that
  // reads it. A component (or the backend, via a `state` effect) sets a signal and everything bound to it
  // updates — one truth, many projections. Backed by an Alpine store (reactive); seeded from
  // `#milpa-live-signals`. Read anywhere with `x-text="$store.milpa['<key>']"`; set with
  // `MilpaLive.signal('<key>', value)`; read with `MilpaLive.signal('<key>')`.
  function readJson(id, fallback) {
    try { var el = document.getElementById(id); return el ? (JSON.parse(el.textContent || 'null') || fallback) : fallback; } catch (e) { return fallback; }
  }
  var signalSeed = readJson('milpa-live-signals', {});          // { key: value }
  var persistKeys = readJson('milpa-live-persist', []);         // ["key", …] — persisted to localStorage
  var computedDefs = readJson('milpa-live-computed', {});       // { key: { template: "{a} · {b}" } }
  var persistSet = {};
  persistKeys.forEach(function (k) { persistSet[k] = true; });
  function persistKey(key) { return 'milpa-signal:' + key; }
  // Restore persisted signals over the seed, so a remembered value wins on load.
  persistKeys.forEach(function (k) {
    try { var raw = window.localStorage.getItem(persistKey(k)); if (raw !== null) { signalSeed[k] = JSON.parse(raw); } } catch (e) { /* a broken memory is an empty memory */ }
  });

  root.signals = function () {
    return (window.Alpine && typeof window.Alpine.store === 'function') ? window.Alpine.store('milpa') : signalSeed;
  };
  root.signal = function (key, value) {
    var store = root.signals();
    if (arguments.length < 2) { return store ? store[key] : undefined; }
    if (store) { store[key] = value; }
    if (persistSet[key]) { try { window.localStorage.setItem(persistKey(key), JSON.stringify(value)); } catch (e) { /* ignore */ } }
    return value;
  };
  // A DERIVED signal: `key` is recomputed whenever the signals it reads change. Reading `store[dep]` inside
  // the effect makes Alpine track the dependency, so the computed re-runs (and re-projects) automatically.
  root.computed = function (key, fn) {
    if (!window.Alpine || typeof window.Alpine.effect !== 'function') { return; }
    window.Alpine.effect(function () { var store = window.Alpine.store('milpa'); if (store) { store[key] = fn(store); } });
  };

  window.MilpaLive = root;

  // milpaField: input / textarea / select. Local state + UX, remembered locally, zero network.
  function milpaField(options) {
    var opts = options || {};
    var storage = root.pickStorage(opts.storage || 'local');
    var persistKey = opts.persistKey || ('milpa:' + opts.componentId);
    var initial = opts.initialState || {};
    var hasOwn = function (object, key) { return Object.prototype.hasOwnProperty.call(object || {}, key); };

    return {
      value: hasOwn(initial, 'value') ? initial.value : (opts.value || ''),
      dirty: Boolean(initial.dirty),
      touched: Boolean(initial.touched),
      error: initial.error || null,

      async init() {
        if (!persistKey) { return; }
        var saved = await storage.get(persistKey);
        if (!saved) { return; }
        if (hasOwn(saved, 'value')) { this.value = saved.value == null ? '' : saved.value; }
        this.dirty = Boolean(saved.dirty);
        this.touched = Boolean(saved.touched);
        this.error = saved.error || null;
      },

      async persist() {
        if (!persistKey) { return; }
        await storage.set(persistKey, { value: this.value, dirty: this.dirty, touched: this.touched, error: this.error, componentId: opts.componentId, name: opts.name });
      },

      async change(value) { this.value = value; this.dirty = true; this.error = null; await this.persist(); },
      async blur() { this.touched = true; await this.persist(); },
      async reset(value) {
        this.value = value === undefined ? (opts.value || '') : value;
        this.dirty = false; this.touched = false; this.error = null;
        if (persistKey) { await storage.remove(persistKey); }
      },
    };
  }

  // milpaCheckbox: same shape with `checked` instead of `value`.
  function milpaCheckbox(options) {
    var opts = options || {};
    var storage = root.pickStorage(opts.storage || 'local');
    var persistKey = opts.persistKey || ('milpa:' + opts.componentId);
    var initial = opts.initialState || {};
    var hasOwn = function (object, key) { return Object.prototype.hasOwnProperty.call(object || {}, key); };

    return {
      checked: hasOwn(initial, 'checked') ? Boolean(initial.checked) : Boolean(opts.value),
      dirty: Boolean(initial.dirty),
      touched: Boolean(initial.touched),
      error: initial.error || null,

      async init() {
        if (!persistKey) { return; }
        var saved = await storage.get(persistKey);
        if (!saved) { return; }
        if (hasOwn(saved, 'checked')) { this.checked = Boolean(saved.checked); }
        this.dirty = Boolean(saved.dirty);
        this.touched = Boolean(saved.touched);
        this.error = saved.error || null;
      },

      async persist() {
        if (!persistKey) { return; }
        await storage.set(persistKey, { checked: this.checked, dirty: this.dirty, touched: this.touched, error: this.error, componentId: opts.componentId, name: opts.name });
      },

      async change(checked) { this.checked = Boolean(checked); this.dirty = true; this.error = null; await this.persist(); },
      async blur() { this.touched = true; await this.persist(); },
      async reset(checked) {
        this.checked = checked === undefined ? Boolean(opts.value) : Boolean(checked);
        this.dirty = false; this.touched = false; this.error = null;
        if (persistKey) { await storage.remove(persistKey); }
      },
    };
  }

  // milpaDataTable: selection, sort and paging kept LOCALLY (remembered in storage, no network).
  // The REMOTE runtime (milpa-live-remote.js) replaces this same name with the over-the-wire variant
  // through `register(name, factory, { replace: true })` when a page loads it; on a local-only page,
  // this is the whole behaviour.
  function milpaDataTable(options) {
    var opts = options || {};
    var storage = root.pickStorage(opts.storage || 'local');
    var persistKey = opts.persistKey || ('milpa:' + opts.componentId);
    var initial = opts.initialState || {};
    var normalizeRows = function (rows) { return Array.isArray(rows) ? rows.map(function (row) { return String(row); }) : []; };

    return {
      selectedRows: normalizeRows(initial.selectedRows),
      sortBy: initial.sortBy || '',
      sortDirection: initial.sortDirection || 'asc',
      page: initial.page || 1,
      error: initial.error || null,

      async init() {
        if (!persistKey) { return; }
        var saved = await storage.get(persistKey);
        if (!saved) { return; }
        this.selectedRows = normalizeRows(saved.selectedRows);
        this.sortBy = saved.sortBy || '';
        this.sortDirection = saved.sortDirection || 'asc';
        this.page = saved.page || 1;
      },

      async persist() {
        if (!persistKey) { return; }
        await storage.set(persistKey, { selectedRows: this.selectedRows, sortBy: this.sortBy, sortDirection: this.sortDirection, page: this.page, componentId: opts.componentId, name: opts.name });
      },

      isSelected(rowId) { return this.selectedRows.includes(String(rowId)); },

      async toggleRow(rowId) {
        var id = String(rowId);
        this.selectedRows = this.isSelected(id) ? this.selectedRows.filter(function (c) { return c !== id; }) : this.selectedRows.concat([id]);
        await this.persist();
      },

      async clearSelection() {
        this.selectedRows = [];
        if (persistKey) { await storage.remove(persistKey); }
      },

      async sort(key) {
        var nextKey = String(key);
        this.sortDirection = this.sortBy === nextKey && this.sortDirection === 'asc' ? 'desc' : 'asc';
        this.sortBy = nextKey;
        await this.persist();
      },

      sortMark(key) {
        if (this.sortBy !== String(key)) { return ''; }
        return this.sortDirection === 'asc' ? '^' : 'v';
      },

      sortState(key) {
        if (this.sortBy !== String(key)) { return 'none'; }
        return this.sortDirection === 'desc' ? 'descending' : 'ascending';
      },
    };
  }

  // The built-ins are bound through the same register() a plugin uses — so they are first in the queue,
  // and a plugin that picks one of their names fails loudly instead of silently shadowing it.
  root.register('milpaField', milpaField);
  root.register('milpaCheckbox', milpaCheckbox);
  root.register('milpaDataTable', milpaDataTable);

  // Hand the factories to Alpine when it boots (this file loads before alpine.min.js): store, computed
  // signals, then every registered factory in registration order — built-ins first, plugins after.
  function onAlpineInit() {
    // The shared signals store — reactive, seeded from the page. Every `$store.milpa['<key>']` binding
    // tracks it, so setting one signal projects to all of them.
    if (!window.Alpine.store('milpa')) { window.Alpine.store('milpa', signalSeed); }
    // Declarative derived signals: each `key` recomputes its template (`{dep}` → the dep's value) reactively.
    Object.keys(computedDefs).forEach(function (key) {
      var template = (computedDefs[key] && computedDefs[key].template) || '';
      root.computed(key, function (store) {
        return template.replace(/\{([^}]+)\}/g, function (_, k) { var v = store[k.trim()]; return v == null ? '' : v; });
      });
    });
    queue.forEach(function (name) { window.Alpine.data(name, factories[name]); });
    queue = [];
    started = true;
  }

  if (window.Alpine && typeof window.Alpine.data === 'function') {
    // Alpine got here first (a host or a guest emitted alpine.min.js before this runtime): its `alpine:init`
    // has fired, or will without us, so a queued registration would never flush while registered() said yes.
    // Bind now, loudly. The factories reach Alpine; any `x-data` it already walked has failed, and the fix is
    // the emit order — LiveBoot::html() puts Alpine last (greenhouse decisions/0211).
    console.warn('[milpa-live] Alpine loaded before the runtime; binding factories at once — emit alpine.min.js last (LiveBoot::html())');
    onAlpineInit();
  } else {
    document.addEventListener('alpine:init', onAlpineInit);
  }

  root.__loaded = true;
}());
