const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');
const { spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '..');
function ui(theme = 'ElephantRoute') {
  const context = { window: {} }; vm.createContext(context);
  vm.runInContext(fs.readFileSync(path.join(root, `theme/${theme}/assets/elephant-route-order-refund.js`), 'utf8'), context);
  return context.window.ElephantRouteOrderRefund;
}
const h = (tag, props, children) => ({ tag, props, children });
const format = value => (value / 100).toFixed(2);
test('refunded row shows struck original and red same-size credit on its own line', () => {
  for (const theme of ['ElephantRoute','Xboard']) {
    const node = ui(theme).renderAmount(h, {status:5,total_amount:5000,refund:{amount:4000,group_order_count:1,group_total:4000}}, format);
    const [original, refunded] = node.children;
    assert.equal(original.tag, 's'); assert.equal(original.children, '50.00');
    assert.equal(refunded.children, '+40.00 元'); assert.equal(refunded.props.style.color, '#dc2626');
    assert.equal(original.props.style.fontSize, 'inherit'); assert.equal(refunded.props.style.fontSize, 'inherit');
    assert.equal(original.props.style.display, 'block'); assert.equal(refunded.props.style.display, 'block');
  }
});
test('other statuses remain unchanged; missing metadata is never invented as a zero refund', () => {
  for (const status of [0,1,2,3,4]) assert.equal(ui().renderAmount(h,{status,total_amount:5000},format), '50.00');
  assert.equal(ui().renderAmount(h,{status:5,total_amount:5000},format).children[1].children, '退款金额待确认');
});
test('merged row displays its allocation and explains the single combined credit', () => {
  const result = ui().renderAmount(h,{status:5,total_amount:5000,refund:{amount:1234,group_order_count:2,group_total:4000}},format);
  assert.equal(result.children[1].children,'+12.34 元'); assert.match(result.children[1].props.title,/合计 40.00 元/);
});
test('user themes and entry points load the helper before the app; status 5 is refunded', () => {
  const reference=fs.readFileSync(path.join(root,'theme/ElephantRoute/assets/elephant-route-order-refund.js'));
  for(const theme of ['ElephantRoute','Xboard']) {
    for(const prefix of ['theme','public/theme']) assert.deepEqual(fs.readFileSync(path.join(root,`${prefix}/${theme}/assets/elephant-route-order-refund.js`)),reference);
    const view=fs.readFileSync(path.join(root,`theme/${theme}/dashboard.blade.php`),'utf8');
    assert.ok(view.indexOf('elephant-route-order-refund.js')<view.indexOf('type="module"'));
    const source=fs.readFileSync(path.join(root,`theme/${theme}/assets/umi.js`),'utf8');
    assert.ok(source.includes('5:"已退款"')); assert.ok(source.includes('return window.ElephantRouteOrderRefund.renderAmount(v,s,sn)'));
  }
});
for (const [file,label] of [['refund-receipt-notification.php','Refund receipts and notification'],['refund-email-template.php','Refund HTML email']]) {
  test(label+' isolated integration',()=>{
    const result=spawnSync('php',[path.join(__dirname,file)],{cwd:root,encoding:'utf8'});
    assert.equal(result.status,0,result.stdout+result.stderr);assert.ok(result.stdout.includes(label));
  });
}
test('refund job retains mail queue and limiter and scheduled recovery is registered',()=>{
  const job=fs.readFileSync(path.join(root,'app/Jobs/SendRefundEmailJob.php'),'utf8');
  assert.ok(job.includes('extends SendEmailJob'));assert.ok(job.includes("parent::__construct([], 'send_email')"));
  const kernel=fs.readFileSync(path.join(root,'app/Console/Kernel.php'),'utf8');assert.ok(kernel.includes("command('refund:retry-mail')->everyMinute()"));
});
