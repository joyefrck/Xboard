import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

void main() {
  test('macOS disposal releases Dart resources without stopping the tunnel',
      () {
    final source =
        File('lib/core/singbox/macos_vpn_service.dart').readAsStringSync();
    final disposeBody = RegExp(
      r'void dispose\(\) \{([\s\S]*?)\n  \}',
    ).firstMatch(source)!.group(1)!;

    expect(disposeBody, isNot(contains('stop()')));
    expect(disposeBody, contains('_stateController.close()'));
  });

  test('all macOS stop paths carry an explicit source', () {
    final runtime =
        File('lib/core/services/mac_runtime_service.dart').readAsStringSync();
    final macos =
        File('lib/core/singbox/macos_vpn_service.dart').readAsStringSync();
    final tray = File('lib/widgets/tray_controller.dart').readAsStringSync();
    final update = File('lib/core/api/services/app_update_service.dart')
        .readAsStringSync();

    expect(runtime, contains("{'reason': reason}"));
    expect(
      macos,
      contains(r'macOS runtime stop requested reason=${reason.wireValue}'),
    );
    expect(tray, contains('VpnStopReason.trayExit'));
    expect(update, contains("'reason': 'update_install'"));
  });

  test('macOS outbound selection never restarts the TUN runtime', () {
    final source =
        File('lib/core/singbox/macos_vpn_service.dart').readAsStringSync();
    final start = source.indexOf(
      'Future<void> selectOutbound(String groupTag, String outboundTag)',
    );
    final end = source.indexOf('\n  @override\n  void dispose()', start);

    expect(start, greaterThanOrEqualTo(0));
    expect(end, greaterThan(start));
    final selectBody = source.substring(start, end);

    expect(selectBody, contains('_clashController.selectOutbound'));
    expect(selectBody, isNot(contains('_runtime.stopCore')));
    expect(selectBody, isNot(contains('_runtime.startTunMode')));
    expect(selectBody, isNot(contains('VpnStatus.coreStarting')));
  });

  test('macOS connected latency stays on the live core', () {
    final source =
        File('lib/core/singbox/macos_vpn_service.dart').readAsStringSync();
    final start = source.indexOf(
      'Future<Map<String, ConnectionLatencyResult>> _runConnectionLatencies',
    );
    final end = source.indexOf(
      '\n  @override\n  Future<void> stopConnectionLatencyTest()',
      start,
    );
    final body = source.substring(start, end);

    expect(body, contains('_latencyFallbackRunner.resolve'));
    expect(body, contains('primaryResults: const {}'));
    expect(body, isNot(contains('MacosLatencySession(')));
    expect(body, isNot(contains('_runtime.stopCore')));
    expect(body, isNot(contains('_runtime.startTunMode')));
  });
}
