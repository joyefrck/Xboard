const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const bundle = fs.readFileSync(path.join(__dirname, '../public/assets/admin/assets/index.js'), 'utf8');
const sidebar = bundle.slice(bundle.indexOf('function lg('), bundle.indexOf('function rg('));
const dialog = bundle.slice(bundle.indexOf('function rg('), bundle.indexOf('const ig='));
const columns = bundle.slice(bundle.indexOf('cg=s=>'), bundle.indexOf('function dg()'));
const jsx = (type, props) => ({ type, props });

function nodes(tree) {
  if (Array.isArray(tree)) return tree.flatMap(nodes);
  if (!tree || typeof tree !== 'object') return [];
  return [tree, ...nodes(tree.props?.children)];
}

function setup(ticket, { message = '  reply  ', sending = false } = {}) {
  const calls = [];
  const states = [true, message, sending, [], ticket.id, '', false];
  let stateIndex = 0;
  let queryIndex = 0;
  let resolveReply, rejectReply, replyChain;
  const pendingReply = new Promise((resolve, reject) => { resolveReply = resolve; rejectReply = reject; });
  const context = {
    ...Object.fromEntries('he hs J ue pe $l eg k Ct ng ag H $ ig og xboardTicketRemarksDialog bs eo Ga so Me Gi sg tg Zi xboardAdminTicketFilePicker xboardAdminTicketAttachmentLinks'.split(' ').map(name => [name, name])),
    e: { jsx, jsxs: jsx, Fragment: 'Fragment' },
    b: (...classes) => classes.filter(Boolean).join(' '),
    re: value => value,
    ct: { OPENING: 0, CLOSED: 1 }, js: { LOW: 0, MIDDLE: 1, HIGH: 2 },
    M: () => ({ t: key => key }), et: () => () => {},
    il: () => ({ setIsOpen() {}, setEditingUser() {} }),
    m: {
      useRef: () => ({ current: null }), useEffect() {},
      useState: () => { const index = stateIndex++; return [states[index], value => { states[index] = value; }]; }
    },
    oe: () => queryIndex++ === 0
      ? { data: { data: [ticket] }, refetch: () => calls.push('sidebar') }
      : { data: { data: ticket }, refetch: () => calls.push('detail') },
    zt: {
      reply(payload) {
        calls.push(['reply', JSON.parse(JSON.stringify(payload))]);
        // Keep the production rejection observable without an unhandled rejection.
        return { then(callback) { return { finally(done) { replyChain = pendingReply.then(callback).finally(done); } }; } };
      },
      close: async () => { calls.push('close'); }
    },
    A: { success() {} }, setTimeout() {},
    xboardTicketTypeKey: () => 'node'
  };
  vm.createContext(context);
  vm.runInContext(`${sidebar}${dialog};this.renderDialog=rg;this.renderSidebar=lg;this.getColumns=${columns.replace(/^cg=/, '')}`, context);
  const refresh = () => calls.push('table');
  const tree = context.renderDialog({ ticketId: ticket.id, onUpdated: refresh });
  return { context, tree, calls, states, refresh, resolveReply, rejectReply, settled: () => replyChain };
}

test('reply success immediately refreshes detail, sidebar and the parent table', async () => {
  const app = setup({ id: 3139, status: 0, reply_status: 1, subject: 'ticket', messages: [] });
  const send = nodes(app.tree).find(node => node.type === 'J' && node.props.children === 'detail.input.send');
  await send.props.onClick();
  assert.deepEqual(app.calls, [['reply', { id: 3139, message: 'reply', attachments: [] }]]);
  app.resolveReply();
  await app.settled();
  assert.deepEqual(app.calls.slice(1).sort(), ['detail', 'sidebar', 'table']);
  assert.equal(app.states[1], '');
  assert.equal(app.states[2], false);
  const actionColumn = app.context.getColumns(app.refresh).find(column => column.id === 'actions');
  const actionTree = actionColumn.cell({ row: { original: { id: 3139, status: 0 } } });
  const detail = nodes(actionTree).find(node => node.type === app.context.renderDialog);
  assert.equal(detail.props.onUpdated, app.refresh, 'table must pass its refresh callback into the dialog');
});

test('failed replies preserve the draft and do not refresh any list', async () => {
  const app = setup({ id: 3139, status: 0, reply_status: 1, subject: 'ticket' });
  const send = nodes(app.tree).find(node => node.type === 'J' && node.props.children === 'detail.input.send');
  await send.props.onClick();
  app.rejectReply(new Error('request failed'));
  await assert.rejects(app.settled(), /request failed/);
  assert.equal(app.calls.length, 1);
  assert.equal(app.states[1], '  reply  ');
  assert.equal(app.states[2], false);
});

for (const [status, replyStatus, expected] of [[0, 1, 'pending'], [0, 0, 'replied'], [1, 0, 'closed'], [1, 1, 'closed']]) {
  test(`sidebar and detail show ${expected} for status=${status}, reply_status=${replyStatus}`, () => {
    const ticket = { id: 3139, status, reply_status: replyStatus, subject: 'ticket' };
    const app = setup(ticket);
    const card = app.context.renderSidebar({ ticket });
    assert.ok(nodes(card).some(node => node.type === 'H' && node.props.children === `status.${expected}`));
    assert.ok(nodes(app.tree).some(node => node.type === 'H' && node.props.children === `status.${expected}`));
  });
}
