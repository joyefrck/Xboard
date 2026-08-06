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
    this.serviceCallTimeout = const Duration(seconds: 3),
    this.jobTimeout = const Duration(seconds: 60),
  })  : _invoke = invoke,
        _delay = delay ?? Future<void>.delayed {
    if (pollInterval <= Duration.zero) {
      throw ArgumentError.value(pollInterval, 'pollInterval');
    }
    if (serviceCallTimeout <= Duration.zero) {
      throw ArgumentError.value(serviceCallTimeout, 'serviceCallTimeout');
    }
    if (jobTimeout <= Duration.zero) {
      throw ArgumentError.value(jobTimeout, 'jobTimeout');
    }
  }

  final WindowsLatencyJobInvoke _invoke;
  final WindowsLatencyJobDelay _delay;
  final Duration pollInterval;
  final Duration serviceCallTimeout;
  final Duration jobTimeout;

  String? _activeRunId;

  Future<void> cancel() async {
    final runId = _activeRunId;
    if (runId == null) return;
    await _cancelBestEffort(runId);
  }

  Future<Map<String, ConnectionLatencyResult>> run({
    required List<String> nodeTags,
    required String testUrl,
    required int timeoutMs,
    required int concurrency,
    required bool Function() isCancelled,
    ConnectionLatencyResultCallback? onResult,
  }) async {
    final deadline = DateTime.now().add(jobTimeout);
    WindowsLatencySnapshot? snapshot;
    var runId = '';
    final published = <String>{};
    try {
      var current = WindowsServiceProtocol.parseLatencySnapshot(
        await _invokeBeforeDeadline(
          'startLatencyTest',
          {
            'node_tags_json': jsonEncode(nodeTags),
            'test_url': testUrl,
            'timeout_ms': timeoutMs,
            'concurrency': concurrency,
          },
          deadline,
        ),
      );
      snapshot = current;
      runId = current.runId;
      if (runId.isEmpty) {
        throw ConnectionLatencyUnavailableException(
          current.errorMessage ?? 'Windows 测速服务未返回任务编号',
        );
      }
      _activeRunId = runId;

      while (true) {
        if (isCancelled()) {
          current = WindowsServiceProtocol.parseLatencySnapshot(
            await _invokeBeforeDeadline(
              'cancelLatencyTest',
              {'run_id': runId},
              deadline,
            ),
          );
          snapshot = current;
          return _completeResults(
            nodeTags,
            current,
            ConnectionLatencyFailureKind.cancelled,
          );
        }

        for (final entry in current.results.entries) {
          if (published.add(entry.key)) {
            onResult?.call(entry.key, entry.value);
          }
        }

        switch (current.status) {
          case WindowsLatencyJobStatus.running:
            await _waitBeforeDeadline(pollInterval, deadline);
            current = WindowsServiceProtocol.parseLatencySnapshot(
              await _invokeBeforeDeadline(
                'getLatencyTest',
                {'run_id': runId},
                deadline,
              ),
            );
            snapshot = current;
            if (current.runId != runId) {
              throw const ConnectionLatencyUnavailableException(
                'Windows 测速任务已被替换',
              );
            }
          case WindowsLatencyJobStatus.completed:
            return _completeResults(
              nodeTags,
              current,
              ConnectionLatencyFailureKind.serviceError,
            );
          case WindowsLatencyJobStatus.cancelled:
            return _completeResults(
              nodeTags,
              current,
              ConnectionLatencyFailureKind.cancelled,
            );
          case WindowsLatencyJobStatus.error:
            throw ConnectionLatencyUnavailableException(
              current.errorMessage ?? 'Windows 测速服务不可用',
            );
        }
      }
    } on TimeoutException {
      await _cancelBestEffort(runId);
      final timedOutSnapshot = snapshot ??
          WindowsLatencySnapshot(
            runId: runId,
            status: WindowsLatencyJobStatus.error,
            completed: 0,
            total: nodeTags.length,
            results: const <String, ConnectionLatencyResult>{},
            errorCode: 'latency_timeout',
            errorMessage: 'Windows 测速服务响应超时',
          );
      final results = _completeResults(
        nodeTags,
        timedOutSnapshot,
        ConnectionLatencyFailureKind.timeout,
      );
      for (final entry in results.entries) {
        if (published.add(entry.key)) {
          onResult?.call(entry.key, entry.value);
        }
      }
      return results;
    } finally {
      if (_activeRunId == runId) {
        _activeRunId = null;
      }
    }
  }

  Future<Map<String, dynamic>> _invokeBeforeDeadline(
    String method,
    Map<String, dynamic> arguments,
    DateTime deadline,
  ) {
    final remaining = deadline.difference(DateTime.now());
    if (remaining <= Duration.zero) {
      throw TimeoutException('Windows latency job timed out');
    }
    final timeout =
        remaining < serviceCallTimeout ? remaining : serviceCallTimeout;
    return _invoke(method, arguments).timeout(timeout);
  }

  Future<void> _waitBeforeDeadline(
    Duration duration,
    DateTime deadline,
  ) {
    final remaining = deadline.difference(DateTime.now());
    if (remaining <= Duration.zero) {
      throw TimeoutException('Windows latency job timed out');
    }
    return _delay(duration).timeout(remaining);
  }

  Future<void> _cancelBestEffort(String runId) async {
    try {
      await _invoke(
        'cancelLatencyTest',
        {'run_id': runId},
      ).timeout(serviceCallTimeout);
    } on Object {
      // Cancellation is best-effort; the provider generation still rejects
      // any result arriving after this runner has returned.
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
