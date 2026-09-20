/**
 * The dispatch-after-rebind contract (greenhouse #49 / decisions/0389), measured by execution.
 *
 * A component's own re-render replaces its root's `outerHTML`; Alpine re-binds the NEW root's `x-on:`
 * listeners ASYNCHRONOUSLY, from the MutationObserver microtask the swap queues. A `dispatch` effect
 * fired synchronously in the same task reaches the freshly-swapped element BEFORE its listener exists
 * and is lost — the "seed a node, then navigate to it" click that silently did nothing while the write
 * succeeded. The remote runtime must hold `dispatch` effects until after that rebind.
 *
 * This models the exact ordering with node's real async primitives: the swapped-in root attaches its
 * `milpa:created` listener on a `queueMicrotask` (Alpine's async rebind), and the runtime — driven
 * through the real `milpaComponent().act()` -> `apply()` -> `applyEffects()` path — must still deliver
 * the dispatch. The negative control fires the SAME event synchronously and shows it is lost, so the
 * positive result is the fix, not a tautology.
 *
 * Run: `node --test 'tests/js/**\/*.test.mjs'` (Node >= 22; node:test is built in, nothing to install).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency · Apache-2.0
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import vm from 'node:vm';

const resources = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../resources');
const LOCAL = path.join(resources, 'milpa-live.js');
const REMOTE = path.join(resources, 'milpa-live-remote.js');

// A minimal CustomEvent over node's global Event: the runtime does `new CustomEvent('milpa:x', {detail})`
// and `el.dispatchEvent(ce)`; a real EventTarget listener then reads `ce.detail`.
class CustomEventShim extends Event {
  constructor(type, init) { super(type, init); this.detail = (init && init.detail) || null; }
}

// Build the scene: a page whose document resolves the ONE component-id and the ONE state-script the
// runtime asks for, whose component root re-binds its listener LATE (on a microtask) when swapped —
// exactly as Alpine does — and that has NO requestAnimationFrame, so `afterRebind` takes its setTimeout
// path. Returns the captured `milpaComponent` factory, the first root, and the fired-hrefs array.
function scene() {
  const stateScript = { textContent: '<milpa-state security="signed">envelope</milpa-state>' };
  const boot = { textContent: JSON.stringify({ endpoint: '/live', sessionId: 's', csrfToken: 'c' }) };
  const fired = [];
  let currentRoot;

  function makeRoot() {
    const el = new EventTarget();
    el.querySelector = (sel) => (sel.indexOf('data-milpa-state') !== -1 ? stateScript : null);
    el.contains = () => true;
    Object.defineProperty(el, 'outerHTML', {
      configurable: true,
      set() {
        const next = makeRoot();
        currentRoot = next;
        // Alpine binds the new root's x-on:milpa:created asynchronously, from the observer microtask.
        queueMicrotask(() => next.addEventListener('milpa:created', (ev) => fired.push(ev.detail && ev.detail.href)));
      },
    });
    return el;
  }
  currentRoot = makeRoot();
  const firstRoot = currentRoot;

  const documentListeners = {};
  const sandbox = {
    console: { warn() {}, log() {}, error() {} },
    queueMicrotask,
    setTimeout,
    Event,
    CustomEvent: CustomEventShim,
    EventTarget,
    addEventListener() {},
    document: {
      addEventListener(type, fn) { (documentListeners[type] || (documentListeners[type] = [])).push(fn); },
      getElementById: (id) => (id === 'milpa-live-boot' ? boot : null),
      querySelector: (sel) => (sel.indexOf('data-milpa-component-id') !== -1 ? currentRoot : null),
    },
  };
  sandbox.window = sandbox;
  vm.createContext(sandbox);
  vm.runInContext(readFileSync(LOCAL, 'utf8'), sandbox, { filename: LOCAL });
  vm.runInContext(readFileSync(REMOTE, 'utf8'), sandbox, { filename: REMOTE });

  // Start Alpine the documented way: a stub records every Alpine.data call, then alpine:init fires and
  // the queued factories register. This is the same mechanism the registry-contract test uses.
  const calls = [];
  sandbox.Alpine = { data: (name, factory) => calls.push([name, factory]), store() {}, directive() {}, magic() {}, nextTick(cb) { cb(); } };
  (documentListeners['alpine:init'] || []).forEach((fn) => fn());
  const entry = calls.find((c) => c[0] === 'milpaComponent');

  return { sandbox, factory: entry ? entry[1] : null, firstRoot, fired };
}

test('#49 — a dispatch effect is delivered AFTER the swapped root is re-bound (deferred, not lost)', async () => {
  const s = scene();
  assert.ok(s.factory, 'milpaComponent factory is registered');

  s.sandbox.window.MilpaLive.transport = () => Promise.resolve({
    status: 200,
    data: {
      html: '<form data-milpa-component-id="comp-1"></form>',
      effects: [{ type: 'dispatch', to: 'comp-1', event: 'created', payload: { href: '/ui/new' } }],
    },
  });

  const c = s.factory({ componentId: 'comp-1' });
  c.$root = s.firstRoot;
  await c.act('create', {});
  await new Promise((r) => setTimeout(r, 5)); // let afterRebind's setTimeout run after the rebind microtask

  assert.deepEqual(s.fired, ['/ui/new'], 'the re-rendered root received milpa:created with its href');
});

test('control — a SYNCHRONOUS dispatch to the same late-binding root is LOST (the race is real)', async () => {
  // The pre-fix behaviour, reproduced directly: swap the root (schedules a microtask rebind) and
  // dispatch in the SAME task. The listener does not exist yet, so the event is lost — which is exactly
  // why the runtime must defer. This proves the positive test is the fix, not an always-green assertion.
  let current = new EventTarget();
  const fired = [];
  const swap = () => {
    const next = new EventTarget();
    current = next;
    queueMicrotask(() => next.addEventListener('milpa:created', (e) => fired.push(e.detail.href)));
  };
  swap();
  current.dispatchEvent(new CustomEventShim('milpa:created', { detail: { href: '/ui/new' } }));
  await new Promise((r) => setTimeout(r, 5));
  assert.deepEqual(fired, [], 'synchronous dispatch reached the element before Alpine re-bound it — lost');
});
