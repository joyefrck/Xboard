import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:math';

import '../services/app_logger.dart';
import 'connection_latency_manager.dart';
import 'macos_curl_connection_probe.dart';
import 'macos_latency_config.dart';

typedef MacosLiveLatencySelectorUpdater = Future<void> Function({
  required String selectorTag,
  required String nodeTag,
  required Duration timeout,
});
typedef MacosLiveLatencyProbe = Future<ConnectionLatencyResult> Function({
  required int proxyPort,
  required String testUrl,
  required Duration timeout,
});
typedef MacosLiveLatencyLogger = Future<void> Function(String message);

class MacosLiveLatencySession {
  MacosLiveLatencySession({
    required List<int> workerPorts,
    MacosLiveLatencySelectorUpdater? selectorUpdater,
    MacosLiveLatencyProbe? probe,
    MacosLiveLatencyLogger? logger,
  })  : _workerPorts = List<int>.unmodifiable(workerPorts),
        _selectorUpdater = selectorUpdater ?? _updateSelector,
        _probe = probe,
        _logger = logger ?? AppLogger.instance.info {
    if (_workerPorts.isEmpty) {
      throw ArgumentError.value(
          workerPorts, 'workerPorts', 'must not be empty');
    }
  }

  final List<int> _workerPorts;
  final MacosLiveLatencySelectorUpdater _selectorUpdater;
  final MacosLiveLatencyProbe? _probe;
  final MacosLiveLatencyLogger _logger;
  final Set<Process> _activeCurlProcesses = <Process>{};
  bool _stopped = false;

  Future<Map<String, ConnectionLatencyResult>> run({
    required List<String> nodeTags,
    required String testUrl,
    required int timeoutMs,
    required int concurrency,
    ConnectionLatencyResultCallback? onResult,
  }) async {
    final results = <String, ConnectionLatencyResult>{};
    var nextIndex = 0;
    final workerCount = min(
      min(4, max(1, concurrency)),
      min(_workerPorts.length, nodeTags.length),
    );

    void record(String nodeTag, ConnectionLatencyResult result) {
      results[nodeTag] = result;
      onResult?.call(nodeTag, result);
    }

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
            selectorTag:
                '${MacosLatencyConfigBuilder.workerPrefix}$workerIndex',
            nodeTag: nodeTag,
            timeout: remaining,
          ).timeout(remaining);
          remaining = Duration(milliseconds: timeoutMs) - stopwatch.elapsed;
          if (_stopped || remaining <= Duration.zero) {
            throw TimeoutException('macOS live latency session stopped');
          }
          final probe = _probe;
          result = probe == null
              ? await MacosCurlConnectionProbe.run(
                  proxyPort: _workerPorts[workerIndex],
                  testUrl: testUrl,
                  timeout: remaining,
                  onProcessStarted: _activeCurlProcesses.add,
                  onProcessFinished: _activeCurlProcesses.remove,
                ).timeout(remaining)
              : await probe(
                  proxyPort: _workerPorts[workerIndex],
                  testUrl: testUrl,
                  timeout: remaining,
                ).timeout(remaining);
        } on TimeoutException {
          result = ConnectionLatencyResult(
            latencyMs: -1,
            elapsedMs: stopwatch.elapsedMilliseconds,
            attempts: const <int>[-1, -1],
            failureKind: _stopped
                ? ConnectionLatencyFailureKind.cancelled
                : ConnectionLatencyFailureKind.timeout,
            source: ConnectionLatencySource.connectionProbe,
          );
        } catch (_) {
          result = ConnectionLatencyResult(
            latencyMs: -1,
            elapsedMs: stopwatch.elapsedMilliseconds,
            attempts: const <int>[-1, -1],
            failureKind: _stopped
                ? ConnectionLatencyFailureKind.cancelled
                : ConnectionLatencyFailureKind.serviceError,
            source: ConnectionLatencySource.connectionProbe,
          );
        } finally {
          stopwatch.stop();
        }
        record(nodeTag, result);
        await _logger(
          'macOS live-worker latency node=$nodeTag '
          'attempts=${result.attempts.join(',')} '
          'latency=${result.latencyMs}ms elapsed=${result.elapsedMs}ms '
          'failure=${result.failureKind?.name ?? 'none'}',
        );
      }
    }

    if (workerCount > 0) {
      await Future.wait(List.generate(workerCount, worker));
    }
    for (final nodeTag in nodeTags) {
      if (!results.containsKey(nodeTag)) {
        record(
          nodeTag,
          ConnectionLatencyResult(
            latencyMs: -1,
            elapsedMs: 0,
            attempts: const <int>[-1, -1],
            failureKind: _stopped
                ? ConnectionLatencyFailureKind.cancelled
                : ConnectionLatencyFailureKind.serviceError,
            source: ConnectionLatencySource.connectionProbe,
          ),
        );
      }
    }
    return Map<String, ConnectionLatencyResult>.unmodifiable(results);
  }

  Future<void> stop() async {
    if (_stopped) return;
    _stopped = true;
    for (final process in _activeCurlProcesses.toList(growable: false)) {
      process.kill(ProcessSignal.sigterm);
    }
    if (_activeCurlProcesses.isNotEmpty) {
      await Future<void>.delayed(const Duration(milliseconds: 200));
      for (final process in _activeCurlProcesses.toList(growable: false)) {
        process.kill(ProcessSignal.sigkill);
      }
    }
  }

  static Future<void> _updateSelector({
    required String selectorTag,
    required String nodeTag,
    required Duration timeout,
  }) async {
    final client = HttpClient()
      ..connectionTimeout = timeout
      ..findProxy = (_) => 'DIRECT';
    try {
      final encodedSelector = Uri.encodeComponent(selectorTag);
      final request = await client.putUrl(
        Uri.parse('http://127.0.0.1:9090/proxies/$encodedSelector'),
      );
      request.headers.contentType = ContentType.json;
      request.write(jsonEncode(<String, String>{'name': nodeTag}));
      final response = await request.close().timeout(timeout);
      await response.drain<void>().timeout(timeout);
      if (response.statusCode != HttpStatus.ok &&
          response.statusCode != HttpStatus.noContent) {
        throw HttpException(
          'Clash selector update failed: ${response.statusCode}',
        );
      }
    } finally {
      client.close(force: true);
    }
  }
}
