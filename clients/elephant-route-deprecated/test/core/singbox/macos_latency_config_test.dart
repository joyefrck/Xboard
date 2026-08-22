import 'dart:convert';

import 'package:elephant_network/core/singbox/macos_latency_config.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  const source = {
    'use_tun_mode': true,
    'log': {'level': 'info'},
    'dns': {
      'servers': [
        {
          'tag': 'dns-direct',
          'address': '1.1.1.1',
          'detour': '节点选择',
        }
      ]
    },
    'inbounds': [
      {'type': 'tun', 'tag': 'tun-in', 'auto_route': true},
      {
        'type': 'mixed',
        'tag': 'mixed-in',
        'listen': '127.0.0.1',
        'listen_port': 2334,
      },
    ],
    'outbounds': [
      {
        'type': 'vless',
        'tag': 'node-a',
        'server': 'node-a.invalid',
        'server_port': 443,
        'uuid': 'redacted-a',
      },
      {
        'type': 'anytls',
        'tag': 'node-b',
        'server': 'node-b.invalid',
        'server_port': 443,
        'password': 'redacted-b',
      },
      {'type': 'direct', 'tag': 'direct'},
      {
        'type': 'selector',
        'tag': '节点选择',
        'outbounds': ['node-a', 'node-b'],
        'default': 'node-a',
      },
    ],
    'route': {
      'rules': [
        {'protocol': 'dns', 'outbound': 'direct'}
      ],
      'final': '节点选择',
    },
    'experimental': {
      'clash_api': {'external_controller': '127.0.0.1:9090'},
      'cache_file': {'enabled': true, 'path': '/tmp/cache.db'},
    },
  };

  test('builds isolated loopback workers without TUN or shared cache', () {
    final sourceText = jsonEncode(source);
    final output = MacosLatencyConfigBuilder.build(
      sourceConfig: sourceText,
      nodeTags: const ['node-a', 'node-b'],
      workerPorts: const [31001, 31002],
      clashApiPort: 31003,
      defaultInterface: 'en0',
    );

    expect(output.containsKey('use_tun_mode'), isFalse);
    expect(output['inbounds'], [
      {
        'type': 'mixed',
        'tag': '__elephant_latency_in_0',
        'listen': '127.0.0.1',
        'listen_port': 31001,
      },
      {
        'type': 'mixed',
        'tag': '__elephant_latency_in_1',
        'listen': '127.0.0.1',
        'listen_port': 31002,
      },
    ]);
    expect(
      output['experimental']['clash_api']['external_controller'],
      '127.0.0.1:31003',
    );
    expect(output['experimental'].containsKey('cache_file'), isFalse);
    expect(output['route']['rules'], [
      {
        'inbound': ['__elephant_latency_in_0'],
        'action': 'resolve',
        'strategy': 'ipv4_only',
      },
      {
        'inbound': ['__elephant_latency_in_0'],
        'action': 'route',
        'outbound': '__elephant_latency_worker_0',
      },
      {
        'inbound': ['__elephant_latency_in_1'],
        'action': 'resolve',
        'strategy': 'ipv4_only',
      },
      {
        'inbound': ['__elephant_latency_in_1'],
        'action': 'route',
        'outbound': '__elephant_latency_worker_1',
      },
    ]);
    expect(output['route']['final'], 'direct');
    expect(output['route']['auto_detect_interface'], isFalse);
    expect(output['route']['default_interface'], 'en0');
    expect(output['dns']['servers'][0]['detour'], 'direct');
    expect(
      (output['outbounds'] as List<dynamic>).any(
        (item) => (item as Map<String, dynamic>)['tag'] == '节点选择',
      ),
      isFalse,
    );
    expect(jsonDecode(sourceText), source);
  });

  test('appends one private selector per worker with concrete nodes only', () {
    final output = MacosLatencyConfigBuilder.build(
      sourceConfig: jsonEncode(source),
      nodeTags: const ['node-a', 'node-b'],
      workerPorts: const [31001, 31002, 31003, 31004],
      clashApiPort: 31005,
      defaultInterface: 'en0',
    );

    final selectors = (output['outbounds'] as List<dynamic>)
        .where((item) =>
            (item as Map<String, dynamic>)['tag']
                ?.toString()
                .startsWith('__elephant_latency_worker_') ==
            true)
        .cast<Map<String, dynamic>>()
        .toList();
    expect(selectors, hasLength(4));
    for (final selector in selectors) {
      expect(selector['type'], 'selector');
      expect(selector['outbounds'], ['node-a', 'node-b']);
      expect(selector['default'], 'node-a');
    }
  });

  test('rejects unknown and non-concrete node tags', () {
    expect(
      () => MacosLatencyConfigBuilder.build(
        sourceConfig: jsonEncode(source),
        nodeTags: const ['missing'],
        workerPorts: const [31001],
        clashApiPort: 31002,
        defaultInterface: 'en0',
      ),
      throwsArgumentError,
    );
    expect(
      () => MacosLatencyConfigBuilder.build(
        sourceConfig: jsonEncode(source),
        nodeTags: const ['节点选择'],
        workerPorts: const [31001],
        clashApiPort: 31002,
        defaultInterface: 'en0',
      ),
      throwsArgumentError,
    );
  });

  test('discovers only concrete proxy tags', () {
    expect(
      MacosLatencyConfigBuilder.concreteProxyTags(jsonEncode(source)),
      {'node-a', 'node-b'},
    );
  });

  test('rejects missing and tunnel default interfaces', () {
    for (final interface in ['', 'utun7', 'en0;unsafe']) {
      expect(
        () => MacosLatencyConfigBuilder.build(
          sourceConfig: jsonEncode(source),
          nodeTags: const ['node-a'],
          workerPorts: const [31001],
          clashApiPort: 31002,
          defaultInterface: interface,
        ),
        throwsArgumentError,
      );
    }
  });

  test('adds live workers without changing the main TUN selector', () {
    final result = MacosLatencyConfigBuilder.addLiveWorkers(
      sourceConfig: jsonEncode(source),
      workerPorts: const [32001, 32002, 32003, 32004],
    );
    final output = jsonDecode(result.configJson) as Map<String, dynamic>;
    final outbounds = (output['outbounds'] as List).cast<Map>();
    final workers = outbounds.where((item) =>
        item['tag'].toString().startsWith('__elephant_latency_worker_'));
    final mainSelector = outbounds.singleWhere((item) => item['tag'] == '节点选择');

    expect(workers, hasLength(4));
    expect(workers.first['outbounds'], ['node-a', 'node-b']);
    expect(mainSelector['default'], 'node-a');
    expect(result.nodeTags, ['node-a', 'node-b']);

    final inbounds = (output['inbounds'] as List).cast<Map>();
    expect(inbounds.any((item) => item['type'] == 'tun'), isTrue);
    expect(
      inbounds.where((item) =>
          item['tag'].toString().startsWith('__elephant_latency_in_')),
      hasLength(4),
    );
    final rules = (output['route']['rules'] as List).cast<Map>();
    expect(rules.first, {
      'inbound': ['__elephant_latency_in_0'],
      'action': 'resolve',
      'strategy': 'ipv4_only',
    });
    expect(rules.last, {'protocol': 'dns', 'outbound': 'direct'});
  });

  test('replaces stale live workers and rejects invalid ports', () {
    final first = MacosLatencyConfigBuilder.addLiveWorkers(
      sourceConfig: jsonEncode(source),
      workerPorts: const [32001, 32002, 32003, 32004],
    );
    final second = MacosLatencyConfigBuilder.addLiveWorkers(
      sourceConfig: first.configJson,
      workerPorts: const [33001, 33002, 33003, 33004],
    );
    final output = jsonDecode(second.configJson) as Map<String, dynamic>;
    expect(
      (output['outbounds'] as List).where((item) =>
          item['tag'].toString().startsWith('__elephant_latency_worker_')),
      hasLength(4),
    );
    expect(
      (output['inbounds'] as List).where((item) =>
          item['tag'].toString().startsWith('__elephant_latency_in_')),
      hasLength(4),
    );
    for (final ports in const <List<int>>[
      [],
      [0],
      [31001, 31001],
    ]) {
      expect(
        () => MacosLatencyConfigBuilder.addLiveWorkers(
          sourceConfig: jsonEncode(source),
          workerPorts: ports,
        ),
        throwsFormatException,
      );
    }
  });
}
