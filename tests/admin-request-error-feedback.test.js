const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const repoRoot = path.resolve(__dirname, '..');
const adminBundle = fs.readFileSync(
  path.join(repoRoot, 'public/assets/admin/assets/index.js'),
  'utf8',
);

function extractResponseInterceptor(source) {
  const startMarker = 'Ot.interceptors.response.use(';
  const endMarker = 'const O={get:';
  const start = source.indexOf(startMarker);
  const end = source.indexOf(endMarker, start);

  assert.notEqual(start, -1, 'admin Axios response interceptor exists');
  assert.notEqual(end, -1, 'admin request wrapper follows the interceptor');

  return source.slice(start, end);
}

test('admin request cancellation is rejected without showing a global error', () => {
  const interceptor = extractResponseInterceptor(adminBundle);
  const cancellationGuard =
    'ko.isCancel(s)||s?.code==="ERR_CANCELED"||s?.name==="AbortError"';
  const guardIndex = interceptor.indexOf(cancellationGuard);
  const notificationIndex = interceptor.indexOf('A.error(');

  assert.notEqual(guardIndex, -1, 'Axios and browser cancellation are detected');
  assert.notEqual(notificationIndex, -1, 'global error notification still exists');
  assert.ok(guardIndex < notificationIndex, 'cancellation exits before notification');
  assert.match(
    interceptor,
    /if\(ko\.isCancel\(s\)\|\|s\?\.code==="ERR_CANCELED"\|\|s\?\.name==="AbortError"\)return Promise\.reject\(s\)/,
  );
});

test('admin request timeouts use a specific retry message', () => {
  const interceptor = extractResponseInterceptor(adminBundle);

  assert.match(interceptor, /s\?\.code==="ECONNABORTED"/);
  assert.match(interceptor, /s\?\.code==="ETIMEDOUT"/);
  assert.match(interceptor, /\/timeout\/i\.test\(s\?\.message\|\|""\)/);
  assert.match(interceptor, /A\.error\(l\?"请求超时，请重试":/);
});

test('admin HTTP errors keep existing message precedence and status handling', () => {
  const interceptor = extractResponseInterceptor(adminBundle);

  assert.match(interceptor, /s\.response\?\.data\?\.message/);
  assert.match(interceptor, /\(n===401\|\|n===403\)&&ni\(\)/);
  assert.match(interceptor, /401:"登录已过期"/);
  assert.match(interceptor, /403:"没有权限"/);
  assert.match(interceptor, /404:"资源或接口不存在"/);
  assert.match(interceptor, /t\|\|\{401:/);
});
