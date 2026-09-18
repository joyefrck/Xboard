const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');
const read = p => fs.readFileSync(path.join(__dirname, '..', p), 'utf8');
const dashboard = read('theme/ElephantRoute/assets/elephant-route-dashboard-v2.js');
const joinSource = dashboard.slice(dashboard.indexOf('  function joinTelegramGroup('), dashboard.indexOf('  function openTelegramBinding('));
function fixture() {
  const calls = [], opens = [], buttons = [], messages = [], modals = [], routes = [];
  let resolve, reject, hidden = true, clock = Date.now();
  const state = { root: {}, data: { user: { telegram_id: 123 } }, modalGeneration: 0, groupJoining: false };
  const context = {
    state, ENDPOINTS: { telegramGroup: '/api/v1/user/telegram/join-group' },
    Date: class extends Date { static now() { return clock; } },
    setButtonState: (...args) => buttons.push(args),
    createElement: (tag, className, textContent) => ({ tag, className, textContent, dataset: {}, addEventListener(type, fn) { this[type] = fn; } }),
    openModal(title, subtitle, render) {
      hidden = false; state.modalGeneration++;
      const modal = { title, subtitle, body: [], footer: [] }; modals.push(modal);
      render({ appendChild: el => modal.body.push(el) }, { appendChild: el => modal.footer.push(el) });
    },
    closeModal() { hidden = true; state.modalGeneration++; },
    getModal: () => ({ getAttribute: () => String(hidden) }),
    addModalCloseButton: footer => footer.appendChild({ textContent: '我知道了' }),
    requestJson: (...args) => { calls.push(args); return new Promise((yes,no) => { resolve=yes; reject=no; }); },
    getResponseData: x => x.data,
    global: { open: (...args) => opens.push(args) },
    notify: (...args) => messages.push(args),
    navigate: route => routes.push(route),
    openTelegramBinding: () => messages.push(['bind'])
  };
  vm.runInNewContext(joinSource+';this.join = joinTelegramGroup;', context);
  return { ...context, calls, opens, buttons, messages, modals, routes, resolve: value=>resolve({data:value}), reject: value=>reject(value), advance: ms=>{clock+=ms;}, last: ()=>modals.at(-1) };
}
const settled = () => new Promise(resolve => setImmediate(resolve));

test('group admission uses POST, disables repeated clicks and waits for an explicit Telegram click', async () => {
  const f=fixture(); f.join(); f.join(); assert.equal(f.calls.length,1); assert.equal(f.calls[0][1].method,'POST');
  f.resolve({state:'ready',invite_link:'https://t.me/+synthetic',expires_at:Math.floor(Date.now()/1000)+600}); await settled();
  assert.equal(f.opens.length,0); assert.equal(f.state.groupJoining,false);
  const button=f.last().footer.find(el=>el.textContent==='前往 Telegram 申请入群'); assert.ok(button); button.click();
  assert.deepEqual(f.opens[0],['https://t.me/+synthetic','_blank','noopener,noreferrer']);
  f.advance(601000); button.click(); assert.equal(f.opens.length,1); assert.match(f.messages[0][1],/过期/);
});
test('ineligible users see purchase and historical-grant guidance plus a close button', async () => {
  const f=fixture(); f.join(); f.resolve({state:'ineligible'}); await settled();
  assert.match(f.last().body[0].textContent,/管理员.*过期.*联系支持/);
  assert.ok(f.last().footer.some(el=>el.textContent==='我知道了'));
  f.last().footer.find(el=>el.textContent==='前往购买').click(); assert.deepEqual(f.routes,['#/plan']); assert.equal(f.opens.length,0);
});
test('qualified unbound users are guided to binding, not another payment', async () => {
  const f=fixture(); f.join(); f.resolve({state:'binding_required'}); await settled();
  assert.match(f.last().body[0].textContent,/已获得入群资格/); assert.equal(f.state.data.user.telegram_id,null);
  f.last().footer.find(el=>el.textContent==='绑定 Bot').click(); assert.deepEqual(f.messages,[['bind']]);
});
test('closing or replacing the modal prevents late responses from reopening it', async () => {
  for (const replace of [false,true]) {
    const f=fixture(); f.join(); if(replace) f.openModal('other','',()=>{}); else f.closeModal();
    const count=f.modals.length; f.resolve({state:'ineligible'}); await settled(); assert.equal(f.modals.length,count);
  }
});
test('expired, hostile or missing links never open an external window', async () => {
  for (const result of [{state:'ready',invite_link:'javascript:alert(1)'},{state:'ready',invite_link:'https://t.me.attacker.invalid/+x'},{state:'ready',invite_link:'https://t.me/+expired',expires_at:1}]) {
    const f=fixture(); f.join(); f.resolve(result); await settled(); assert.equal(f.opens.length,0);
    assert.ok(!f.last().footer.some(el=>el.textContent==='前往 Telegram 申请入群'));
  }
});
test('terminal states and temporary/authentication errors offer no raw-link fallback', async () => {
  for(const state of ['already_member','binding_conflict','blocked','unavailable']) {
    const f=fixture();f.join();f.resolve({state});await settled();assert.equal(f.opens.length,0);assert.equal(f.last().footer.length,1);
  }
  for(const status of [401,503]) {
    const f=fixture();f.join();f.reject({status});await settled();assert.equal(f.opens.length,0);
    assert.match(f.last().title,status===401?/登录/:/暂时/);
  }
});
test('routes, server-side identity and webhook authentication protect all group entry points', () => {
  const routes=read('app/Http/Routes/V1/UserRoute.php'); assert.match(routes,/post\('\/telegram\/join-group'.*throttle:6,1/);
  const user=read('app/Http/Controllers/V1/User/TelegramController.php'); assert.match(user,/issue\(\$request->user\(\)\)/); assert.match(user,/no-store/);
  const comm=read('app/Http/Controllers/V1/User/CommController.php'); assert.match(comm,/'telegram_discuss_link'\s*=>\s*null/);
  const hook=read('app/Http/Controllers/V1/Guest/TelegramController.php'); assert.match(hook,/hash_equals/); assert.match(hook,/ProcessTelegramGroupRequest::dispatch/); assert.doesNotMatch(hook,/isAvailable|formatChatJoinRequest/);
  assert.doesNotMatch(joinSource,/telegram_discuss_link/);
});
test('order and administrator grants are wired inside their existing transactions', () => {
  const orders=read('app/Services/OrderService.php'); assert.match(orders,/grantFromOrder\(\$order\);\s*}\);/);
  const admin=read('app/Http/Controllers/V2/Admin/UserController.php'); assert.match(admin,/isAdminPlanGrant\(\$user, \$params\)/);
  assert.equal((admin.match(/'admin_plan'/g)||[]).length,3); assert.match(admin,/'admin_package'/);
  assert.doesNotMatch(read('app/Services/UserService.php'),/TelegramGroupEligibility/);
});
test('admin controls are localized, cache refreshed and isolated from ticket bot controls', () => {
  const admin=read('public/assets/admin/assets/index.js'); assert.match(admin,/name:"telegram_group_access_enable"/); assert.match(admin,/name:"telegram_discuss_id"/); assert.match(admin,/checkTelegramGroup/);
  assert.doesNotMatch(admin,/name:"telegram_discuss_link"/); assert.match(admin,/catch\{await K\(\)\}finally/);
  for(const locale of ['zh-CN','en-US','ko-KR']) {
    const context={window:{}}; vm.runInNewContext(read(`public/assets/admin/locales/${locale}.js`),context);
    const labels=context.window.XBOARD_TRANSLATIONS[locale].settings.telegram.group_access;
    for(const key of ['title','enable','prerequisites','chat_id','check','ready','not_ready']) assert.ok(labels[key]);
  }
  assert.match(read('resources/views/admin.blade.php'),/20260918-income-period1/);
  assert.equal(dashboard,read('public/theme/ElephantRoute/assets/elephant-route-dashboard-v2.js'));
});
test('join queue has unique jobs and enough retry visibility for bounded Telegram operations', () => {
  const job=read('app/Jobs/ProcessTelegramGroupRequest.php'); assert.match(job,/ShouldBeUnique/); assert.match(job,/onConnection\('telegram_group'\)/);
  assert.match(read('config/queue.php'),/'telegram_group'[\s\S]*?'retry_after'\s*=>\s*300/);
  assert.match(read('config/horizon.php'),/'XboardTelegramGroup'/);
  assert.match(read('app/Console/Kernel.php'),/telegram:group-retry.*everyMinute/);
});
