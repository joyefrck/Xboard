# v2rayN AnyTLS Subscription Compatibility Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Include standard AnyTLS share links in Xboard subscriptions rendered for v2rayN.

**Architecture:** Keep v2rayN on the existing `General` Base64 URI renderer. Extend that renderer's whitelist, dispatch match, and builder, then prove the complete PHP rendering path with a focused Node regression test using synthetic node data.

**Tech Stack:** PHP 8, Laravel, Node.js built-in test runner

---

### Task 1: Lock the v2rayN AnyTLS regression

**Files:**
- Create: `tests/v2rayn-anytls-subscription-compatibility.test.js`

- [ ] **Step 1: Write the failing test**

Create a Node test that boots the Laravel app in a PHP subprocess, checks both
v2rayN routing shapes, introspects the General renderer, and renders a synthetic
IPv6 AnyTLS node:

```js
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const test = require('node:test');
const assert = require('node:assert/strict');

const repoRoot = path.resolve(__dirname, '..');

function inspectV2rayNAnyTLS() {
  const phpScript = `
    require "vendor/autoload.php";
    $app = require "bootstrap/app.php";
    $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();

    $manager = app("protocols.manager");
    $server = [
        "name" => "AnyTLS Test 节点",
        "type" => App\\Models\\Server::TYPE_ANYTLS,
        "host" => "2001:db8::1",
        "port" => 443,
        "password" => "test-password",
        "protocol_settings" => [
            "tls" => [
                "server_name" => "tls.example.com",
                "allow_insecure" => false,
            ],
        ],
    ];

    $reflection = new ReflectionClass(App\\Protocols\\General::class);
    $general = $reflection->newInstanceWithoutConstructor();
    $hasBuilder = method_exists(App\\Protocols\\General::class, "buildAnyTLS");
    $line = $hasBuilder
        ? App\\Protocols\\General::buildAnyTLS("test-password", $server)
        : null;

    $protocol = new App\\Protocols\\General(
        ["uuid" => "test-user"],
        [$server],
        "v2rayn",
        "7.24.4"
    );

    echo json_encode([
        "flagRenderer" => $manager->matchProtocolClassName("v2rayn"),
        "userAgentRenderer" => $manager->matchProtocolClassName("v2rayN/7.24.4"),
        "allowedProtocols" => $general->allowedProtocols,
        "hasBuilder" => $hasBuilder,
        "line" => $line,
        "decodedResponse" => base64_decode($protocol->handle()->getContent()),
    ], JSON_THROW_ON_ERROR);
  `;

  const result = spawnSync('php', ['-r', phpScript], {
    cwd: repoRoot,
    encoding: 'utf8',
  });

  assert.equal(result.status, 0, result.stderr || result.stdout);
  return JSON.parse(result.stdout);
}

test('v2rayN flag and user agent route to the General renderer', () => {
  const info = inspectV2rayNAnyTLS();

  assert.equal(info.flagRenderer, 'App\\Protocols\\General');
  assert.equal(info.userAgentRenderer, 'App\\Protocols\\General');
});

test('General renderer includes standard AnyTLS share links for v2rayN', () => {
  const info = inspectV2rayNAnyTLS();
  const expected = 'anytls://test-password@[2001:db8::1]:443?sni=tls.example.com&insecure=0#AnyTLS%20Test%20%E8%8A%82%E7%82%B9\\r\\n';

  assert.ok(info.allowedProtocols.includes('anytls'));
  assert.equal(info.hasBuilder, true);
  assert.equal(info.line, expected);
  assert.equal(info.decodedResponse, expected);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run:

```bash
node --test tests/v2rayn-anytls-subscription-compatibility.test.js
```

Expected: the routing test passes and the AnyTLS rendering test fails because
`allowedProtocols` lacks `anytls`, `hasBuilder` is false, and the decoded
response is empty.

### Task 2: Extend the General renderer

**Files:**
- Modify: `app/Protocols/General.php:14-45`
- Modify: `app/Protocols/General.php` before `buildSocks()`

- [ ] **Step 1: Add AnyTLS to the whitelist and dispatch match**

Add `Server::TYPE_ANYTLS` after Hysteria in both lists:

```php
public $allowedProtocols = [
    Server::TYPE_VMESS,
    Server::TYPE_VLESS,
    Server::TYPE_SHADOWSOCKS,
    Server::TYPE_TROJAN,
    Server::TYPE_HYSTERIA,
    Server::TYPE_ANYTLS,
    Server::TYPE_SOCKS,
];
```

```php
Server::TYPE_HYSTERIA => self::buildHysteria($item['password'], $item),
Server::TYPE_ANYTLS => self::buildAnyTLS($item['password'], $item),
Server::TYPE_SOCKS => self::buildSocks($item['password'], $item),
```

- [ ] **Step 2: Add the standard AnyTLS URI builder**

```php
public static function buildAnyTLS($password, $server)
{
    $protocol_settings = $server['protocol_settings'];
    $name = rawurlencode($server['name']);
    $params = [
        'sni' => data_get($protocol_settings, 'tls.server_name'),
        'insecure' => data_get($protocol_settings, 'tls.allow_insecure')
    ];
    $query = http_build_query($params);
    $addr = Helper::wrapIPv6($server['host']);
    $uri = "anytls://{$password}@{$addr}:{$server['port']}?{$query}#{$name}";
    $uri .= "\r\n";
    return $uri;
}
```

- [ ] **Step 3: Run focused checks**

Run:

```bash
php -l app/Protocols/General.php
node --test tests/v2rayn-anytls-subscription-compatibility.test.js
```

Expected: PHP reports no syntax errors and both Node subtests pass.

### Task 3: Verify runtime behavior and scope

**Files:**
- Verify: `app/Protocols/General.php`
- Verify: `tests/v2rayn-anytls-subscription-compatibility.test.js`

- [ ] **Step 1: Restart the mounted local Octane web process**

Run:

```bash
docker compose restart web
docker compose ps web
```

Expected: `xboard-web-1` returns to `Up` state on port 7001.

- [ ] **Step 2: Run a synthetic mounted-container probe**

Execute `General` with an AnyTLS-only server for client `v2rayn` version
`7.24.4` and Base64-decode the response.

Expected decoded line:

```text
anytls://test-password@[2001:db8::1]:443?sni=tls.example.com&insecure=0#AnyTLS%20Test%20%E8%8A%82%E7%82%B9
```

- [ ] **Step 3: Run the focused compatibility suite and inspect the diff**

Run:

```bash
node --test \
  tests/v2rayn-anytls-subscription-compatibility.test.js \
  tests/karing-subscription-compatibility.test.js \
  tests/stash-subscription-compatibility.test.js
git diff --check
git diff -- app/Protocols/General.php tests/v2rayn-anytls-subscription-compatibility.test.js
```

Expected: all subtests pass, `git diff --check` exits zero, and the diff is
limited to the General renderer and its dedicated regression test.
