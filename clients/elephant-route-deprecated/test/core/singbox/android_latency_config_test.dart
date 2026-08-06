import 'dart:convert';

import 'package:elephant_network/core/singbox/android_latency_config.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  const sourceConfig = '''
  {
    "inbounds": [{"type":"tun","tag":"tun-in","address":["172.19.0.1/30"]}],
    "outbounds": [
      {"type":"selector","tag":"proxy","outbounds":["Tokyo AnyTLS"],"default":"Tokyo AnyTLS"},
      {"type":"anytls","tag":"Tokyo AnyTLS","server":"example.com","server_port":443,"password":"secret","tls":{"enabled":true,"server_name":"example.com"}},
      {"type":"vless","tag":"Los Angeles","server":"la.example.com","server_port":443,"uuid":"uuid"},
      {"type":"direct","tag":"direct"}
    ],
    "route": {"rules":[{"protocol":"dns","outbound":"direct"}],"final":"proxy"},
    "experimental":{"clash_api":{"external_controller":"127.0.0.1:9090"}}
  }
  ''';

  test('preserves AnyTLS credentials and adds four isolated workers', () {
    final result = AndroidLatencyConfigBuilder.build(
      sourceConfig: sourceConfig,
      workerPorts: const [31001, 31002, 31003, 31004],
    );

    final config = jsonDecode(result.configJson) as Map<String, dynamic>;
    final outbounds = (config['outbounds'] as List).cast<Map>();
    final anyTls = outbounds.singleWhere((item) => item['type'] == 'anytls');
    expect(anyTls['password'], 'secret');
    expect(anyTls['tls'], {
      'enabled': true,
      'server_name': 'example.com',
    });

    final mainSelector =
        outbounds.singleWhere((item) => item['tag'] == 'proxy');
    expect(mainSelector['default'], 'Tokyo AnyTLS');

    final workers = outbounds
        .where((item) =>
            item['tag']?.toString().startsWith('__elephant_latency_worker_') ??
            false)
        .toList();
    expect(workers, hasLength(4));
    expect(workers.first['outbounds'], ['Tokyo AnyTLS', 'Los Angeles']);

    final inbounds = (config['inbounds'] as List).cast<Map>();
    expect(inbounds.any((item) => item['type'] == 'tun'), isTrue);
    final latencyInbounds =
        inbounds.where((item) => item['type'] == 'mixed').toList();
    expect(latencyInbounds, hasLength(4));
    expect(latencyInbounds.first['listen'], '127.0.0.1');
    expect(latencyInbounds.first['listen_port'], 31001);

    final rules = (config['route']['rules'] as List).cast<Map>();
    expect(
      rules.take(2),
      [
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
      ],
    );
    expect(
      rules.where((rule) => rule['action'] == 'resolve'),
      hasLength(4),
    );
    expect(rules.last, {'protocol': 'dns', 'outbound': 'direct'});
    expect(result.nodeTags, ['Tokyo AnyTLS', 'Los Angeles']);
  });

  test('rebuilding removes stale hidden workers instead of duplicating them',
      () {
    final first = AndroidLatencyConfigBuilder.build(
      sourceConfig: sourceConfig,
      workerPorts: const [31001, 31002, 31003, 31004],
    );
    final second = AndroidLatencyConfigBuilder.build(
      sourceConfig: first.configJson,
      workerPorts: const [32001, 32002, 32003, 32004],
    );
    final config = jsonDecode(second.configJson) as Map<String, dynamic>;

    final workers = (config['outbounds'] as List).where((item) =>
        item['tag'].toString().startsWith('__elephant_latency_worker_'));
    final inbounds = (config['inbounds'] as List).where(
        (item) => item['tag'].toString().startsWith('__elephant_latency_in_'));
    expect(workers, hasLength(4));
    expect(inbounds, hasLength(4));
  });

  test('rejects a configuration without concrete proxy outbounds', () {
    expect(
      () => AndroidLatencyConfigBuilder.build(
        sourceConfig: '{"outbounds":[{"type":"direct","tag":"direct"}]}',
        workerPorts: const [31001],
      ),
      throwsA(isA<FormatException>()),
    );
  });
}
