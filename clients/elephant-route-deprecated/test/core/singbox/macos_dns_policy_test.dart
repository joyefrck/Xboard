import 'dart:convert';
import 'dart:io';

import 'package:elephant_network/core/singbox/macos_dns_policy.dart';
import 'package:elephant_network/core/singbox/macos_singbox_runtime.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('replaces only the default remote DNS with proxied IPv4 DoH', () {
    final config = <String, dynamic>{
      'dns': {
        'servers': [
          {
            'tag': 'remote',
            'address': '8.8.8.8',
            'detour': '节点选择',
          },
          {
            'tag': 'local',
            'address': '223.5.5.5',
            'detour': 'direct',
          },
        ],
      },
    };

    final result = MacosDnsPolicy.apply(config);
    final servers = ((result['dns'] as Map)['servers'] as List)
        .cast<Map<String, dynamic>>();

    expect(servers[0], {
      'tag': 'remote',
      'address': 'https://1.1.1.1/dns-query',
      'detour': '节点选择',
      'strategy': 'ipv4_only',
    });
    expect(servers[1], {
      'tag': 'local',
      'address': '223.5.5.5',
      'detour': 'direct',
    });
  });

  test('preserves a custom remote DNS value', () {
    final config = <String, dynamic>{
      'dns': {
        'servers': [
          {
            'tag': 'remote',
            'address': '9.9.9.9',
            'detour': '节点选择',
          },
        ],
      },
    };

    expect(MacosDnsPolicy.apply(config), config);
  });

  test('migrates the any-outbound DNS rule to the default resolver', () {
    final config = <String, dynamic>{
      'dns': {
        'servers': [
          {'tag': 'local', 'address': '223.5.5.5'},
        ],
        'rules': [
          {
            'outbound': ['any'],
            'server': 'local',
          },
          {'clash_mode': 'global', 'server': 'remote'},
        ],
      },
      'route': <String, dynamic>{},
    };

    MacosDnsPolicy.apply(config);

    expect((config['dns'] as Map)['rules'], [
      {'clash_mode': 'global', 'server': 'remote'},
    ]);
    expect(
      (config['route'] as Map)['default_domain_resolver'],
      'local',
    );
  });

  test('migrates a tagged outbound DNS rule onto that outbound', () {
    final config = <String, dynamic>{
      'dns': {
        'servers': [
          {'tag': 'local', 'address': '223.5.5.5'},
        ],
        'rules': [
          {'outbound': 'proxy-a', 'server': 'local'},
        ],
      },
      'outbounds': [
        {'type': 'socks', 'tag': 'proxy-a'},
        {'type': 'direct', 'tag': 'direct'},
      ],
    };

    MacosDnsPolicy.apply(config);

    expect((config['dns'] as Map)['rules'], isEmpty);
    expect((config['outbounds'] as List).first, {
      'type': 'socks',
      'tag': 'proxy-a',
      'domain_resolver': 'local',
    });
  });

  test('adds the proxy detour when the default remote DNS lacks one', () {
    final config = <String, dynamic>{
      'dns': {
        'servers': [
          {'tag': 'remote', 'address': '8.8.8.8'},
        ],
      },
    };

    final server =
        (((MacosDnsPolicy.apply(config)['dns'] as Map)['servers'] as List)
            .single as Map<String, dynamic>);

    expect(server['detour'], '节点选择');
    expect(server['strategy'], 'ipv4_only');
  });

  test('rewritten configuration passes the bundled sing-box check', () async {
    final config = MacosDnsPolicy.apply(<String, dynamic>{
      'log': {'level': 'warn'},
      'dns': {
        'servers': [
          {
            'tag': 'remote',
            'address': '8.8.8.8',
            'detour': '节点选择',
          },
        ],
      },
      'inbounds': [
        {
          'type': 'mixed',
          'tag': 'mixed-in',
          'listen': '127.0.0.1',
          'listen_port': 2334,
        },
      ],
      'outbounds': [
        {
          'type': 'selector',
          'tag': '节点选择',
          'outbounds': ['direct'],
          'default': 'direct',
        },
        {'type': 'direct', 'tag': 'direct'},
      ],
      'route': {'final': '节点选择'},
    });
    final directory = await Directory.systemTemp.createTemp('macos-dns-test-');
    addTearDown(() => directory.delete(recursive: true));
    final configFile = File('${directory.path}/config.json');
    await configFile.writeAsString(jsonEncode(config));

    final result = await Process.run(
      'assets/bin/sing-box-darwin-arm64',
      ['check', '-c', configFile.path],
      environment: {
        ...Platform.environment,
        ...MacosSingBoxRuntime.compatibilityEnvironment,
      },
    );

    expect(
      result.exitCode,
      0,
      reason: '${result.stdout}\n${result.stderr}',
    );
  });
}
