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

  const result = spawnSync('php', ['-d', 'display_errors=0', '-r', phpScript], {
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
  const expected = 'anytls://test-password@[2001:db8::1]:443?sni=tls.example.com&insecure=0#AnyTLS%20Test%20%E8%8A%82%E7%82%B9\r\n';

  assert.ok(info.allowedProtocols.includes('anytls'));
  assert.equal(info.hasBuilder, true);
  assert.equal(info.line, expected);
  assert.equal(info.decodedResponse, expected);
});
