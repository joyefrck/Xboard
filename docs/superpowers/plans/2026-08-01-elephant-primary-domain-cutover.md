# Elephant Primary Domain Cutover Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `https://www.elephant111.org` the Xboard server and active-client primary domain while retaining `https://www.elephant111.com` as a compatibility alias.

**Architecture:** Update both sources of server-side canonical URLs (`APP_URL` and `v2_settings.app_url`), transform the safe-mode alias list without disturbing unrelated entries, and restart the persistent Web/Horizon processes. Update only active-client production fallback and Android direct-routing inputs; keep the dynamic-domain service and test scenario fixtures intact.

**Tech Stack:** Laravel/PHP, Docker, Redis-backed settings cache, Flutter/Dart, Kotlin, Node.js contract tests, OpenResty

---

### Task 1: Lock the active-client production-domain contract

**Files:**
- Create: `tests/client-primary-domain-contract.test.js`
- Test: `tests/client-primary-domain-contract.test.js`

- [ ] **Step 1: Write the failing contract test**

```js
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
```

- [ ] **Step 2: Run the test and confirm the old domain fails the contract**

Run:

```bash
node --test tests/client-primary-domain-contract.test.js
```

Expected: FAIL because the three production paths still contain `www.elephant111.com`.

### Task 2: Switch the active-client production fallback

**Files:**
- Modify: `clients/elephant-route-deprecated/lib/utils/constants.dart`
- Modify: `clients/elephant-route-deprecated/build_prod.sh`
- Modify: `clients/elephant-route-deprecated/android/app/src/main/kotlin/com/elephantroute/SingboxVpnService.kt`
- Test: `tests/client-primary-domain-contract.test.js`

- [ ] **Step 1: Replace only production-source occurrences**

Change all three default returns in `ApiConstants.baseUrl` to
`https://www.elephant111.org`, set `PROD_URL` to
`https://www.elephant111.org/`, and change both Android DNS/route domain entries
to `www.elephant111.org`.

- [ ] **Step 2: Run the focused contract tests**

Run:

```bash
node --test tests/client-primary-domain-contract.test.js tests/subscribe-url-generation.test.js
```

Expected: 2 tests pass.

- [ ] **Step 3: Run active-client static and unit checks**

Run:

```bash
cd clients/elephant-route-deprecated
flutter analyze
flutter test --no-pub test/core/api/domain_resolver_test.dart test/providers/vpn_provider_test.dart
```

Expected: analyze exits 0 and the selected Flutter tests pass. Existing dynamic-domain fixtures may still contain `.com` because they exercise selection and failover behavior.

- [ ] **Step 4: Commit the client change**

```bash
git add tests/client-primary-domain-contract.test.js \
  clients/elephant-route-deprecated/lib/utils/constants.dart \
  clients/elephant-route-deprecated/build_prod.sh \
  clients/elephant-route-deprecated/android/app/src/main/kotlin/com/elephantroute/SingboxVpnService.kt
git commit -m "chore: switch client primary domain"
```

### Task 3: Back up and switch the production server

**Files:**
- Modify on host: `/opt/1panel/apps/openresty/openresty/www/sites/xboard/index/.env`
- Create on host: `/root/xboard-domain-cutover-$(date -u +%Y%m%dT%H%M%SZ)/.env.before`
- Create on host: `/root/xboard-domain-cutover-$(date -u +%Y%m%dT%H%M%SZ)/settings.before.json`
- Modify in MariaDB: `v2_settings.app_url`, `v2_settings.app_url_aliases`

- [ ] **Step 1: Capture current state and create recoverable backups**

Connect with:

```bash
ssh -p 22 root@47.238.145.117
```

On the host, create a timestamped backup directory, copy `.env`, and serialize
the current `app_url` and alias list from `index-web-1` into
`settings.before.json`. Verify both files are non-empty before continuing.

```bash
XBOARD_PATH=/opt/1panel/apps/openresty/openresty/www/sites/xboard/index
CUTOVER_STAMP=$(date -u +%Y%m%dT%H%M%SZ)
CUTOVER_BACKUP_DIR="/root/xboard-domain-cutover-$CUTOVER_STAMP"
mkdir -m 700 "$CUTOVER_BACKUP_DIR"
cp -a "$XBOARD_PATH/.env" "$CUTOVER_BACKUP_DIR/.env.before"
docker exec index-web-1 php artisan tinker --execute='echo json_encode(["app_url" => admin_setting("app_url"), "app_url_aliases" => admin_setting("app_url_aliases", [])], JSON_UNESCAPED_SLASHES) . PHP_EOL;' > "$CUTOVER_BACKUP_DIR/settings.before.json"
test -s "$CUTOVER_BACKUP_DIR/.env.before"
test -s "$CUTOVER_BACKUP_DIR/settings.before.json"
```

- [ ] **Step 2: Update `APP_URL` and verify the exact line**

Replace only the line beginning `APP_URL=` with:

```dotenv
APP_URL=https://www.elephant111.org
```

Run `grep '^APP_URL='` and require exactly one matching line.

```bash
sed -i 's#^APP_URL=.*#APP_URL=https://www.elephant111.org#' "$XBOARD_PATH/.env"
test "$(grep -c '^APP_URL=' "$XBOARD_PATH/.env")" -eq 1
grep '^APP_URL=' "$XBOARD_PATH/.env"
```

- [ ] **Step 3: Update the database settings through Xboard's setting API**

Use `php artisan tinker --execute` in `index-web-1` to set:

```php
$aliases = collect(admin_setting('app_url_aliases', []))
    ->reject(fn ($alias) => rtrim((string) $alias, '/') === 'https://www.elephant111.org')
    ->push('https://www.elephant111.com')
    ->unique()
    ->values()
    ->all();
admin_setting([
    'app_url' => 'https://www.elephant111.org',
    'app_url_aliases' => $aliases,
]);
```

Run the update through the existing container:

```bash
docker exec index-web-1 php artisan tinker --execute='$aliases = collect(admin_setting("app_url_aliases", []))->reject(fn ($alias) => rtrim((string) $alias, "/") === "https://www.elephant111.org")->push("https://www.elephant111.com")->unique()->values()->all(); admin_setting(["app_url" => "https://www.elephant111.org", "app_url_aliases" => $aliases]); dump(admin_setting("app_url")); dump(admin_setting("app_url_aliases", []));'
```

Expected aliases, preserving unrelated entries:

```text
https://www.elephant223.com
https://www.elphantroute.com
https://dx.elphantroute.com
https://www.elephant111.com
```

- [ ] **Step 4: Clear configuration and restart persistent services**

Run `php artisan optimize:clear` in `index-web-1`, restart `index-web-1` and
`index-horizon-1`, and wait until both containers report `Up`. If either fails,
restore `.env.before` and both settings from `settings.before.json`, then clear
caches and restart again.

```bash
docker exec index-web-1 php artisan optimize:clear
docker restart index-web-1 index-horizon-1
docker ps --filter name=index-web-1 --filter name=index-horizon-1 --format '{{.Names}} {{.Status}}'
```

Rollback uses the recorded values rather than assumed defaults:

```bash
cp -a "$CUTOVER_BACKUP_DIR/.env.before" "$XBOARD_PATH/.env"
docker cp "$CUTOVER_BACKUP_DIR/settings.before.json" index-web-1:/tmp/settings.before.json
docker exec index-web-1 php artisan tinker --execute='$before = json_decode(file_get_contents("/tmp/settings.before.json"), true, 512, JSON_THROW_ON_ERROR); admin_setting($before);'
docker exec index-web-1 php artisan optimize:clear
docker restart index-web-1 index-horizon-1
```

### Task 4: Verify production and repository state

**Files:**
- Verify: production host, public HTTPS endpoints, local repository

- [ ] **Step 1: Verify effective server settings**

In `index-web-1`, confirm:

```text
config('app.url') = https://www.elephant111.org
admin_setting('app_url') = https://www.elephant111.org
```

Confirm `app_url_aliases` equals the four-value compatibility list and
`admin_setting('subscribe_url')` remains unchanged.

- [ ] **Step 2: Verify containers and queue processing**

Run `docker ps`, `php artisan horizon:status`, Redis `PING`, MariaDB container
status, and OpenResty configuration test. Expected: all scoped containers are
running, Horizon reports running, Redis returns `PONG`, and OpenResty reports
successful syntax.

- [ ] **Step 3: Verify new and compatibility routes**

Check these outcomes with `curl`:

```text
https://www.elephant111.org/ -> 302 /app#/login
https://www.elephant111.org/app -> 200
https://www.elephant111.org/api/v1/guest/domain/check -> 200 success
https://elephant111.org/ -> 301 https://www.elephant111.org/
https://www.elephant111.com/ -> not 403 and reaches its existing login behavior
https://www.elephant223.com/ -> not 403
https://www.elphantroute.com/ -> not 403
https://dx.elphantroute.com/ -> not 403
```

- [ ] **Step 4: Re-run focused repository verification**

Run:

```bash
node --test tests/client-primary-domain-contract.test.js tests/subscribe-url-generation.test.js
git diff --check
git status --short --branch
```

Expected: tests pass, no whitespace errors, and only the committed plan/client
changes remain ahead of `origin/master`.
