const assert = require('node:assert/strict');
const test = require('node:test');
const { createPurchaseNotice } = require('../theme/ElephantRoute/assets/elephant-route-pages-v2.js');

function harness(hash = '#/plan') {
  const attributes = new Map();
  const dialogs = [];
  const win = {
    location: { hash },
    document: {
      body: {
        appendChild(dialog) { dialogs.push(dialog); },
        setAttribute(key, value) { attributes.set(key, value); },
        removeAttribute(key) { attributes.delete(key); },
      },
      createElement(tag) {
        assert.equal(tag, 'dialog');
        const events = new Map();
        const buttons = new Map();
        return {
          events, buttons, open: false, removed: false, attributes: new Map(),
          setAttribute(key, value) { this.attributes.set(key, value); },
          addEventListener(event, callback) { events.set(event, callback); },
          querySelector(selector) {
            return { addEventListener(event, callback) { buttons.set(selector, callback); } };
          },
          showModal() { this.open = true; },
          close() { this.open = false; },
          remove() { this.removed = true; },
        };
      },
    },
  };
  const notice = createPurchaseNotice(win);
  const navigate = hash => { win.location.hash = hash; notice.sync(); };
  notice.sync();
  return { win, notice, navigate, dialogs, attributes };
}

test('checkout entry opens a global notice and confirmation preserves the selected plan', () => {
  const h = harness();
  assert.equal(h.dialogs.length, 0);
  h.navigate('#/plan/4');
  const dialog = h.dialogs[0];
  assert.equal(dialog.open, true);
  assert.equal(h.attributes.get('data-er-purchase-notice'), 'open');
  assert.match(dialog.innerHTML, /站内工单/);
  assert.match(dialog.innerHTML, /官方 Telegram 群/);
  assert.match(dialog.innerHTML, /停止提供服务并停用相关账号/);
  assert.equal(dialog.attributes.get('aria-labelledby'), 'er-purchase-notice-title');
  assert.equal(dialog.attributes.get('aria-describedby'), 'er-purchase-notice-description');
  h.notice.sync();
  assert.equal(h.dialogs.length, 1);
  dialog.buttons.get('[data-er-notice-continue]')();
  assert.equal(h.win.location.hash, '#/plan/4');
  assert.equal(dialog.open, false);
  assert.equal(dialog.removed, true);
  assert.equal(h.attributes.size, 0);
  h.notice.sync();
  h.navigate('#/plan/4?period=onetime_price');
  h.navigate('#/order/test-order');
  assert.equal(h.dialogs.length, 1, 'checkout to order must not repeat the notice');
  h.navigate('#/plan');
  h.navigate('#/plan/4');
  assert.equal(h.dialogs.length, 2, 'a new purchase must show the notice again');
});

test('direct order entry, another plan, and another order each show a notice', () => {
  const h = harness('#/order/first');
  assert.equal(h.dialogs.length, 1);
  h.dialogs[0].buttons.get('[data-er-notice-continue]')();
  h.navigate('#/order/second');
  assert.equal(h.dialogs.length, 2);
  h.navigate('#/plan/2');
  h.dialogs[2].buttons.get('[data-er-notice-continue]')();
  h.navigate('#/plan/3');
  assert.equal(h.dialogs.length, 4);
});

test('back and Escape return to the store without continuing the purchase', () => {
  const h = harness('#/plan/4');
  h.dialogs[0].buttons.get('[data-er-notice-back]')();
  assert.equal(h.win.location.hash, '#/plan');
  assert.equal(h.dialogs[0].removed, true);
  h.navigate('#/plan/4');
  let prevented = false;
  h.dialogs[1].events.get('cancel')({ preventDefault() { prevented = true; } });
  assert.equal(prevented, true);
  assert.equal(h.win.location.hash, '#/plan');
  assert.equal(h.attributes.size, 0);
});

test('leaving the purchase route or unmounting removes the dialog and scroll lock', () => {
  const h = harness('#/plan/4');
  h.navigate('#/ticket');
  assert.equal(h.dialogs[0].removed, true);
  assert.equal(h.attributes.size, 0);
  for (const route of ['#/login', '#/dashboard', '#/plan', '#/order']) h.navigate(route);
  assert.equal(h.dialogs.length, 1);
  h.navigate('#/order/example');
  h.notice.destroy();
  h.notice.destroy();
  assert.equal(h.dialogs[1].removed, true);
  assert.equal(h.attributes.size, 0);
});
