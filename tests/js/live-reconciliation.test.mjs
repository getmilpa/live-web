/**
 * Dynamic component reconciliation: assets introduced by a server render travel once, and replacing a
 * component keeps the human's active field. The fake DOM implements only the browser calls the shipped
 * runtime makes; the runtime itself is loaded verbatim and driven through milpaComponent().act().
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

function scene() {
  const appended = [];
  const destroyed = [];
  const listeners = {};
  const boot = { textContent: JSON.stringify({ endpoint: '/live', sessionId: 's', csrfToken: 'c' }) };
  const state = { textContent: '<signed/>', remove() {} };
  const initialMarker = { getAttribute: (name) => name === 'data-milpa-components' ? 'todo-board@1 input@1' : null };
  const messages = { textContent: JSON.stringify({ 'todo-board.title': 'Tasks' }) };
  let currentRoot;

  const document = {
    activeElement: null,
    head: { appendChild: append },
    body: { appendChild: append },
    addEventListener(type, fn) { (listeners[type] ||= []).push(fn); },
    getElementById(id) {
      if (id === 'milpa-live-boot') return boot;
      if (id === 'milpa-messages') return messages;
      return null;
    },
    querySelector(selector) {
      if (selector.includes('data-milpa-component-id')) return currentRoot;
      if (selector.includes('data-milpa-state')) return state;
      return null;
    },
    querySelectorAll(selector) {
      return selector === '[data-milpa-components]' ? [initialMarker] : [];
    },
    createElement(tagName) {
      return {
        tagName: tagName.toUpperCase(),
        attributes: {},
        textContent: '',
        setAttribute(name, value) { this.attributes[name] = String(value); },
        getAttribute(name) { return this.attributes[name] || null; },
      };
    },
  };

  function append(node) {
    appended.push(node);
    if (node.tagName === 'SCRIPT' && node.attributes.src && typeof node.onload === 'function') {
      queueMicrotask(node.onload);
    }
    return node;
  }

  function makeField(ownerId) {
    return {
      id: '',
      name: 'title',
      type: 'text',
      selectionStart: 0,
      selectionEnd: 0,
      closest: () => ({ getAttribute: () => ownerId }),
      focus() { document.activeElement = this; },
      setSelectionRange(start, end) { this.selectionStart = start; this.selectionEnd = end; },
    };
  }

  function makeRoot() {
    const field = makeField('todo-add');
    const root = {
      removed: false,
      nextElementSibling: null,
      querySelector(selector) {
        if (selector.includes('data-milpa-state')) return state;
        if (selector.includes('todo-add') && selector.includes('name="title"')) return field;
        return null;
      },
      contains(node) { return node === field; },
      insertAdjacentHTML() {
        const next = makeRoot();
        this.nextElementSibling = next.root;
        currentRoot = next.root;
      },
      remove() { this.removed = true; },
    };
    return { root, field };
  }

  const first = makeRoot();
  currentRoot = first.root;
  first.field.selectionStart = 2;
  first.field.selectionEnd = 4;
  document.activeElement = first.field;
  const sandbox = {
    console: { warn() {}, log() {}, error() {} },
    document,
    queueMicrotask,
    setTimeout,
  };
  sandbox.window = sandbox;
  vm.createContext(sandbox);
  vm.runInContext(readFileSync(LOCAL, 'utf8'), sandbox, { filename: LOCAL });
  vm.runInContext(readFileSync(REMOTE, 'utf8'), sandbox, { filename: REMOTE });
  const registrations = [];
  sandbox.Alpine = {
    data(name, factory) { registrations.push([name, factory]); },
    store() {},
    destroyTree(root) { destroyed.push(root); },
  };
  (listeners['alpine:init'] || []).forEach((fn) => fn());
  const factory = registrations.find(([name]) => name === 'milpaComponent')[1];

  return { sandbox, document, appended, destroyed, factory, first, currentRoot: () => currentRoot };
}

const response = () => ({
  status: 200,
  data: {
    html: '<main data-milpa-component-id="todo"></main><script data-milpa-state="todo"><signed/></script>',
    effects: [],
    assets: {
      client: { styles: ['/todo/item.css'], scripts: ['/todo/item.js'] },
      components: {
        'todo-board@1': { styles: '.board{}', scripts: [], messages: {} },
        'todo-item@1': {
          styles: '[data-milpa-component="todo-item"]{display:flex}',
          scripts: ['window.todoItemLoaded = true;'],
          messages: { 'todo-item.delete': 'Delete' },
        },
      },
    },
  },
});

test('a dynamic child installs each declared asset once across successive renders', async () => {
  const s = scene();
  s.sandbox.window.MilpaLive.transport = () => response();
  const component = s.factory({ componentId: 'todo' });
  component.$root = s.first.root;

  await component.act('add', { title: 'One' });
  component.$root = s.currentRoot();
  await component.act('refresh', {});

  const stylesheets = s.appended.filter((node) => node.tagName === 'LINK');
  const externalScripts = s.appended.filter((node) => node.tagName === 'SCRIPT' && node.attributes.src);
  const componentStyles = s.appended.filter((node) => node.tagName === 'STYLE');
  const componentScripts = s.appended.filter((node) => node.tagName === 'SCRIPT' && !node.attributes.src);
  assert.equal(stylesheets.length, 1);
  assert.equal(externalScripts.length, 1);
  assert.equal(componentStyles.length, 1, 'the initial board contract is skipped; only the new item style is added');
  assert.equal(componentScripts.length, 1);
  assert.match(componentStyles[0].textContent, /todo-item/);
  assert.equal(JSON.parse(s.document.getElementById('milpa-messages').textContent)['todo-item.delete'], 'Delete');
});

test('component replacement destroys the old Alpine tree and restores the active field and selection', async () => {
  const s = scene();
  s.sandbox.window.MilpaLive.transport = () => response();
  const component = s.factory({ componentId: 'todo' });
  component.$root = s.first.root;

  await component.act('add', { title: 'One' });

  assert.equal(s.first.root.removed, true);
  assert.deepEqual(s.destroyed, [s.first.root]);
  assert.equal(s.document.activeElement, s.currentRoot().querySelector('[data-milpa-component-id="todo-add"] [name="title"]'));
  assert.equal(s.document.activeElement.selectionStart, 2);
  assert.equal(s.document.activeElement.selectionEnd, 4);
});
