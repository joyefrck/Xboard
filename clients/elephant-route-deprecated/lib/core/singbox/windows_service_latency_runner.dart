import 'dart:async';
import 'dart:math';

import 'connection_latency_manager.dart';

typedef WindowsServiceLatencyProbe = Future<int> Function(String nodeTag);
typedef WindowsServiceLatencyCancellationCheck = bool Function();

final class WindowsServiceLatencyRunner {
  const WindowsServiceLatencyRunner({
    required WindowsServiceLatencyProbe probe,
  }) : _probe = probe;

  final WindowsServiceLatencyProbe _probe;

  Future<Map<String, ConnectionLatencyResult>> run({
    required List<String> nodeTags,
    required int timeoutMs,
    required int concurrency,
    required WindowsServiceLatencyCancellationCheck isCancelled,
    ConnectionLatencyResultCallback? onResult,
  }) async {
    final results = <String, ConnectionLatencyResult>{};
    var nextIndex = 0;
    final workerCount = min(
      min(4, max(1, concurrency)),
      nodeTags.length,
    );
    final timeout = Duration(milliseconds: max(1, timeoutMs));

    Future<void> worker() async {
      while (!isCancelled()) {
        final nodeIndex = nextIndex;
        if (nodeIndex >= nodeTags.length) return;
        nextIndex++;
        final nodeTag = nodeTags[nodeIndex];
        final stopwatch = Stopwatch()..start();
        ConnectionLatencyResult result;
        try {
          final delay = await _probe(nodeTag).timeout(timeout);
          result = isCancelled()
              ? _cancelledResult(stopwatch.elapsedMilliseconds)
              : ConnectionLatencyResult(
                  latencyMs: delay > 0 ? delay : -1,
                  elapsedMs: stopwatch.elapsedMilliseconds,
                  attempts: <int>[delay],
                  failureKind: delay > 0
                      ? null
                      : ConnectionLatencyFailureKind.serviceError,
                  source: ConnectionLatencySource.clashFallback,
                );
        } on TimeoutException {
          result = isCancelled()
              ? _cancelledResult(stopwatch.elapsedMilliseconds)
              : ConnectionLatencyResult(
                  latencyMs: -1,
                  elapsedMs: stopwatch.elapsedMilliseconds,
                  attempts: const <int>[-1],
                  failureKind: ConnectionLatencyFailureKind.timeout,
                  source: ConnectionLatencySource.clashFallback,
                );
        } catch (_) {
          result = isCancelled()
              ? _cancelledResult(stopwatch.elapsedMilliseconds)
              : ConnectionLatencyResult(
                  latencyMs: -1,
                  elapsedMs: stopwatch.elapsedMilliseconds,
                  attempts: const <int>[-1],
                  failureKind: ConnectionLatencyFailureKind.serviceError,
                  source: ConnectionLatencySource.clashFallback,
                );
        } finally {
          stopwatch.stop();
        }
        results[nodeTag] = result;
        if (!isCancelled() &&
            result.failureKind != ConnectionLatencyFailureKind.cancelled) {
          onResult?.call(nodeTag, result);
        }
      }
    }

    if (workerCount > 0) {
      await Future.wait(List.generate(workerCount, (_) => worker()));
    }
    for (final nodeTag in nodeTags) {
      results.putIfAbsent(nodeTag, () => _cancelledResult(0));
    }
    return results;
  }

  static ConnectionLatencyResult _cancelledResult(int elapsedMs) {
    return ConnectionLatencyResult(
      latencyMs: -1,
      elapsedMs: elapsedMs,
      attempts: const <int>[-1],
      failureKind: ConnectionLatencyFailureKind.cancelled,
      source: ConnectionLatencySource.clashFallback,
    );
  }
}
