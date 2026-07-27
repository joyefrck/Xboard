import 'dart:async';

import 'package:elephant_network/core/singbox/connection_latency_manager.dart';
import 'package:elephant_network/core/singbox/windows_service_latency_runner.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('returns successful and failed service-core delays per node', () async {
    final callbacks = <String, ConnectionLatencyResult>{};
    final runner = WindowsServiceLatencyRunner(
      probe: (nodeTag) async => nodeTag == 'Tokyo' ? 36 : -1,
    );

    final results = await runner.run(
      nodeTags: const ['Tokyo', 'Osaka'],
      timeoutMs: 5000,
      concurrency: 2,
      isCancelled: () => false,
      onResult: (nodeTag, result) => callbacks[nodeTag] = result,
    );

    expect(results.keys, containsAll(const ['Tokyo', 'Osaka']));
    expect(results['Tokyo']?.latencyMs, 36);
    expect(results['Tokyo']?.isSuccess, isTrue);
    expect(
      results['Tokyo']?.source,
      ConnectionLatencySource.clashFallback,
    );
    expect(results['Osaka']?.latencyMs, -1);
    expect(
      results['Osaka']?.failureKind,
      ConnectionLatencyFailureKind.serviceError,
    );
    expect(callbacks.keys, containsAll(const ['Tokyo', 'Osaka']));
  });

  test('classifies a bounded service call as timeout', () async {
    final runner = WindowsServiceLatencyRunner(
      probe: (_) => Completer<int>().future,
    );

    final results = await runner.run(
      nodeTags: const ['Tokyo'],
      timeoutMs: 10,
      concurrency: 1,
      isCancelled: () => false,
    );

    expect(
      results['Tokyo']?.failureKind,
      ConnectionLatencyFailureKind.timeout,
    );
  });

  test('caps worker concurrency at four', () async {
    var active = 0;
    var maximumActive = 0;
    final runner = WindowsServiceLatencyRunner(
      probe: (nodeTag) async {
        active++;
        if (active > maximumActive) maximumActive = active;
        await Future<void>.delayed(const Duration(milliseconds: 5));
        active--;
        return 20;
      },
    );

    final results = await runner.run(
      nodeTags: List.generate(12, (index) => 'node-$index'),
      timeoutMs: 5000,
      concurrency: 12,
      isCancelled: () => false,
    );

    expect(results, hasLength(12));
    expect(maximumActive, 4);
  });

  test('marks unfinished nodes cancelled without stale callbacks', () async {
    var cancelled = false;
    final callbacks = <String>[];
    final firstProbeStarted = Completer<void>();
    final releaseFirstProbe = Completer<void>();
    final runner = WindowsServiceLatencyRunner(
      probe: (nodeTag) async {
        firstProbeStarted.complete();
        await releaseFirstProbe.future;
        return 25;
      },
    );

    final run = runner.run(
      nodeTags: const ['Tokyo', 'Osaka'],
      timeoutMs: 5000,
      concurrency: 1,
      isCancelled: () => cancelled,
      onResult: (nodeTag, _) => callbacks.add(nodeTag),
    );
    await firstProbeStarted.future;
    cancelled = true;
    releaseFirstProbe.complete();
    final results = await run;

    expect(callbacks, isEmpty);
    expect(
      results.values.map((result) => result.failureKind),
      everyElement(ConnectionLatencyFailureKind.cancelled),
    );
  });
}
