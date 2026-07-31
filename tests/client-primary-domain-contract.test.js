const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const assert = require('node:assert/strict');

const repoRoot = path.resolve(__dirname, '..');

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

test('active client production entrypoints use elephant111.org', () => {
  const constants = read('clients/elephant-route-deprecated/lib/utils/constants.dart');
  const buildScript = read('clients/elephant-route-deprecated/build_prod.sh');
  const androidService = read(
    'clients/elephant-route-deprecated/android/app/src/main/kotlin/com/elephantroute/SingboxVpnService.kt',
  );

  assert.match(constants, /https:\/\/www\.elephant111\.org/);
  assert.doesNotMatch(constants, /https:\/\/www\.elephant111\.com/);
  assert.match(buildScript, /PROD_URL="https:\/\/www\.elephant111\.org\/"/);
  assert.doesNotMatch(buildScript, /https:\/\/www\.elephant111\.com/);
  assert.equal((androidService.match(/www\.elephant111\.org/g) || []).length, 2);
  assert.doesNotMatch(androidService, /www\.elephant111\.com/);
});
