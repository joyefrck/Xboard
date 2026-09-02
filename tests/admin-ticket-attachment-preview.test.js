const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

// Model DOM capture/bubble order, including Radix's document-level dismissal.
class Target {
  constructor() {
    this.listeners = [];
    this.children = [];
    this.style = {};
  }
  addEventListener(type, callback, options = false) {
    this.listeners.push({ type, callback, capture: options === true || !!options.capture });
  }
  removeEventListener(type, callback, options = false) {
    const capture = options === true || !!options.capture;
    this.listeners = this.listeners.filter(x => x.type !== type || x.callback !== callback || x.capture !== capture);
  }
  setAttribute(name, value) { this[name] = value; }
  append(...nodes) { nodes.forEach(node => this.appendChild(node)); }
  appendChild(node) { node.parent = this; this.children.push(node); return node; }
  remove() {
    this.parent.children = this.parent.children.filter(x => x !== this);
    this.parent = null;
  }
}

function dispatch(target, type, extra = {}) {
  const event = {
    type, target, ...extra, defaultPrevented: false, stopped: false, immediate: false,
    preventDefault() { this.defaultPrevented = true; },
    stopPropagation() { this.stopped = true; },
    stopImmediatePropagation() { this.stopped = this.immediate = true; }
  };
  const ancestors = [];
  for (let node = target; node; node = node.parent) ancestors.push(node);
  const deliver = (node, capture) => {
    for (const entry of [...node.listeners]) {
      if (entry.type === type && entry.capture === capture) entry.callback(event);
      if (event.immediate) break;
    }
  };
  for (const node of [...ancestors].reverse()) {
    deliver(node, true);
    if (event.stopped) return event;
  }
  for (const node of ancestors) {
    deliver(node, false);
    if (event.stopped) break;
  }
  return event;
}

function setupPreview() {
  const window = new Target();
  const document = new Target();
  document.parent = window;
  document.body = document.appendChild(new Target());
  document.body.style.pointerEvents = 'none'; // Modal Dialog's outside-pointer lock.
  document.createElement = () => new Target();
  const revoked = [];
  const state = { ticketOpen: true, outsideClicks: 0 };
  document.addEventListener('pointerdown', () => { state.ticketOpen = false; });
  document.addEventListener('click', () => { state.outsideClicks++; });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') state.ticketOpen = false;
  }, true);
  const bundle = fs.readFileSync(path.join(__dirname, '../public/assets/admin/assets/index.js'), 'utf8');
  const start = bundle.indexOf('xboardAdminShowTicketAttachment=');
  const end = bundle.indexOf(',xboardAdminOpenTicketAttachment=', start);
  assert.ok(start >= 0 && end > start);
  const show = vm.runInNewContext(`(${bundle.slice(start, end).split('=').slice(1).join('=')})`, {
    window, document, URL: { revokeObjectURL: url => revoked.push(url) }
  });
  return { window, document, revoked, state, show, overlay: show('blob:test', '<plain filename>.png') };
}

test('ticket image preview receives pointers despite the underlying modal body lock', () => {
  const { overlay } = setupPreview();
  assert.match(overlay.style.cssText, /pointer-events:\s*auto/);
  assert.equal(overlay.children[0].alt, '<plain filename>.png');
});

for (const closeTarget of ['button', 'backdrop']) {
  test(`ticket image ${closeTarget} closes only the preview and releases its resources`, () => {
    const { window, document, overlay, state, revoked } = setupPreview();
    const target = closeTarget === 'button' ? overlay.children[1] : overlay;
    dispatch(target, 'pointerdown');
    assert.equal(state.ticketOpen, true, 'pointerdown must not dismiss the ticket');
    dispatch(target, 'click');
    assert.equal(document.body.children.length, 0);
    assert.equal(state.ticketOpen, true);
    assert.equal(state.outsideClicks, 0);
    assert.deepEqual(revoked, ['blob:test']);
    assert.equal(window.listeners.length, 0, 'remove preview keyboard capture on close');
    dispatch(document, 'keydown', { key: 'Escape' });
    assert.equal(state.ticketOpen, false, 'underlying dialog can close normally afterward');
  });
}

test('clicking the preview image keeps both layers open without leaking events', () => {
  const { overlay, state, revoked } = setupPreview();
  dispatch(overlay.children[0], 'pointerdown');
  dispatch(overlay.children[0], 'click');
  assert.equal(state.ticketOpen, true);
  assert.equal(state.outsideClicks, 0);
  assert.ok(overlay.parent);
  assert.deepEqual(revoked, []);
});

test('Escape closes the image before the document-capture ticket handler', () => {
  const { window, document, overlay, state, revoked, show } = setupPreview();
  dispatch(overlay.children[1], 'keydown', { key: 'ArrowRight' });
  assert.ok(overlay.parent);
  for (const preview of [overlay, null]) {
    const current = preview || show('blob:test-again', 'second.png');
    const event = dispatch(current.children[1], 'keydown', { key: 'Escape' });
    assert.equal(state.ticketOpen, true);
    assert.equal(event.defaultPrevented, true);
    assert.equal(current.parent, null);
    assert.equal(window.listeners.length, 0);
  }
  assert.deepEqual(revoked, ['blob:test', 'blob:test-again']);
  dispatch(document, 'keydown', { key: 'Escape' });
  assert.equal(state.ticketOpen, false);
});
