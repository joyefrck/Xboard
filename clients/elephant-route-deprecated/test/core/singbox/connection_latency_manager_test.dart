import 'package:elephant_network/core/singbox/connection_latency_manager.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('connection latency result exposes latency and elapsed time', () {
    const result = ConnectionLatencyResult(
      latencyMs: 253,
      elapsedMs: 1164,
      attempts: [1163, 253],
    );

    expect(result.latencyMs, 253);
    expect(result.elapsedMs, 1164);
    expect(result.attempts, [1163, 253]);
  });

  test('unavailable error preserves the user-facing readiness message', () {
    const error = ConnectionLatencyUnavailableException('测速服务未就绪');

    expect(error.message, '测速服务未就绪');
    expect(error.toString(), '测速服务未就绪');
  });
}
