/**
 * The client runtime's registry contract (greenhouse decisions/0211), measured by execution: a stub page
 * loads the shipped files verbatim (no build), a stub Alpine records what it is handed, and each rule of
 * "one runtime per page" is asserted — the double-load guard, the queue flushed after the built-ins, the
 * no-override error, the remote runtime's explicit replace path, the fail-loud without the local runtime,
 * and the CSRF refresh stored back into the boot.
 *
 * Run: `node --test 'tests/js/**\/*.test.mjs'` — or `npm test` (Node ≥ 22; node:test is built in, nothing to install).
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

// A page: `window` is the context itself, `document` only knows the two things the runtimes ask at load
// time (listeners and elements by id), `console.warn` is recorded, and Alpine is absent until started.
function page(elements = {}) {
  const listeners = {};
  const warnings = [];
  const sandbox = {
    console: { warn: (m) => warnings.push(m), log() {}, error() {} },
    document: {
      addEventListener(type, fn) { (listeners[type] ||= []).push(fn); },
      getElementById(id) { return elements[id] || null; },
      querySelector() { return null; },
    },
  };
  sandbox.window = sandbox;
  vm.createContext(sandbox);
  const load = (file) => vm.runInContext(readFileSync(file, 'utf8'), sandbox, { filename: file });
  // A stub Alpine: it records every `Alpine.data` call in order.
  const stubAlpine = () => {
    const calls = [];
    const stores = {};
    sandbox.Alpine = {
      data(name, factory) { calls.push([name, factory]); },
      store(name, value) { if (value !== undefined) { stores[name] = value; } return stores[name]; },
      effect() {},
    };
    return calls;
  };
  // Start it the documented way: the stub appears, then `alpine:init` fires.
  const startAlpine = () => {
    const calls = stubAlpine();
    (listeners['alpine:init'] || []).forEach((fn) => fn());
    return calls;
  };
  // The wrong way round: Alpine is already on the page (and started) before the runtime loads.
  const presetAlpine = () => stubAlpine();
  return { sandbox, listeners, warnings, load, startAlpine, presetAlpine };
}

const throwsMatching = (fn, pattern) => {
  let thrown = null;
  try { fn(); } catch (e) { thrown = e; }
  assert.ok(thrown, 'expected an error');
  assert.match(String(thrown.message), pattern);
};

test('the second copy of the runtime is refused with a warning, and the first registry stands', () => {
  const p = page();
  p.load(LOCAL);
  const first = p.sandbox.window.MilpaLive;
  assert.equal(first.__loaded, true);

  p.load(LOCAL);

  assert.equal(p.warnings.length, 1);
  assert.match(p.warnings[0], /\[milpa-live\] runtime loaded twice; ignoring the second copy/);
  assert.equal(p.sandbox.window.MilpaLive, first, 'the same registry object');
  assert.equal((p.listeners['alpine:init'] || []).length, 1, 'one alpine:init listener, not two');
});

test('built-ins bind first, queued plugin factories follow in registration order, and after start a factory binds at once', () => {
  const p = page();
  p.load(LOCAL);
  const live = p.sandbox.window.MilpaLive;
  const a = () => ({ a: true });
  const b = () => ({ b: true });

  live.register('pluginA', a);
  live.register('pluginB', b);
  assert.equal(live.registered('pluginA'), true);
  assert.equal(live.registered('nope'), false);

  const calls = p.startAlpine();
  assert.deepEqual(calls.map(([name]) => name), ['milpaField', 'milpaCheckbox', 'milpaDataTable', 'pluginA', 'pluginB']);
  assert.equal(calls[3][1], a);
  assert.equal(calls[4][1], b);

  const c = () => ({ c: true });
  live.register('pluginC', c);
  assert.equal(calls.length, 6, 'after start, register() hands the factory to Alpine immediately');
  assert.deepEqual(calls[5], ['pluginC', c]);
});

test('registering a name that is already bound throws, naming it — built-ins included', () => {
  const p = page();
  p.load(LOCAL);
  const live = p.sandbox.window.MilpaLive;

  throwsMatching(() => live.register('milpaField', () => ({})), /"milpaField" is already registered/);
  live.register('pluginA', () => ({}));
  throwsMatching(() => live.register('pluginA', () => ({})), /"pluginA" is already registered/);
  throwsMatching(() => live.register('', () => ({})), /needs a factory name/);
  throwsMatching(() => live.register('pluginX', 'not a function'), /needs a factory function/);

  const calls = p.startAlpine();
  assert.deepEqual(calls.map(([name]) => name), ['milpaField', 'milpaCheckbox', 'milpaDataTable', 'pluginA'], 'nothing was overridden, nothing was double-bound');
});

test('the remote runtime replaces milpaDataTable through the explicit replace path and registers its other factories', () => {
  const p = page();
  p.load(LOCAL);
  p.load(REMOTE);
  const live = p.sandbox.window.MilpaLive;
  assert.equal(live.__loaded, true, 'the remote merged into the local registry instead of replacing it');
  assert.equal(typeof live.send, 'function');
  assert.equal(typeof live.register, 'function');

  const calls = p.startAlpine();
  assert.deepEqual(
    calls.map(([name]) => name),
    ['milpaField', 'milpaCheckbox', 'milpaDataTable', 'milpaAutocomplete', 'milpaFieldRemote', 'milpaComponent'],
    'the replaced name keeps its slot; new names queue after the built-ins',
  );
  const tables = calls.filter(([name]) => name === 'milpaDataTable');
  assert.equal(tables.length, 1, 'bound once — the replacement, not both');
  const table = tables[0][1]({ componentId: 't' });
  assert.equal(typeof table.act, 'function', 'the over-the-wire variant (it has act())');
});

test('the second copy of the remote runtime is refused with a warning; the replacement happened once', () => {
  const p = page();
  p.load(LOCAL);
  p.load(REMOTE);
  const live = p.sandbox.window.MilpaLive;
  assert.equal(live.__remoteLoaded, true);

  p.load(REMOTE); // a guest shipping its own copy at another URL — must not throw on milpaAutocomplete

  assert.equal(p.warnings.length, 1);
  assert.match(p.warnings[0], /\[milpa-live-remote\] runtime loaded twice; ignoring the second copy/);
  assert.equal(p.sandbox.window.MilpaLive, live, 'the same registry object');
  const calls = p.startAlpine();
  assert.deepEqual(
    calls.map(([name]) => name),
    ['milpaField', 'milpaCheckbox', 'milpaDataTable', 'milpaAutocomplete', 'milpaFieldRemote', 'milpaComponent'],
    'each factory bound once',
  );
});

test('Alpine already on the page: the runtime warns, binds at once, and registered() never lies', () => {
  const p = page();
  const calls = p.presetAlpine();

  p.load(LOCAL);
  const live = p.sandbox.window.MilpaLive;

  assert.equal(p.warnings.length, 1);
  assert.match(p.warnings[0], /\[milpa-live\] Alpine loaded before the runtime/);
  assert.deepEqual(calls.map(([name]) => name), ['milpaField', 'milpaCheckbox', 'milpaDataTable'], 'the built-ins reached Alpine');
  assert.equal((p.listeners['alpine:init'] || []).length, 0, 'no listener waiting for an alpine:init that will never fire');
  assert.notEqual(p.sandbox.Alpine.store('milpa'), undefined, 'the signals store was seeded');

  const late = () => ({ late: true });
  live.register('late', late);
  assert.equal(live.registered('late'), true);
  assert.deepEqual(calls[3], ['late', late], 'handed to Alpine at once, not queued');
});

test('the remote runtime without the local one fails loudly', () => {
  const p = page();
  throwsMatching(() => p.load(REMOTE), /the local runtime \(milpa-live\.js\) must load first/);
  assert.equal(p.sandbox.window.MilpaLive, undefined);
});

test('a returned csrfToken is stored into the boot, and the request carries the boot session id — never a cookie', async () => {
  const boot = { textContent: JSON.stringify({ endpoint: '/live', sessionId: 'live-abc', csrfToken: 'old-token' }) };
  const p = page({ 'milpa-live-boot': boot });
  p.load(LOCAL);
  p.load(REMOTE);
  const live = p.sandbox.window.MilpaLive;

  const sent = [];
  live.transport = (b, body) => { sent.push(body); return { status: 200, data: { ok: true, csrfToken: 'fresh-token' } }; };
  const root = { querySelector: () => ({ textContent: '<milpa-state security="signed"/>' }) };

  const result = await live.send(live.bootData(), root, 'c-1', 'go', { x: 1 });

  assert.equal(result.status, 200);
  assert.equal(sent.length, 1);
  assert.equal(sent[0].sessionId, 'live-abc', 'the session id comes from the boot payload');
  assert.equal(sent[0].csrfToken, 'old-token');
  assert.equal(sent[0].state, '<milpa-state security="signed"/>');
  assert.equal(JSON.parse(boot.textContent).csrfToken, 'fresh-token', 'the boot now carries the refreshed token');
  assert.equal(live.bootData().sessionId, 'live-abc', 'the session id is untouched');

  // A response without a token leaves the boot alone.
  live.transport = () => ({ status: 200, data: { ok: true } });
  await live.send(live.bootData(), root, 'c-1', 'go', {});
  assert.equal(JSON.parse(boot.textContent).csrfToken, 'fresh-token');
});

function applicationComponent() {
  const boot = { textContent: JSON.stringify({ endpoint: '/live', sessionId: 'page', csrfToken: 'csrf' }) };
  const p = page({ 'milpa-live-boot': boot });
  p.load(LOCAL);
  p.load(REMOTE);
  const factory = p.startAlpine().find(([name]) => name === 'milpaComponent')[1];
  const component = factory({ componentId: 'task' });
  const envelope = { textContent: '<signed initial/>' };
  const root = { querySelector: () => envelope, contains: () => false, outerHTML: '<task open/>' };
  component.$root = root;
  return { p, component, root, envelope, boot };
}

test('an application component sends its declared action and applies the server HTML without another client', async () => {
  const { p, component, root, boot } = applicationComponent();
  const sent = [];
  p.sandbox.MilpaLive.transport = (b, body) => {
    sent.push(body);
    return { status: 200, data: { html: '<task complete/>', state: '<signed next/>', csrfToken: 'fresh' } };
  };
  await component.act('toggle', { id: 'one' });
  assert.equal(sent.length, 1);
  assert.equal(sent[0].action, 'toggle');
  assert.equal(sent[0].payload.id, 'one');
  assert.equal(sent[0].state, '<signed initial/>');
  assert.equal(root.outerHTML, '<task complete/>');
  assert.equal(JSON.parse(boot.textContent).csrfToken, 'fresh');
  assert.equal(component.busy, false);
  assert.equal(component.error, null);
});

test('a generic action refuses double submission while its signed envelope is in flight', async () => {
  const { p, component } = applicationComponent();
  let finish;
  let calls = 0;
  p.sandbox.MilpaLive.transport = () => { calls++; return new Promise(resolve => { finish = resolve; }); };
  const pending = component.act('toggle', {});
  await component.act('toggle', {});
  assert.equal(calls, 1);
  assert.equal(component.busy, true);
  finish({ status: 200, data: { html: '<task complete/>' } });
  await pending;
  assert.equal(component.busy, false);
});

test('refused and failed generic actions preserve the UI and report their error', async () => {
  const { p, component, root } = applicationComponent();
  p.sandbox.MilpaLive.transport = () => ({ status: 403, data: { message: 'Scope refused' } });
  await component.act('toggle', {});
  assert.equal(component.error, 'Scope refused');
  assert.equal(root.outerHTML, '<task open/>');
  p.sandbox.MilpaLive.transport = () => Promise.reject(new Error('Offline'));
  await component.act('toggle', {});
  assert.equal(component.error, 'Offline');
  assert.equal(component.busy, false);
});

test('a successful generic action without replacement HTML still renews its signed envelope', async () => {
  const { p, component, envelope } = applicationComponent();
  p.sandbox.MilpaLive.transport = () => ({ status: 200, data: { state: '<signed next/>' } });
  await component.act('toggle', {});
  assert.equal(envelope.textContent, '<signed next/>');
});


test('storage none keeps edits ephemeral and remounts the server value without touching browser stores', async () => {
  const p = page();
  let reads = 0, writes = 0, removals = 0;
  p.sandbox.localStorage = {
    getItem() { reads++; return JSON.stringify({ value: 'obsolete draft', checked: true }); },
    setItem() { writes++; },
    removeItem() { removals++; },
  };
  p.load(LOCAL);
  const factories = Object.fromEntries(p.startAlpine());
  for (const [factory, key, value, edit] of [['milpaField', 'value', 'server title', 'local edit'], ['milpaCheckbox', 'checked', false, true]]) {
    const options = { componentId: 'form', storage: 'none', initialState: { [key]: value } };
    const field = factories[factory](options);
    await field.init();
    assert.equal(field[key], value);
    await field.change(edit);
    await field.blur();
    assert.equal(field[key], edit, 'typing still changes local UI state');
    const remount = factories[factory](options);
    await remount.init();
    assert.equal(remount[key], value, 'the server wins on remount');
    await field.reset(value);
    assert.equal(field[key], value);
  }
  assert.deepEqual([reads, writes, removals], [0, 0, 0]);
  const remembered = factories.milpaField({ componentId: 'form' });
  await remembered.init();
  assert.equal(remembered.value, 'obsolete draft', 'positive control: default local memory remains active');
  await remembered.change('remember me');
  await remembered.reset();
  assert.deepEqual([reads, writes, removals], [1, 1, 1]);
});
