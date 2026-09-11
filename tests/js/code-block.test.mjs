/**
 * code-block — the copy button, clicked.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency · Apache-2.0
 *
 * The renderer's test already proves the payload carries no prompt. What only a click can prove is the
 * wiring: one delegated listener, the payload read from the attribute and never from the DOM, the
 * status said and then taken back, and the fallback when the clipboard is not available — «consola sin
 * clic no prueba cableado» (greenhouse decisions/0272, this component 0299).
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import vm from 'node:vm';

const SCRIPT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../resources/components/code-block.js');

/** A button as the RENDERER printed it: the payload is an attribute, the status slot is a child. */
function button(payload) {
  const said = { textContent: '' };
  const attrs = { 'data-milpa-copy': payload };
  const el = {
    getAttribute: (n) => (n in attrs ? attrs[n] : null),
    setAttribute: (n, v) => { attrs[n] = v; },
    removeAttribute: (n) => { delete attrs[n]; },
    querySelector: (sel) => (sel === '.copy-said' ? said : null),
    // Matched by the PAYLOAD ATTRIBUTE, the way the script matches it: the listener is on `document`,
    // where a class selector like `.copy` would answer for anybody else's button too.
    closest: (sel) => (sel === '[data-milpa-copy]' && 'data-milpa-copy' in attrs ? el : null),
    attrs,
    said,
  };

  return el;
}

/** A page carrying the script, with a clipboard that either works or is absent. */
function page({ secure = true, clipboard = true } = {}) {
  const listeners = {};
  const written = [];
  const timers = [];
  const sandbox = {
    console: { warn() {}, log() {}, error() {} },
    document: {
      addEventListener(type, fn) { (listeners[type] ||= []).push(fn); },
      createElement: () => ({ setAttribute() {}, select() {}, remove() {}, style: {}, value: '' }),
      body: { appendChild() {} },
      execCommand: (kind) => { written.push('execCommand:' + kind); return true; },
    },
    navigator: clipboard ? { clipboard: { writeText: async (t) => { written.push(t); } } } : {},
    setTimeout: (fn) => { timers.push(fn); return timers.length; },
  };
  sandbox.window = sandbox;
  sandbox.isSecureContext = secure;
  vm.createContext(sandbox);
  vm.runInContext(readFileSync(SCRIPT, 'utf8'), sandbox);

  return {
    click: async (target) => { for (const fn of listeners.click || []) { await fn({ target }); } },
    written,
    runTimers: () => timers.forEach((fn) => fn()),
    listenerCount: (listeners.click || []).length,
  };
}

test('one delegated listener carries every block on the page', () => {
  const p = page();
  assert.equal(p.listenerCount, 1, 'the document keeps one, so blocks that arrive later are covered too');
});

test('a click copies the attribute payload, not anything read off the DOM', async () => {
  const p = page();
  const b = button('php bin/coa list\nphp bin/coa serve');
  await p.click(b);
  assert.deepEqual(p.written, ['php bin/coa list\nphp bin/coa serve']);
  assert.equal(b.said.textContent, 'copied');
  assert.ok('data-copied' in b.attrs, 'and the button says so while it stands');
});

test('the status is taken back, because one that stays is one nobody reads twice', async () => {
  const p = page();
  const b = button('php bin/coa serve');
  await p.click(b);
  p.runTimers();
  assert.equal(b.said.textContent, '');
  assert.ok(!('data-copied' in b.attrs));
});

test('without a secure context it falls back and still says something', async () => {
  const p = page({ secure: false, clipboard: false });
  const b = button('php bin/coa serve');
  await p.click(b);
  assert.deepEqual(p.written, ['execCommand:copy'], 'the old selection dance rather than nothing');
  assert.equal(b.said.textContent, 'copied');
});

test('a click anywhere else does nothing at all', async () => {
  const p = page();
  await p.click({ closest: () => null });
  assert.deepEqual(p.written, []);
});

test('a button with an empty payload copies nothing and claims nothing', async () => {
  const p = page();
  const b = button('');
  await p.click(b);
  assert.deepEqual(p.written, []);
  assert.equal(b.said.textContent, '', 'no «copied» for a copy that did not happen');
});
