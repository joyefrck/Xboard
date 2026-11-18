const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const bundle = fs.readFileSync(path.join(__dirname, '../public/assets/admin/assets/index.js'), 'utf8');
const sidebar = bundle.slice(bundle.indexOf('function lg('), bundle.indexOf('function rg('));
const dialog = bundle.slice(bundle.indexOf('function rg('), bundle.indexOf('const ig='));
const jsx = (type, props, key) => ({ type, props, key });

function nodes(tree) {
  if (Array.isArray(tree)) return tree.flatMap(nodes);
  if (!tree || typeof tree !== 'object') return [];
  return [tree, ...nodes(tree.props?.children)];
}

function setup(ticket, { message = '  reply  ', sending = false, sidebarTickets = [ticket] } = {}) {
  const calls = [];
  const states = [{[ticket.id]: {text: message}}, sending, '', false];
  let stateIndex = 0;
  let queryIndex = 0;
  let resolveReply, rejectReply;
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
      useRef: () => ({ current: null }), useCallback: callback => callback, useEffect() {},
      useState: () => { const index = stateIndex++; return [states[index], value => { states[index] = typeof value === 'function' ? value(states[index]) : value; }]; }
    },
    oe: () => queryIndex++ === 0
      ? { data: { data: sidebarTickets }, refetch: () => calls.push('sidebar') }
      : { data: { data: ticket }, refetch: () => calls.push('detail') },
    zt: {
      reply(payload) {
        calls.push(['reply', JSON.parse(JSON.stringify(payload))]);
        return pendingReply;
      },
      close: async () => { calls.push('close'); }
    },
    A: { success() {} }, setTimeout() {},
    xboardTicketTypeKey: () => 'node'
  };
  vm.createContext(context);
  vm.runInContext(`${sidebar}${dialog};this.renderDialog=rg;this.renderSidebar=lg`, context);
  const refresh = () => calls.push('table');
  function render(activeTicket = ticket) {
    ticket = activeTicket;
    stateIndex = 0;
    queryIndex = 0;
    return context.renderDialog({ ticketId: ticket.id, onUpdated: refresh, onClose: () => calls.push('dismiss') });
  }
  const tree = render();
  return { context, tree, render, calls, states, refresh, resolveReply, rejectReply };
}

test('reply success immediately refreshes detail, sidebar and the parent table', async () => {
  const app = setup({ id: 3139, status: 0, reply_status: 1, subject: 'ticket', messages: [] });
  const send = nodes(app.tree).find(node => node.type === 'J' && node.props.children === 'detail.input.send');
  const sending = send.props.onClick();
  assert.deepEqual(app.calls, [['reply', { id: 3139, message: 'reply', attachments: [] }]]);
  app.resolveReply();
  await sending;
  assert.deepEqual(app.calls.slice(1).sort(), ['detail', 'sidebar', 'table']);
  assert.equal(app.states[0][3139], undefined);
  assert.equal(app.states[1], false);
});

test('failed replies preserve the draft and do not refresh any list', async () => {
  const app = setup({ id: 3139, status: 0, reply_status: 1, subject: 'ticket' });
  const send = nodes(app.tree).find(node => node.type === 'J' && node.props.children === 'detail.input.send');
  const sending = send.props.onClick();
  app.rejectReply(new Error('request failed'));
  await sending;
  assert.equal(app.calls.length, 1);
  assert.equal(app.states[0][3139].text, '  reply  ');
  assert.equal(app.states[1], false);
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

test('selected replied ticket stays in the sidebar when sorting moves it off the first page', () => {
  const ticket = { id: 3139, status: 0, reply_status: 0, subject: 'answered' };
  const other = { id: 3140, status: 0, reply_status: 1, subject: 'pending' };
  const app = setup(ticket, { sidebarTickets: [other] });
  const cards = nodes(app.tree).filter(node => node.type === app.context.renderSidebar);
  assert.deepEqual(cards.map(card => card.props.ticket.id), [3139, 3140]);
  assert.equal(cards[0].props.isActive, true);
  assert.ok(nodes(app.tree).some(node => node.props?.children === '#3139'));
});

test('Ctrl+Enter shares the button submission and ignores repeat and composition events', async () => {
  const app = setup({ id: 8, status: 0, subject: 'ticket' });
  const input = nodes(app.tree).find(node => node.type === 'Zi');
  const send = nodes(app.tree).find(node => node.props?.onClick?.name === 'sendReply');
  const event = { key: 'Enter', ctrlKey: true, nativeEvent: {}, preventDefault() {} };
  for (const override of [{ctrlKey:false}, {key:'a'}, {repeat:true}, {nativeEvent:{isComposing:true}}, {nativeEvent:{keyCode:229}}]) {
    input.props.onKeyDown({ ...event, ...override });
  }
  assert.deepEqual(app.calls, []);
  input.props.onKeyDown(event);
  input.props.onKeyDown(event);
  await send.props.onClick();
  assert.deepEqual(app.calls, [['reply', { id:8, message:'reply', attachments:[] }]]);
  app.resolveReply();
});

test('shortcut cannot send a blank, closed, or already sending reply', () => {
  for (const [status, options] of [[0, { message: '  ' }], [1, {}], [0, { sending:true }]]) {
    const app = setup({ id: 8, status, subject:'ticket' }, options);
    const input = nodes(app.tree).find(node => node.type === 'Zi');
    input.props.onKeyDown({ key:'Enter', ctrlKey:true, nativeEvent:{}, preventDefault() {} });
    assert.deepEqual(app.calls, []);
  }
});

test('switching tickets keeps each text and image draft with its ticket until sent', async () => {
  const first = { id: 3, status: 0, subject: 'first' };
  const second = { id: 4, status: 0, subject: 'second' };
  const app = setup(first, { message: '', sidebarTickets: [first, second] });
  function editor(ticket) {
    const elements = nodes(app.render(ticket));
    return {
      input: elements.find(node => node.type === 'Zi').props,
      picker: elements.find(node => node.type === 'xboardAdminTicketFilePicker').props,
      send: elements.find(node => node.props?.['aria-keyshortcuts'] === 'Control+Enter').props
    };
  }
  const image = { name: 'first.png' };
  editor(first).input.onChange({ target: { value: 'draft A' } });
  editor(first).picker.onFilesChange([image]);
  const blank = editor(second);
  assert.equal(blank.input.value, '');
  assert.equal(blank.picker.files.length, 0);
  assert.equal(blank.send.disabled, true);
  blank.input.onChange({ target: { value: 'draft B' } });
  assert.equal(editor(first).input.value, 'draft A');
  assert.equal(editor(first).picker.files[0], image);
  const sending = editor(first).send.onClick();
  assert.deepEqual(app.calls[0], ['reply', { id: 3, message: 'draft A', attachments: [image] }]);
  app.resolveReply();
  await sending;
  assert.equal(editor(first).input.value, '');
  assert.equal(editor(first).picker.files.length, 0);
  assert.equal(editor(second).input.value, 'draft B');
});

test('the reply field autofocuses on each editable ticket and stays mounted during refreshes', () => {
  const first = { id: 3, status: 0, subject: 'first' };
  const app = setup(first);
  const field = ticket => nodes(app.render(ticket)).find(node => node.type === 'Zi');
  const original = field(first);
  assert.equal(original.props.autoFocus, true);
  assert.equal(field({ ...first, reply_status: 0 }).key, original.key);
  const next = field({ ...first, id: 4 });
  assert.notEqual(next.key, original.key);
  assert.equal(next.props.autoFocus, true);
  const closed = field({ ...first, status: 1 });
  assert.equal(closed.props.autoFocus, false);
  assert.equal(closed.props.disabled, true);
});

test('mounting a restored reply input collapses its selection at the end of the draft', () => {
  const app = setup({ id: 4, status: 0, subject: 'ticket' });
  const { ref } = nodes(app.tree).find(node => node.type === 'Zi').props;
  const draft = '第一行\n大饭店🙂';
  const selections = [];
  const input = { value: draft, setSelectionRange: (...args) => selections.push(args) };
  ref(input);
  ref(null);
  ref({ ...input, disabled: true });
  assert.deepEqual(selections, [[draft.length, draft.length]]);
});
