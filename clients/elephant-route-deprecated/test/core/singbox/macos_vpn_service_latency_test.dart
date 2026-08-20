import 'package:elephant_network/core/singbox/connection_latency_manager.dart';
import 'package:elephant_network/core/singbox/macos_latency_fallback.dart';
import 'package:elephant_network/core/singbox/macos_vpn_service.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../test_bootstrap.dart';

void main() {
  configureTestEnvironment();

  test('macOS VPN service exposes isolated connection latency capability', () {
    final service = MacosVpnService();

    expect(service, isA<ConnectionLatencyManager>());

    service.dispose();
  });

  test('stopping an idle latency session is idempotent', () async {
    final service = MacosVpnService();

    await service.stopConnectionLatencyTest();
    await service.stopSpeedTest();

    service.dispose();
  });

  test('streams live Clash results without an isolated config', () async {
    final callbacks = <String, ConnectionLatencyResult>{};
    final runner = MacosLatencyFallbackRunner(
      concurrency: 4,
      probe: (nodeTag, testUrl, timeoutMs) async => ConnectionLatencyResult(
        latencyMs: nodeTag == 'node-a' ? 120 : 180,
        elapsedMs: 10,
        source: ConnectionLatencySource.clashFallback,
      ),
    );
    final service = MacosVpnService(latencyFallbackRunner: runner);

    final results = await service.testConnectionLatencies(
      nodeTags: const ['node-a', 'node-b'],
      testUrl: 'https://www.gstatic.com/generate_204',
      timeoutMs: 5000,
      concurrency: 4,
      onResult: (nodeTag, result) => callbacks[nodeTag] = result,
    );

    expect(results.keys, containsAll(['node-a', 'node-b']));
    expect(callbacks.keys, containsAll(['node-a', 'node-b']));
    expect(callbacks['node-a']?.latencyMs, 120);
    expect(callbacks['node-b']?.latencyMs, 180);
    service.dispose();
  });

  test('publishes a typed live Clash failure immediately', () async {
    final callbacks = <ConnectionLatencyResult>[];
    final runner = MacosLatencyFallbackRunner(
      concurrency: 4,
      probe: (nodeTag, testUrl, timeoutMs) async =>
          const ConnectionLatencyResult(
        latencyMs: -1,
        elapsedMs: 5000,
        failureKind: ConnectionLatencyFailureKind.timeout,
        source: ConnectionLatencySource.clashFallback,
      ),
    );
    final service = MacosVpnService(latencyFallbackRunner: runner);

    await service.testConnectionLatencies(
      nodeTags: const ['node-timeout'],
      testUrl: 'https://www.gstatic.com/generate_204',
      timeoutMs: 5000,
      concurrency: 4,
      onResult: (nodeTag, result) => callbacks.add(result),
    );

    expect(callbacks, hasLength(1));
    expect(
      callbacks.single.failureKind,
      ConnectionLatencyFailureKind.timeout,
    );
    service.dispose();
  });

  test('latency lifecycle deadlines must be positive', () {
    expect(
      () => MacosVpnService(latencyRunTimeout: Duration.zero),
      throwsArgumentError,
    );
  });

  test('uses both built-in targets but preserves a custom target', () async {
    final requestedUrls = <String>[];
    final runner = MacosLatencyFallbackRunner(
      probe: (nodeTag, testUrl, timeoutMs) async {
        requestedUrls.add(testUrl);
        return ConnectionLatencyResult(
          latencyMs: testUrl.contains('cloudflare') ? -1 : 180,
          elapsedMs: 10,
          failureKind: testUrl.contains('cloudflare')
              ? ConnectionLatencyFailureKind.httpError
              : null,
          source: ConnectionLatencySource.clashFallback,
          httpStatusCodes:
              testUrl.contains('cloudflare') ? const [503] : const [200],
        );
      },
    );
    final service = MacosVpnService(latencyFallbackRunner: runner);

    await service.testConnectionLatencies(
      nodeTags: const ['built-in'],
      testUrl: 'https://cp.cloudflare.com/generate_204',
      timeoutMs: 5000,
      concurrency: 4,
    );
    expect(requestedUrls, [
      'https://cp.cloudflare.com/generate_204',
      'https://www.gstatic.com/generate_204',
    ]);

    requestedUrls.clear();
    await service.testConnectionLatencies(
      nodeTags: const ['custom'],
      testUrl: 'https://example.com/custom_204',
      timeoutMs: 5000,
      concurrency: 4,
    );
    expect(requestedUrls, ['https://example.com/custom_204']);

    service.dispose();
  });
}
