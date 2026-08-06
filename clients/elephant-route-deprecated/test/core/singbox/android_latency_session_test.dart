import 'dart:async';

import 'package:elephant_network/core/singbox/android_connection_probe.dart';
import 'package:elephant_network/core/singbox/android_latency_session.dart';
import 'package:elephant_network/core/singbox/connection_latency_manager.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('uses hidden selectors and never exceeds four concurrent nodes',
      () async {
    final selected = <String>[];
    final probe = _TrackingProbe();
    final session = AndroidLatencySession(
      workerPorts: const [31001, 31002, 31003, 31004],
      selectorUpdater: ({
        required selectorTag,
        required nodeTag,
        required timeout,
      }) async {
        selected.add('$selectorTag:$nodeTag');
      },
      probe: probe,
    );

    final results = await session.run(
      nodeTags: List.generate(8, (index) => 'node-$index'),
      testUrl: 'https://www.gstatic.com/generate_204',
      timeoutMs: 5000,
      concurrency: 4,
    );

    expect(results, hasLength(8));
    expect(probe.maxActive, 4);
    expect(probe.nodeTags, List.generate(8, (index) => 'node-$index'));
    expect(selected, hasLength(8));
    expect(selected.first, '__elephant_latency_worker_0:node-0');
  });

  test('marks the node as service error when selector update fails', () async {
    final session = AndroidLatencySession(
      workerPorts: const [31001],
      selectorUpdater: ({
        required selectorTag,
        required nodeTag,
        required timeout,
      }) async {
        throw StateError('selector unavailable');
      },
      probe: _TrackingProbe(),
    );

    final results = await session.run(
      nodeTags: const ['node-a'],
      testUrl: 'https://www.gstatic.com/generate_204',
      timeoutMs: 5000,
      concurrency: 4,
    );

    expect(results['node-a']?.latencyMs, -1);
    expect(
      results['node-a']?.failureKind,
      ConnectionLatencyFailureKind.serviceError,
    );
  });

  test('selector switching and probing share one total timeout', () async {
    final probe = _TrackingProbe(delay: const Duration(seconds: 1));
    final session = AndroidLatencySession(
      workerPorts: const [31001],
      selectorUpdater: ({
        required selectorTag,
        required nodeTag,
        required timeout,
      }) async {
        await Future<void>.delayed(const Duration(milliseconds: 25));
      },
      probe: probe,
    );
    final stopwatch = Stopwatch()..start();

    final results = await session.run(
      nodeTags: const ['node-a'],
      testUrl: 'https://www.gstatic.com/generate_204',
      timeoutMs: 50,
      concurrency: 4,
    );

    expect(results['node-a']?.latencyMs, -1);
    expect(stopwatch.elapsedMilliseconds, lessThan(300));
  });

  test('stop cancels owned probes and marks unfinished nodes as cancelled',
      () async {
    final probe = _CancelableProbe();
    final session = AndroidLatencySession(
      workerPorts: const [31001, 31002, 31003, 31004],
      selectorUpdater: ({
        required selectorTag,
        required nodeTag,
        required timeout,
      }) async {},
      probe: probe,
    );
    final running = session.run(
      nodeTags: const ['node-a', 'node-b', 'node-c', 'node-d', 'node-e'],
      testUrl: 'https://www.gstatic.com/generate_204',
      timeoutMs: 5000,
      concurrency: 4,
    );
    await probe.started.future;

    await session.stop();
    final results = await running;

    expect(probe.stopped, isTrue);
    expect(results, hasLength(5));
    expect(results.values.every((result) => result.latencyMs == -1), isTrue);
    expect(
      results.values.every(
        (result) =>
            result.failureKind == ConnectionLatencyFailureKind.cancelled,
      ),
      isTrue,
    );
  });
}

class _TrackingProbe implements AndroidNodeProbe {
  _TrackingProbe({this.delay = const Duration(milliseconds: 15)});

  final Duration delay;
  var active = 0;
  var maxActive = 0;
  var stopped = false;
  final nodeTags = <String>[];

  @override
  Future<ConnectionLatencyResult> run({
    required String nodeTag,
    required int proxyPort,
    required String testUrl,
    required Duration timeout,
  }) async {
    nodeTags.add(nodeTag);
    active++;
    if (active > maxActive) maxActive = active;
    await Future<void>.delayed(delay);
    active--;
    return ConnectionLatencyResult(
      latencyMs: stopped ? -1 : 100,
      elapsedMs: 15,
      attempts: stopped ? const [-1] : const [150, 100],
      failureKind: stopped ? ConnectionLatencyFailureKind.cancelled : null,
    );
  }

  @override
  Future<void> stop() async {
    stopped = true;
  }
}

class _CancelableProbe implements AndroidNodeProbe {
  final started = Completer<void>();
  final _stopSignal = Completer<void>();
  bool stopped = false;

  @override
  Future<ConnectionLatencyResult> run({
    required String nodeTag,
    required int proxyPort,
    required String testUrl,
    required Duration timeout,
  }) async {
    if (!started.isCompleted) started.complete();
    await _stopSignal.future;
    return const ConnectionLatencyResult(
      latencyMs: -1,
      elapsedMs: 0,
      attempts: <int>[-1, -1],
      failureKind: ConnectionLatencyFailureKind.cancelled,
    );
  }

  @override
  Future<void> stop() async {
    stopped = true;
    if (!_stopSignal.isCompleted) _stopSignal.complete();
  }
}
