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
 * No-build (ADR#10): hand-written, readable, served as-is. Loads after milpa-live.js and before
 * alpine.min.js; all three `defer`, so they run in document order.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency · Apache-2.0
 */
(function () {
  'use strict';

  function bootData() {
    var el = document.getElementById('milpa-live-boot');
    if (!el) { return null; }
    try { return JSON.parse(el.textContent || '{}'); } catch (e) { return null; }
  }

  function envelopeOf(root, componentId) {
    var el = root.querySelector('script[data-milpa-state="' + componentId + '"]');
    return el ? el.textContent : null;
  }

  function storageFor(kind) {
    try { return kind === 'session' ? window.sessionStorage : window.localStorage; } catch (e) { return null; }
  }

  // Send one declared action to the endpoint and hand back the parsed response.
  function send(boot, componentRoot, componentId, action, payload) {
    var envelope = envelopeOf(componentRoot, componentId);
    if (!boot || !envelope) {
      return Promise.reject(new Error('live: no boot data or no signed state on this component'));
    }
    var headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
    if (boot.authorization) { headers['Authorization'] = boot.authorization; }
    return fetch(boot.endpoint, {
      method: 'POST',
      headers: headers,
      credentials: 'same-origin',
      body: JSON.stringify({
        action: action,
        payload: payload || {},
        state: envelope,
        sessionId: boot.sessionId,
        csrfToken: boot.csrfToken,
      }),
    }).then(function (r) {
      return r.json().then(function (data) { return { status: r.status, data: data }; });
    });
  }

  // Apply the server's answer: the re-rendered HTML replaces the component root (the new root
  // carries the new signed envelope and its own x-data, so Alpine mounts it fresh), or the
  // component shows the error the server returned.
  function apply(result, componentRoot, self) {
    if (result.status >= 200 && result.status < 300 && result.data && result.data.html) {
      componentRoot.outerHTML = result.data.html;
      return;
    }
    var err = (result.data && (result.data.message || result.data.error)) || ('live: HTTP ' + result.status);
    self.error = err;
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
        var root = this.$root;
        this.busy = true;
        this.error = null;
        return send(bootData(), root, this.componentId, action, payload)
          .then(function (result) { apply(result, root, self); })
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

  document.addEventListener('alpine:init', function () {
    window.Alpine.data('milpaDataTable', milpaDataTable);
  });

  window.MilpaLive = { send: send, bootData: bootData };
}());
