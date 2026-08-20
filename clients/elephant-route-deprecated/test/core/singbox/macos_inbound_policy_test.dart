import 'dart:convert';
import 'dart:io';

import 'package:elephant_network/core/singbox/macos_inbound_policy.dart';
import 'package:elephant_network/core/singbox/macos_dns_policy.dart';
import 'package:elephant_network/core/singbox/macos_outbound_policy.dart';
import 'package:elephant_network/core/singbox/macos_singbox_runtime.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  final installedConfigPath = Platform.environment['XBOARD_MACOS_CONFIG_PATH'];
  test('migrates legacy inbound fields to ordered route actions', () {
    final config = <String, dynamic>{
      'inbounds': [
        {
          'type': 'tun',
          'sniff': true,
          'sniff_override_destination': true,
          'domain_strategy': 'prefer_ipv4',
          'inet4_address': '172.19.0.1/30',
          'inet6_address': 'fdfe:dcba:9876::1/126',
        },
        {
          'type': 'mixed',
          'tag': 'mixed-in',
          'listen': '127.0.0.1',
          'listen_port': 2334,
          'sniff': true,
          'sniff_timeout': '500ms',
        },
      ],
      'route': {
        'rules': [
          {'protocol': 'dns', 'action': 'hijack-dns'},
        ],
      },
    };

    MacosInboundPolicy.apply(config);

    final inbounds = (config['inbounds'] as List).cast<Map>();
    expect(inbounds[0]['tag'], 'tun-in');
    expect(inbounds[0]['address'], [
      '172.19.0.1/30',
      'fdfe:dcba:9876::1/126',
    ]);
    expect(inbounds[0], isNot(contains('inet4_address')));
    expect(inbounds[0], isNot(contains('inet6_address')));
    for (final inbound in inbounds) {
      expect(inbound, isNot(contains('sniff')));
      expect(inbound, isNot(contains('sniff_override_destination')));
      expect(inbound, isNot(contains('sniff_timeout')));
      expect(inbound, isNot(contains('domain_strategy')));
    }
    expect((config['route'] as Map)['rules'], [
      {
        'inbound': 'tun-in',
        'action': 'resolve',
        'strategy': 'prefer_ipv4',
      },
      {'inbound': 'tun-in', 'action': 'sniff'},
      {
        'inbound': 'mixed-in',
        'action': 'sniff',
        'timeout': '500ms',
      },
      {'protocol': 'dns', 'action': 'hijack-dns'},
    ]);
  });

  test('is idempotent after legacy fields have been removed', () {
    final config = <String, dynamic>{
      'inbounds': [
        {'type': 'mixed', 'tag': 'mixed-in'},
      ],
      'route': {
        'rules': [
          {'inbound': 'mixed-in', 'action': 'sniff'},
        ],
      },
    };

    MacosInboundPolicy.apply(config);

    expect((config['route'] as Map)['rules'], [
      {'inbound': 'mixed-in', 'action': 'sniff'},
    ]);
  });

  test(
    'migrated representative config passes the bundled core check',
    () async {
      final config = <String, dynamic>{
        'log': {'level': 'warn'},
        'dns': {
          'servers': [
            {'tag': 'local', 'address': '223.5.5.5'},
          ],
        },
        'inbounds': [
          {
            'type': 'mixed',
            'tag': 'mixed-in',
            'listen': '127.0.0.1',
            'listen_port': 2334,
            'sniff': true,
            'sniff_override_destination': true,
            'domain_strategy': 'prefer_ipv4',
          },
        ],
        'outbounds': [
          {'type': 'direct', 'tag': 'direct'},
        ],
        'route': {'final': 'direct', 'rules': <dynamic>[]},
      };
      MacosInboundPolicy.apply(config);
      final directory = await Directory.systemTemp.createTemp(
        'macos-inbound-policy-',
      );
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
    },
    skip: !Platform.isMacOS,
  );

  test(
    'migrates the installed sanitized config for the patched core',
    () async {
      final source = jsonDecode(
        await File(installedConfigPath!).readAsString(),
      ) as Map<String, dynamic>;
      MacosDnsPolicy.apply(source);
      MacosInboundPolicy.apply(source);
      MacosOutboundPolicy.apply(source);
      final directory = await Directory.systemTemp.createTemp(
        'macos-installed-config-',
      );
      addTearDown(() => directory.delete(recursive: true));
      final migratedConfig = File('${directory.path}/config.json');
      await migratedConfig.writeAsString(jsonEncode(source));

      final result = await Process.run(
        'assets/bin/sing-box-darwin-arm64',
        ['check', '-c', migratedConfig.path],
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
    },
    skip: installedConfigPath == null || !Platform.isMacOS,
  );
}
