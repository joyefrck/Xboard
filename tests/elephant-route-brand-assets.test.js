const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const repoRoot = path.resolve(__dirname, '..');

function readBuffer(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath));
}

function readText(relativePath) {
  return readBuffer(relativePath).toString('utf8');
}

function assertPng(relativePath, width, height) {
  const buffer = readBuffer(relativePath);
  assert.equal(buffer.toString('ascii', 1, 4), 'PNG');
  assert.equal(buffer.readUInt32BE(16), width);
  assert.equal(buffer.readUInt32BE(20), height);
  assert.equal(buffer[24], 8, `${relativePath} must use 8-bit channels`);
  assert.equal(buffer[25], 6, `${relativePath} must preserve RGBA transparency`);
}

test('ElephantRoute browser assets derive from the latest client logo and stay mirrored', () => {
  const clientLogo = readBuffer('clients/elephant-route-deprecated/assets/images/logo.svg');
  assert.deepEqual(readBuffer('theme/ElephantRoute/assets/client-logo.svg'), clientLogo);
  assert.deepEqual(readBuffer('public/theme/ElephantRoute/assets/client-logo.svg'), clientLogo);
  assert.deepEqual(readBuffer('theme/ElephantRoute/assets/favicon.svg'), clientLogo);
  assert.deepEqual(readBuffer('public/theme/ElephantRoute/assets/favicon.svg'), clientLogo);

  for (const name of [
    'favicon-32x32.png',
    'favicon-16x16.png',
    'apple-touch-icon.png',
    'favicon.ico'
  ]) {
    assert.deepEqual(
      readBuffer(`public/theme/ElephantRoute/assets/${name}`),
      readBuffer(`theme/ElephantRoute/assets/${name}`)
    );
  }
});

test('ElephantRoute PNG and ICO fallbacks have browser-compatible dimensions and transparency', () => {
  assertPng('theme/ElephantRoute/assets/favicon-32x32.png', 32, 32);
  assertPng('theme/ElephantRoute/assets/favicon-16x16.png', 16, 16);
  assertPng('theme/ElephantRoute/assets/apple-touch-icon.png', 180, 180);

  const ico = readBuffer('theme/ElephantRoute/assets/favicon.ico');
  assert.deepEqual(ico.subarray(0, 4), Buffer.from([0x00, 0x00, 0x01, 0x00]));
});

test('ElephantRoute app shell exposes the canonical favicon set on every frontend route', () => {
  for (const viewPath of [
    'theme/ElephantRoute/dashboard.blade.php',
    'public/theme/ElephantRoute/dashboard.blade.php'
  ]) {
    const view = readText(viewPath);
    assert.match(view, /favicon\.svg\?v=\{\{\$version\}\}-er20260826brandLogo1/);
    assert.match(view, /favicon-32x32\.png\?v=\{\{\$version\}\}-er20260826brandLogo1/);
    assert.match(view, /favicon-16x16\.png\?v=\{\{\$version\}\}-er20260826brandLogo1/);
    assert.match(view, /favicon\.ico\?v=\{\{\$version\}\}-er20260826brandLogo1/);
    assert.match(view, /apple-touch-icon\.png\?v=\{\{\$version\}\}-er20260826brandLogo1/);
  }
});

test('ElephantRoute authentication pages use only the canonical client SVG', () => {
  for (const scriptPath of [
    'theme/ElephantRoute/assets/elephant-route-auth.js',
    'public/theme/ElephantRoute/assets/elephant-route-auth.js'
  ]) {
    const script = readText(scriptPath);
    assert.match(script, /BRAND_LOGO_URL = '\/theme\/ElephantRoute\/assets\/client-logo\.svg'/);
    assert.match(script, /applyBrandLogo\(block\.querySelector\('img'\)\)/);
    assert.doesNotMatch(script, /login_logo\.jpeg|home_logo\.jpeg|landing\/assets\/elephant-route-logo\.jpg/);
  }
});

test('ElephantRoute landing page uses the canonical visible logo, metadata, and favicon set', () => {
  const landing = readText('public/landing/index.html');
  const publicLogoUrl = 'https://www.elphantroute.com/theme/ElephantRoute/assets/apple-touch-icon.png';

  assert.match(landing, /rel="icon" type="image\/svg\+xml" href="\/theme\/ElephantRoute\/assets\/favicon\.svg\?v=er20260826brandLogo1"/);
  assert.match(landing, /rel="icon" type="image\/png" sizes="32x32" href="\/theme\/ElephantRoute\/assets\/favicon-32x32\.png\?v=er20260826brandLogo1"/);
  assert.match(landing, /rel="icon" type="image\/png" sizes="16x16" href="\/theme\/ElephantRoute\/assets\/favicon-16x16\.png\?v=er20260826brandLogo1"/);
  assert.match(landing, /rel="icon" href="\/theme\/ElephantRoute\/assets\/favicon\.ico\?v=er20260826brandLogo1" sizes="any"/);
  assert.match(landing, /rel="apple-touch-icon" sizes="180x180" href="\/theme\/ElephantRoute\/assets\/apple-touch-icon\.png\?v=er20260826brandLogo1"/);
  assert.match(landing, /src="\/theme\/ElephantRoute\/assets\/client-logo\.svg"/);
  assert.match(landing, /alt="大象网络"/);
  assert.equal(landing.split(publicLogoUrl).length - 1, 2);
  assert.doesNotMatch(landing, /landing\/assets\/elephant-route-logo\.jpg/);
});

test('ElephantRoute public brand runtime and favicon assets are explicitly deployable', () => {
  const ignoreRules = readText('public/theme/.gitignore');

  for (const name of [
    'elephant-route-auth.js',
    'favicon.svg',
    'favicon-32x32.png',
    'favicon-16x16.png',
    'apple-touch-icon.png',
    'favicon.ico'
  ]) {
    assert.match(ignoreRules, new RegExp(`!ElephantRoute/assets/${name.replace('.', '\\.')}\\n`));
  }
});
