import 'dart:async';
import 'dart:convert';

import 'connection_latency_manager.dart';
import 'windows_service_protocol.dart';

typedef WindowsLatencyJobInvoke = Future<Map<String, dynamic>> Function(
  String method,
  Map<String, dynamic> arguments,
);
typedef WindowsLatencyJobDelay = Future<void> Function(Duration duration);

final class WindowsLatencyJobRunner {
  WindowsLatencyJobRunner({
    required WindowsLatencyJobInvoke invoke,
    WindowsLatencyJobDelay? delay,
    this.pollInterval = const Duration(milliseconds: 250),
  })  : _invoke = invoke,
        _delay = delay ?? Future<void>.delayed;

  final WindowsLatencyJobInvoke _invoke;
  final WindowsLatencyJobDelay _delay;
  final Duration pollInterval;

  String? _activeRunId;

  Future<void> cancel() async {
    final runId = _activeRunId;
    if (runId == null) return;
    await _invoke('cancelLatencyTest', {'run_id': runId});
  }

  Future<Map<String, ConnectionLatencyResult>> run({
    required List<String> nodeTags,
    required String testUrl,
    required int timeoutMs,
    required int concurrency,
    required bool Function() isCancelled,
    ConnectionLatencyResultCallback? onResult,
  }) async {
    final start = WindowsServiceProtocol.parseLatencySnapshot(
      await _invoke('startLatencyTest', {
        'node_tags_json': jsonEncode(nodeTags),
        'test_url': testUrl,
        'timeout_ms': timeoutMs,
        'concurrency': concurrency,
      }),
    );
    final runId = start.runId;
    if (runId.isEmpty) {
      throw ConnectionLatencyUnavailableException(
        start.errorMessage ?? 'Windows 测速服务未返回任务编号',
      );
    }

    _activeRunId = runId;
    final published = <String>{};
    var snapshot = start;
    try {
      while (true) {
        if (isCancelled()) {
          snapshot = WindowsServiceProtocol.parseLatencySnapshot(
            await _invoke('cancelLatencyTest', {'run_id': runId}),
          );
          return _completeResults(
            nodeTags,
            snapshot,
            ConnectionLatencyFailureKind.cancelled,
          );
        }

        for (final entry in snapshot.results.entries) {
          if (published.add(entry.key)) {
            onResult?.call(entry.key, entry.value);
          }
        }

        switch (snapshot.status) {
          case WindowsLatencyJobStatus.running:
            await _delay(pollInterval);
            snapshot = WindowsServiceProtocol.parseLatencySnapshot(
              await _invoke('getLatencyTest', {'run_id': runId}),
            );
            if (snapshot.runId != runId) {
              throw const ConnectionLatencyUnavailableException(
                'Windows 测速任务已被替换',
              );
            }
          case WindowsLatencyJobStatus.completed:
            return _completeResults(
              nodeTags,
              snapshot,
              ConnectionLatencyFailureKind.serviceError,
            );
          case WindowsLatencyJobStatus.cancelled:
            return _completeResults(
              nodeTags,
              snapshot,
              ConnectionLatencyFailureKind.cancelled,
            );
          case WindowsLatencyJobStatus.error:
            throw ConnectionLatencyUnavailableException(
              snapshot.errorMessage ?? 'Windows 测速服务不可用',
            );
        }
      }
    } finally {
      if (_activeRunId == runId) {
        _activeRunId = null;
      }
    }
  }

  static Map<String, ConnectionLatencyResult> _completeResults(
    List<String> nodeTags,
    WindowsLatencySnapshot snapshot,
    ConnectionLatencyFailureKind missingFailure,
  ) {
    final results = <String, ConnectionLatencyResult>{
      ...snapshot.results,
    };
    for (final nodeTag in nodeTags) {
      results.putIfAbsent(
        nodeTag,
        () => ConnectionLatencyResult(
          latencyMs: -1,
          elapsedMs: 0,
          attempts: const <int>[-1],
          failureKind: missingFailure,
          source: ConnectionLatencySource.connectionProbe,
        ),
      );
    }
    return Map<String, ConnectionLatencyResult>.unmodifiable(results);
  }
}
