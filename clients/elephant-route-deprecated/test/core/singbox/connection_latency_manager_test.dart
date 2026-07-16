import 'package:elephant_network/core/singbox/connection_latency_manager.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('connection latency result exposes latency and elapsed time', () {
    const result = ConnectionLatencyResult(
      latencyMs: 253,
      elapsedMs: 1164,
      attempts: [1163, 253],
      source: ConnectionLatencySource.connectionProbe,
      httpStatusCodes: [204, 204],
      processExitCode: 0,
    );

    expect(result.isSuccess, isTrue);
    expect(result.latencyMs, 253);
    expect(result.elapsedMs, 1164);
    expect(result.attempts, [1163, 253]);
    expect(result.failureKind, isNull);
    expect(result.source, ConnectionLatencySource.connectionProbe);
    expect(result.httpStatusCodes, [204, 204]);
    expect(result.processExitCode, 0);
  });

  test('failed result keeps a typed reason and source', () {
    const result = ConnectionLatencyResult(
      latencyMs: -1,
      elapsedMs: 395,
      failureKind: ConnectionLatencyFailureKind.httpError,
      source: ConnectionLatencySource.clashFallback,
      httpStatusCodes: [503],
    );

    expect(result.isSuccess, isFalse);
    expect(result.failureKind, ConnectionLatencyFailureKind.httpError);
    expect(result.source, ConnectionLatencySource.clashFallback);
  });

  test('unavailable error preserves the user-facing readiness message', () {
    const error = ConnectionLatencyUnavailableException('测速服务未就绪');

    expect(error.message, '测速服务未就绪');
    expect(error.toString(), '测速服务未就绪');
  });
}
