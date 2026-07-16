import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:math';

import 'android_connection_probe.dart';
import 'android_latency_config.dart';
import 'connection_latency_manager.dart';

typedef AndroidSelectorUpdater = Future<void> Function({
  required String selectorTag,
  required String nodeTag,
  required Duration timeout,
});

class AndroidLatencySession {
  AndroidLatencySession({
    required List<int> workerPorts,
    AndroidSelectorUpdater? selectorUpdater,
    AndroidNodeProbe? probe,
  })  : _workerPorts = List<int>.unmodifiable(workerPorts),
        _selectorUpdater = selectorUpdater ?? _updateSelector,
        _probe = probe ?? AndroidConnectionProbe();

  final List<int> _workerPorts;
  final AndroidSelectorUpdater _selectorUpdater;
  final AndroidNodeProbe _probe;
  bool _stopped = false;

  Future<Map<String, ConnectionLatencyResult>> run({
    required List<String> nodeTags,
    required String testUrl,
    required int timeoutMs,
    required int concurrency,
    ConnectionLatencyResultCallback? onResult,
  }) async {
    if (_workerPorts.isEmpty) {
      throw StateError('Android latency workers are not ready');
    }
    final results = <String, ConnectionLatencyResult>{};
    var nextIndex = 0;
    final workerCount = min(
      min(4, max(1, concurrency)),
      min(_workerPorts.length, nodeTags.length),
    );

    Future<void> worker(int workerIndex) async {
      while (!_stopped) {
        final nodeIndex = nextIndex;
        if (nodeIndex >= nodeTags.length) return;
        nextIndex++;
        final nodeTag = nodeTags[nodeIndex];
        final stopwatch = Stopwatch()..start();
        ConnectionLatencyResult result;
        try {
          var remaining = Duration(milliseconds: timeoutMs) - stopwatch.elapsed;
          await _selectorUpdater(
            selectorTag: '$androidLatencySelectorPrefix$workerIndex',
            nodeTag: nodeTag,
            timeout: remaining,
          ).timeout(remaining);
          remaining = Duration(milliseconds: timeoutMs) - stopwatch.elapsed;
          if (_stopped || remaining <= Duration.zero) {
            throw TimeoutException('Android latency session timed out');
          }
          result = await _probe
              .run(
                proxyPort: _workerPorts[workerIndex],
                testUrl: testUrl,
                timeout: remaining,
              )
              .timeout(remaining);
        } on TimeoutException {
          result = ConnectionLatencyResult(
            latencyMs: -1,
            elapsedMs: stopwatch.elapsedMilliseconds,
            attempts: const <int>[-1, -1],
            failureKind: ConnectionLatencyFailureKind.timeout,
            source: ConnectionLatencySource.connectionProbe,
          );
        } catch (_) {
          result = ConnectionLatencyResult(
            latencyMs: -1,
            elapsedMs: stopwatch.elapsedMilliseconds,
            attempts: const <int>[-1, -1],
            failureKind: ConnectionLatencyFailureKind.serviceError,
            source: ConnectionLatencySource.connectionProbe,
          );
        } finally {
          stopwatch.stop();
        }
        results[nodeTag] = result;
        onResult?.call(nodeTag, result);
      }
    }

    if (workerCount > 0) {
      await Future.wait(List.generate(workerCount, worker));
    }
    for (final nodeTag in nodeTags) {
      if (!results.containsKey(nodeTag)) {
        const result = ConnectionLatencyResult(
          latencyMs: -1,
          elapsedMs: 0,
          attempts: <int>[-1, -1],
          failureKind: ConnectionLatencyFailureKind.serviceError,
          source: ConnectionLatencySource.connectionProbe,
        );
        results[nodeTag] = result;
        onResult?.call(nodeTag, result);
      }
    }
    return results;
  }

  Future<void> stop() async {
    _stopped = true;
    await _probe.stop();
  }

  static Future<void> _updateSelector({
    required String selectorTag,
    required String nodeTag,
    required Duration timeout,
  }) async {
    final client = HttpClient()..connectionTimeout = timeout;
    try {
      final uri = Uri.parse(
        'http://127.0.0.1:9090/proxies/${Uri.encodeComponent(selectorTag)}',
      );
      final request = await client.putUrl(uri).timeout(timeout);
      request.headers.contentType = ContentType.json;
      request.write(jsonEncode(<String, String>{'name': nodeTag}));
      final response = await request.close().timeout(timeout);
      await response.drain<void>().timeout(timeout);
      if (response.statusCode != HttpStatus.ok &&
          response.statusCode != HttpStatus.noContent) {
        throw HttpException(
          'Clash selector update failed: ${response.statusCode}',
          uri: uri,
        );
      }
    } finally {
      client.close(force: true);
    }
  }
}
