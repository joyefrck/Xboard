import 'package:elephant_network/core/singbox/vpn_state.dart';
import 'package:elephant_network/core/singbox/windows_service_protocol.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  group('WindowsServiceProtocol', () {
    test('parses connected TUN state and traffic', () {
      final state = WindowsServiceProtocol.parseState({
        'status': 'connected',
        'up_speed': 120,
        'down_speed': 340,
        'total_up': 1000,
        'total_down': 2000,
        'latency_map': {'Tokyo': 38, 'Los Angeles': '142'},
      });

      expect(state.status, VpnStatus.connected);
      expect(state.connectionMode, VpnConnectionMode.tun);
      expect(state.upSpeed, 120);
      expect(state.downSpeed, 340);
      expect(state.totalUp, 1000);
      expect(state.totalDown, 2000);
      expect(state.latencyMap, {'Tokyo': 38, 'Los Angeles': 142});
    });

    test('maps native error codes to stable failure reasons', () {
      final state = WindowsServiceProtocol.parseState({
        'status': 'error',
        'error_code': 'tun_conflict',
        'error_message': 'another TUN adapter owns the route',
      });

      expect(state.status, VpnStatus.error);
      expect(state.failureReason, VpnFailureReason.routeConflict);
      expect(state.errorMessage, contains('TUN'));

      final missingInterface = WindowsServiceProtocol.parseState({
        'status': 'error',
        'error_code': 'default_interface_missing',
        'error_message': 'No default interface',
      });
      expect(
        missingInterface.failureReason,
        VpnFailureReason.coreStartFailed,
      );

      const expectedReasons = <String, VpnFailureReason>{
        'control_port_in_use': VpnFailureReason.coreStartFailed,
        'core_config_invalid': VpnFailureReason.invalidConfig,
        'tun_start_failed': VpnFailureReason.routeConflict,
        'core_blocked_or_crashed': VpnFailureReason.coreStartFailed,
        'control_api_timeout': VpnFailureReason.coreStartFailed,
        'core_start_timeout': VpnFailureReason.coreStartFailed,
      };
      for (final entry in expectedReasons.entries) {
        final diagnosticState = WindowsServiceProtocol.parseState({
          'status': 'error',
          'error_code': entry.key,
          'error_message': 'diagnostic',
          'core_exit_code': 1,
        });
        expect(
          diagnosticState.failureReason,
          entry.value,
          reason: entry.key,
        );
        expect(diagnosticState.runtimeDetails?['core_exit_code'], 1);
      }
    });

    test('rejects empty and oversized configs', () {
      expect(
        () => WindowsServiceProtocol.validateConfig('  '),
        throwsFormatException,
      );
      expect(
        () => WindowsServiceProtocol.validateConfig(
          'x' * (WindowsServiceProtocol.maxConfigBytes + 1),
        ),
        throwsFormatException,
      );
    });

    test('turns malformed service output into an error state', () {
      final state = WindowsServiceProtocol.parseState('not-a-map');

      expect(state.status, VpnStatus.error);
      expect(state.failureReason, VpnFailureReason.unknown);
    });

    test('supports bounded service-owned latency jobs', () {
      expect(
        WindowsServiceProtocol.supportedMethods,
        containsAll(const [
          'startLatencyTest',
          'getLatencyTest',
          'cancelLatencyTest',
        ]),
      );
      final snapshot = WindowsServiceProtocol.parseLatencySnapshot({
        'run_id': 'run-1',
        'latency_test_status': 'running',
        'latency_completed': 1,
        'latency_total': 2,
        'latency_results_json':
            '{"Tokyo":{"latency_ms":82,"elapsed_ms":190,"attempts":[168,82],"http_status_codes":[204,204]}}',
      });

      expect(snapshot.runId, 'run-1');
      expect(snapshot.completed, 1);
      expect(snapshot.total, 2);
      expect(snapshot.results['Tokyo']?.latencyMs, 82);
      expect(snapshot.results['Tokyo']?.attempts, [168, 82]);
    });
  });
}
