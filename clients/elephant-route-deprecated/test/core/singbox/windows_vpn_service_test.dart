import 'dart:convert';

import 'package:elephant_network/core/singbox/connection_latency_manager.dart';
import 'package:elephant_network/core/singbox/vpn_state.dart';
import 'package:elephant_network/core/singbox/windows_service_protocol.dart';
import 'package:elephant_network/core/singbox/windows_vpn_service.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  const methodChannel = MethodChannel(WindowsServiceProtocol.methodChannel);
  const eventChannel = MethodChannel(WindowsServiceProtocol.eventChannel);
  final messenger =
      TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger;
  final calls = <MethodCall>[];

  setUp(() {
    calls.clear();
    messenger.setMockMethodCallHandler(eventChannel, (_) async => null);
    messenger.setMockMethodCallHandler(methodChannel, (call) async {
      calls.add(call);
      if (call.method == 'urlTest') {
        return {'status': 'connected', 'delay': 42};
      }
      if (call.method == 'getNetworkProfile') {
        return {
          'status': 'ready',
          'default_interface': 'Ethernet',
          'tun_ipv4_address': '172.31.255.1/30',
          'strict_route': false,
        };
      }
      return {'status': call.method == 'stop' ? 'disconnected' : 'connected'};
    });
  });

  tearDown(() {
    messenger.setMockMethodCallHandler(eventChannel, null);
    messenger.setMockMethodCallHandler(methodChannel, null);
  });

  test('starts through the service with a forced TUN config', () async {
    final service = WindowsVpnService();
    await service.start(jsonEncode({
      'use_tun_mode': false,
      'dns': {'strategy': 'prefer_ipv4'},
      'inbounds': [
        {'type': 'mixed', 'listen_port': 2334},
      ],
      'outbounds': [
        {'type': 'direct', 'tag': 'direct'},
      ],
      'route': {'rules': <Object>[]},
    }));

    final startCall = calls.singleWhere((call) => call.method == 'start');
    final arguments = Map<String, dynamic>.from(startCall.arguments as Map);
    final config = jsonDecode(arguments['config'] as String) as Map;
    final inbounds = config['inbounds'] as List;
    expect(config.containsKey('use_tun_mode'), isFalse);
    expect(inbounds.where((item) => item['type'] == 'tun'), hasLength(1));
    expect(inbounds.where((item) => item['type'] == 'mixed'), isEmpty);
    final tun = inbounds.singleWhere((item) => item['type'] == 'tun') as Map;
    expect(tun['address'], ['172.31.255.1/30']);
    expect(tun['domain_strategy'], 'ipv4_only');
    expect(tun['endpoint_independent_nat'], isTrue);
    expect(tun['mtu'], 1500);
    expect(tun['sniff_override_destination'], isTrue);
    expect(tun['strict_route'], isFalse);
    expect((config['dns'] as Map)['strategy'], 'ipv4_only');
    final route = config['route'] as Map;
    final rules = route['rules'] as List;
    expect(rules.first, {
      'network': 'udp',
      'port': 443,
      'outbound': 'block',
    });
    expect(route['auto_detect_interface'], isFalse);
    expect(route['default_interface'], 'Ethernet');
    expect(service.currentState.status, VpnStatus.connected);
    expect(service.currentState.connectionMode, VpnConnectionMode.tun);
    service.dispose();
  });

  test('does not duplicate the Windows QUIC fallback rule', () async {
    final service = WindowsVpnService();
    await service.start(jsonEncode({
      'inbounds': <Object>[],
      'outbounds': [
        {'type': 'direct', 'tag': 'direct'},
        {'type': 'block', 'tag': 'block'},
      ],
      'route': {
        'rules': [
          {
            'network': 'udp',
            'port': 443,
            'outbound': 'block',
          },
          {
            'protocol': 'dns',
            'outbound': 'dns-out',
          },
        ],
      },
    }));

    final startCall = calls.singleWhere((call) => call.method == 'start');
    final arguments = Map<String, dynamic>.from(startCall.arguments as Map);
    final config = jsonDecode(arguments['config'] as String) as Map;
    final rules = (config['route'] as Map)['rules'] as List;
    final quicFallbackRules = rules.where(
      (rule) =>
          rule is Map &&
          rule['network'] == 'udp' &&
          rule['port'] == 443 &&
          rule['outbound'] == 'block',
    );

    expect(quicFallbackRules, hasLength(1));
    expect(rules.first, quicFallbackRules.single);
    service.dispose();
  });

  test('does not start when Windows has no usable default interface', () async {
    messenger.setMockMethodCallHandler(methodChannel, (call) async {
      calls.add(call);
      if (call.method == 'getNetworkProfile') {
        return {
          'status': 'error',
          'error_code': 'default_interface_missing',
          'error_message': 'No default interface',
        };
      }
      return {'status': 'connected'};
    });

    final service = WindowsVpnService();
    await service.start(jsonEncode({
      'inbounds': <Object>[],
      'outbounds': [
        {'type': 'direct', 'tag': 'direct'},
      ],
      'route': {'rules': <Object>[]},
    }));

    expect(calls.map((call) => call.method), ['getNetworkProfile']);
    expect(service.currentState.status, VpnStatus.error);
    expect(
      service.currentState.runtimeDetails?['error_code'],
      'default_interface_missing',
    );
    service.dispose();
  });

  test('preserves strict route on Windows 11 profiles', () async {
    messenger.setMockMethodCallHandler(methodChannel, (call) async {
      calls.add(call);
      if (call.method == 'getNetworkProfile') {
        return {
          'status': 'ready',
          'default_interface': 'Wi-Fi',
          'tun_ipv4_address': '172.31.255.1/30',
          'strict_route': true,
        };
      }
      return {'status': 'connected'};
    });

    final service = WindowsVpnService();
    await service.start(jsonEncode({
      'inbounds': <Object>[],
      'outbounds': [
        {'type': 'direct', 'tag': 'direct'},
      ],
      'route': {'rules': <Object>[]},
    }));

    final startCall = calls.singleWhere((call) => call.method == 'start');
    final arguments = Map<String, dynamic>.from(startCall.arguments as Map);
    final config = jsonDecode(arguments['config'] as String) as Map;
    final tun = (config['inbounds'] as List)
        .singleWhere((item) => item['type'] == 'tun') as Map;
    expect(tun['strict_route'], isTrue);
    service.dispose();
  });

  test('tests concrete nodes through the connected service core', () async {
    final callbacks = <String, ConnectionLatencyResult>{};
    final service = WindowsVpnService();
    await service.start(jsonEncode({
      'inbounds': <Object>[],
      'outbounds': [
        {'type': 'direct', 'tag': 'direct'},
      ],
      'route': {'rules': <Object>[]},
    }));

    final results = await service.testConnectionLatencies(
      nodeTags: const ['Tokyo', 'Osaka'],
      testUrl: 'https://www.gstatic.com/generate_204',
      timeoutMs: 5000,
      concurrency: 2,
      onResult: (nodeTag, result) => callbacks[nodeTag] = result,
    );

    final testedNodes = calls
        .where((call) => call.method == 'urlTest')
        .map((call) => (call.arguments as Map)['group_tag'])
        .toSet();
    expect(testedNodes, {'Tokyo', 'Osaka'});
    expect(results['Tokyo']?.latencyMs, 42);
    expect(results['Osaka']?.latencyMs, 42);
    expect(callbacks.keys, containsAll(const ['Tokyo', 'Osaka']));
    service.dispose();
  });

  test('uses service APIs for latency, outbound selection, and stop', () async {
    final service = WindowsVpnService();
    expect(await service.urlTest('节点选择'), 42);
    await service.selectOutbound('节点选择', 'Tokyo');
    await service.stop();

    expect(
      calls.map((call) => call.method),
      containsAllInOrder(['urlTest', 'selectOutbound', 'stop']),
    );
    expect(service.currentState.status, VpnStatus.disconnected);
    service.dispose();
  });
}
