import 'package:elephant_network/core/singbox/connection_latency_manager.dart';
import 'package:elephant_network/utils/node_latency_display.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('formats successful latency', () {
    expect(nodeLatencyLabel(latency: 125), '125ms');
  });

  test('shows timeout only for deadline failures', () {
    expect(
      nodeLatencyLabel(
        latency: -1,
        result: const ConnectionLatencyResult(
          latencyMs: -1,
          elapsedMs: 5000,
          failureKind: ConnectionLatencyFailureKind.timeout,
        ),
      ),
      '超时',
    );
  });

  test('shows failure for fast HTTP and service errors', () {
    for (final kind in const [
      ConnectionLatencyFailureKind.httpError,
      ConnectionLatencyFailureKind.transportError,
      ConnectionLatencyFailureKind.serviceError,
    ]) {
      expect(
        nodeLatencyLabel(
          latency: -1,
          result: ConnectionLatencyResult(
            latencyMs: -1,
            elapsedMs: 395,
            failureKind: kind,
          ),
        ),
        '失败',
      );
    }
  });

  test('keeps legacy negative latency compatible as timeout', () {
    expect(nodeLatencyLabel(latency: -1), '超时');
  });
}
