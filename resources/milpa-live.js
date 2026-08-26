/**
 * Milpa Client Runtime Entry — milpa-live.js
 *
 * The LOCAL runtime (ADR#9). It exists only to activate capabilities the server ALREADY declared in
 * the HTML: it registers the Alpine factories the form primitives name (`milpaField`, `milpaCheckbox`)
 * and reveals JS-only controls once Alpine is alive.
 *
 * Client Runtime Entry Contract (frozen): it NEVER makes network requests, opens realtime channels,
 * signs with HMAC, handles authentication, dispatches remote events, persists anything, or validates
 * business rules. That is the Remote Runtime (milpa-live-remote.js + LiveEndpoint), another layer.
 * The native `<form method=post>` stays the authority; this is local UX only.
 *
 * No-build (ADR#10): hand-written, readable, served as-is. Alpine is vendored separately
 * (vendor/alpine.min.js) and loaded AFTER this file; both `defer`, so they run in document order.
 *
 * Extracted verbatim in behaviour from the monorepo (teamx/public/milpa-live.js) into milpa/live-web,
 * the package that owns the markup these factories activate (greenhouse decisions/0083).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency · Apache-2.0
 */
(function () {
  'use strict';

  // milpaField: input / textarea / select. Local state + UX, zero network.
  function milpaField(config) {
    var cfg = config || {};
    var initial = cfg.initialState || {};
    return {
      value: initial.value != null ? initial.value : (cfg.value != null ? cfg.value : ''),
      error: initial.error != null ? initial.error : null,
      dirty: !!initial.dirty,
      touched: !!initial.touched,
      init: function () {},
      // typing clears the server-rendered error and marks dirty — purely local
      change: function (v) { this.value = v; this.dirty = true; this.error = null; },
      blur: function () { this.touched = true; },
    };
  }

  // milpaCheckbox: same, with `checked` instead of `value`.
  function milpaCheckbox(config) {
    var cfg = config || {};
    var initial = cfg.initialState || {};
    return {
      checked: initial.checked != null ? initial.checked : !!cfg.value,
      error: initial.error != null ? initial.error : null,
      dirty: !!initial.dirty,
      touched: !!initial.touched,
      init: function () {},
      change: function (v) { this.checked = !!v; this.dirty = true; this.error = null; },
      blur: function () { this.touched = true; },
    };
  }

  // Register the factories BEFORE Alpine boots (this file loads before alpine.min.js).
  document.addEventListener('alpine:init', function () {
    window.Alpine.data('milpaField', milpaField);
    window.Alpine.data('milpaCheckbox', milpaCheckbox);
  });

  // Reveal JS-only controls (e.g. the topbar toggle) EXACTLY when Alpine finished initialising and
  // their @click already work — a visible control must be executable (ADR#5). The host CSS hides
  // `.mui-topbar__nav-toggle` unless `html.milpa-js`. It NEVER hides server-truth content (ADR#8):
  // only controls that would do nothing without JS.
  document.addEventListener('alpine:initialized', function () {
    document.documentElement.classList.add('milpa-js');
  });
}());
