const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');

test('public pricing renders monthly and one-time plans with their prices and registration actions', async () => {
  const html = fs.readFileSync(path.join(__dirname, '../public/pricing.html'), 'utf8');
  const script = html.match(/<script>([\s\S]*?)<\/script>/)[1];
  const cards = [];
  const grid = { innerHTML: '', appendChild: card => cards.push(card) };
  let loaded;
  vm.runInNewContext(script, {
    window: { addEventListener: (event, callback) => { loaded = callback; } },
    document: {
      getElementById: id => id === 'pricingGrid' ? grid : { addEventListener() {} },
      createElement: () => ({}),
    },
    fetch: async url => {
      assert.equal(url, '/api/v1/guest/plan/fetch');
      return { json: async () => ({ data: [
        { name: '高级套餐', month_price: 3500, onetime_price: null },
        { name: '不限时套餐', month_price: null, onetime_price: 10000 },
      ] }) };
    },
    console,
  });
  loaded();
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(cards.length, 2);
  assert.match(cards[0].innerHTML, /￥35\.00 <span>\/ 月<\/span>/);
  assert.match(cards[1].innerHTML, /不限时套餐/);
  assert.match(cards[1].innerHTML, /￥100\.00 <span>\/ 不限时<\/span>/);
  for (const card of cards) assert.match(card.innerHTML, /\/app#\/register/);
});
