const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const test = require('node:test');
const assert = require('node:assert/strict');

const repoRoot = path.resolve(__dirname, '..');

function readRepoFile(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function buildStashVless(protocolSettings) {
  const phpScript = `
    require "vendor/autoload.php";

    $protocolSettings = json_decode(base64_decode($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    $server = [
        "name" => "vless-tcp-node",
        "type" => "vless",
        "host" => "example.com",
        "port" => 443,
        "password" => "00000000-0000-0000-0000-000000000000",
        "protocol_settings" => $protocolSettings,
    ];

    $reflection = new ReflectionClass(App\\Protocols\\Stash::class);
    $stash = $reflection->newInstanceWithoutConstructor();
    $proxy = $stash->buildVless("00000000-0000-0000-0000-000000000000", $server);

    echo json_encode($proxy, JSON_THROW_ON_ERROR);
  `;

  const result = spawnSync(
    'php',
    [
      '-d',
      'display_errors=0',
      '-r',
      phpScript,
      Buffer.from(JSON.stringify(protocolSettings)).toString('base64'),
    ],
    {
      cwd: repoRoot,
      encoding: 'utf8',
    }
  );

  assert.equal(result.status, 0, result.stderr || result.stdout);
  return JSON.parse(result.stdout);
}

function inspectStash() {
  const phpScript = `
    require "vendor/autoload.php";

    $reflection = new ReflectionClass(App\\Protocols\\Stash::class);
    $instance = $reflection->newInstanceWithoutConstructor();
    $allowed = new ReflectionProperty(App\\Protocols\\Stash::class, "allowedProtocols");

    echo json_encode([
        "allowed" => $allowed->getValue($instance),
        "hasBuildAnyTLS" => method_exists(App\\Protocols\\Stash::class, "buildAnyTLS"),
    ], JSON_THROW_ON_ERROR);
  `;

  const result = spawnSync('php', ['-r', phpScript], {
    cwd: repoRoot,
    encoding: 'utf8',
  });

  assert.equal(result.status, 0, result.stderr || result.stdout);
  return JSON.parse(result.stdout);
}

function renderStashAnyTLS(protocolSettings) {
  const phpScript = `
    require "vendor/autoload.php";
    $app = require "bootstrap/app.php";
    $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
    $app->instance(
        "request",
        Illuminate\\Http\\Request::create("https://subscription.example/api/v1/client/subscribe")
    );

    $protocolSettings = json_decode(base64_decode($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    $server = [
        "name" => "AnyTLS 测试节点",
        "type" => "anytls",
        "host" => "2001:db8::1",
        "port" => 443,
        "password" => "anytls-password",
        "protocol_settings" => $protocolSettings,
    ];
    $user = [
        "u" => 0,
        "d" => 0,
        "transfer_enable" => 1024,
        "effective_transfer_enable" => 1024,
        "expired_at" => 0,
    ];

    $response = (new App\\Protocols\\Stash($user, [$server], "stash", "3.4.0"))->handle();
    $config = Symfony\\Component\\Yaml\\Yaml::parse($response->getContent());
    echo json_encode($config["proxies"][0] ?? null, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
  `;

  const result = spawnSync(
    'php',
    [
      '-d',
      'display_errors=0',
      '-r',
      phpScript,
      Buffer.from(JSON.stringify(protocolSettings)).toString('base64'),
    ],
    {
      cwd: repoRoot,
      encoding: 'utf8',
    }
  );

  assert.equal(result.status, 0, result.stderr || result.stdout);
  return JSON.parse(result.stdout);
}

test('Stash renderer allows AnyTLS and maps canonical TLS settings', () => {
  const info = inspectStash();
  const proxy = renderStashAnyTLS({
    tls: {
      server_name: 'sni.example.com',
      allow_insecure: true,
    },
  });

  assert.ok(info.allowed.includes('anytls'));
  assert.equal(info.hasBuildAnyTLS, true);
  assert.deepEqual(proxy, {
    name: 'AnyTLS 测试节点',
    type: 'anytls',
    server: '2001:db8::1',
    port: 443,
    password: 'anytls-password',
    sni: 'sni.example.com',
    'skip-cert-verify': true,
    udp: true,
  });
});

test('Stash VLESS tcp http header renders network as a string', () => {
  const proxy = buildStashVless({
    tls: 0,
    network: 'tcp',
    network_settings: {
      header: {
        type: 'http',
        request: {
          headers: {
            Host: ['example.com'],
          },
          path: ['/'],
        },
      },
    },
  });

  assert.equal(proxy.network, 'http');
  assert.equal(typeof proxy.network, 'string');
  assert.deepEqual(proxy['http-opts'], {
    headers: {
      Host: ['example.com'],
    },
    path: ['/'],
  });
});

test('Stash VLESS default tcp header does not render boolean network', () => {
  const explicitTcpProxy = buildStashVless({
    tls: 0,
    network: 'tcp',
    network_settings: {
      header: {
        type: 'tcp',
      },
    },
  });

  const defaultTcpProxy = buildStashVless({
    tls: 0,
    network: 'tcp',
    network_settings: {},
  });

  assert.notEqual(typeof explicitTcpProxy.network, 'boolean');
  assert.equal(Object.hasOwn(explicitTcpProxy, 'network'), false);
  assert.notEqual(typeof defaultTcpProxy.network, 'boolean');
  assert.equal(Object.hasOwn(defaultTcpProxy, 'network'), false);
});

test('Stash VLESS tcp network assignment is not mixed with comparison', () => {
  const source = readRepoFile('app/Protocols/Stash.php');

  assert.doesNotMatch(
    source,
    /if\s*\(\s*\$headerType\s*=\s*data_get\([^)]*\)\s*!=\s*'tcp'\s*\)/
  );
});
