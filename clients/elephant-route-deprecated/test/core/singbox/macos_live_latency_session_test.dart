import 'dart:async';

import 'package:elephant_network/core/singbox/connection_latency_manager.dart';
import 'package:elephant_network/core/singbox/macos_live_latency_session.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('limits probes to four live-core workers', () async {
    var inFlight = 0;
    var maxInFlight = 0;
    final session = MacosLiveLatencySession(
      workerPorts: const [31001, 31002, 31003, 31004],
      selectorUpdater: ({
        required selectorTag,
        required nodeTag,
        required timeout,
      }) async {},
      probe: ({required proxyPort, required testUrl, required timeout}) async {
        inFlight++;
        maxInFlight = inFlight > maxInFlight ? inFlight : maxInFlight;
        await Future<void>.delayed(const Duration(milliseconds: 20));
        inFlight--;
        return const ConnectionLatencyResult(
          latencyMs: 66,
          elapsedMs: 100,
          attempts: [200, 66],
        );
      },
      logger: (_) async {},
    );

    final results = await session.run(
      nodeTags: const ['a', 'b', 'c', 'd', 'e'],
      testUrl: 'https://www.gstatic.com/generate_204',
      timeoutMs: 5000,
      concurrency: 8,
    );

    expect(results, hasLength(5));
    expect(maxInFlight, 4);
    await session.stop();
  });

  test('selects private worker before probing and streams results', () async {
    final events = <String>[];
    final callbacks = <String>[];
    final session = MacosLiveLatencySession(
      workerPorts: const [31001],
      selectorUpdater: ({
        required selectorTag,
        required nodeTag,
        required timeout,
      }) async {
        events.add('select:$selectorTag:$nodeTag');
      },
      probe: ({required proxyPort, required testUrl, required timeout}) async {
        events.add('probe:$proxyPort');
        return const ConnectionLatencyResult(
          latencyMs: 66,
          elapsedMs: 100,
          attempts: [200, 66],
        );
      },
      logger: (_) async {},
    );

    await session.run(
      nodeTags: const ['node-a', 'node-b'],
      testUrl: 'https://www.gstatic.com/generate_204',
      timeoutMs: 5000,
      concurrency: 1,
      onResult: (nodeTag, result) => callbacks.add(nodeTag),
    );

    expect(events, [
      'select:__elephant_latency_worker_0:node-a',
      'probe:31001',
      'select:__elephant_latency_worker_0:node-b',
      'probe:31001',
    ]);
    expect(callbacks, ['node-a', 'node-b']);
    await session.stop();
  });

  test('stop prevents remaining nodes from being probed', () async {
    late MacosLiveLatencySession session;
    var probes = 0;
    session = MacosLiveLatencySession(
      workerPorts: const [31001],
      selectorUpdater: ({
        required selectorTag,
        required nodeTag,
        required timeout,
      }) async {},
      probe: ({required proxyPort, required testUrl, required timeout}) async {
        probes++;
        await session.stop();
        return const ConnectionLatencyResult(
          latencyMs: 66,
          elapsedMs: 100,
          attempts: [200, 66],
        );
      },
      logger: (_) async {},
    );

    final results = await session.run(
      nodeTags: const ['node-a', 'node-b'],
      testUrl: 'https://www.gstatic.com/generate_204',
      timeoutMs: 5000,
      concurrency: 1,
    );

    expect(probes, 1);
    expect(results['node-a']?.latencyMs, 66);
    expect(
      results['node-b']?.failureKind,
      ConnectionLatencyFailureKind.cancelled,
    );
  });
}
