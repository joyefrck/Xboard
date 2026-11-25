const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const read = (name) => fs.readFileSync(path.join(root, name), 'utf8');
const bundle = read('theme/ElephantRoute/assets/umi.js');
const start = bundle.indexOf('[X("form"') >= 0 ? bundle.indexOf('[X("form"') : bundle.indexOf('(be(),Fe("form",{key:');
const email = bundle.indexOf('inputProps:{name:"email",autocomplete:"username"');
const form = bundle.slice(bundle.lastIndexOf('Fe("form",{', email), bundle.indexOf('X("div",V7e,[', email));

// Chrome identifies a form by its fields, hidden ones included. When login shared the registration
// fields, Google's crowd data labelled the login password as a new password and the email field
// stopped offering saved accounts.
test('registration-only fields are not rendered on the login page', () => {
  assert.ok(start > 0 && email > 0);
  for (const [field, block] of [['c.email_code', 'D7e'], ['c.confirm_password', 'N7e'], ['c.invite_code', 'H7e'], ['u.value', 'j7e']]) {
    const index = form.indexOf(`...${block}},[`);
    assert.ok(index > 0, `${block} must be conditionally created`);
    const condition = form.slice(form.lastIndexOf('?(be(),Fe("div",{key:"er-', index) - 120, index);
    assert.match(condition, /\["register"(?:,"forgetpassword")?\]\.includes\(se\.value\)/, block);
    assert.ok(form.slice(index).includes(field), field);
  }
  assert.doesNotMatch(form, /\[\[Mn,/, 'v-show would keep hidden fields in the login form');
});

test('login, registration and reset each own their form element', () => {
  assert.match(form, /^Fe\("form",\{key:se\.value,/);
  assert.match(form, /name:"password",autocomplete:se\.value==="login"\?"current-password":"new-password"/);
  assert.match(form, /name:"confirm_password",autocomplete:"new-password"/);
});

test('human verification is rendered again in a recreated form', () => {
  for (const base of ['theme', 'public/theme']) {
    const source = read(`${base}/ElephantRoute/assets/elephant-route-auth.js`);
    const created = source.slice(source.indexOf("if (!container) {"), source.indexOf("container.className = 'er-turnstile-wrap'"));
    assert.match(created, /turnstile\.remove\(turnstileWidgetId\)[\s\S]*turnstileWidgetId = null;\s*turnstileToken = '';/, base);
  }
});
