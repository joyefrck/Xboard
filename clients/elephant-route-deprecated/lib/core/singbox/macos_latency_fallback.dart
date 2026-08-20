import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:math';

import 'package:dio/dio.dart';

import 'connection_latency_manager.dart';

typedef MacosClashLatencyProbe = Future<ConnectionLatencyResult> Function(
  String nodeTag,
  String testUrl,
  int timeoutMs,
);
typedef MacosLatencyCancellationCheck = bool Function();
typedef MacosLatencyRetryDelay = Future<void> Function(Duration duration);
typedef MacosLatencyFallbackLogger = void Function(
  String nodeTag,
  ConnectionLatencyResult result,
  int attempt,
);

class MacosProductionClashLatencyProbe {
  const MacosProductionClashLatencyProbe(this.dio);

  final Dio dio;

  Future<ConnectionLatencyResult> call(
    String nodeTag,
    String testUrl,
    int timeoutMs,
  ) async {
    final stopwatch = Stopwatch()..start();
    try {
      final encodedNode = Uri.encodeComponent(nodeTag);
      final response = await dio.get<dynamic>(
        '/proxies/$encodedNode/delay',
        queryParameters: {
          'url': testUrl,
          'timeout': timeoutMs,
        },
        options: Options(
          receiveTimeout: Duration(milliseconds: timeoutMs),
          sendTimeout: Duration(milliseconds: timeoutMs),
          validateStatus: (statusCode) => statusCode != null,
        ),
      );
      stopwatch.stop();
      final statusCode = response.statusCode;
      final rawData = response.data;
      final data = rawData is String ? jsonDecode(rawData) : rawData;
      final delay = data is Map ? data['delay'] : null;
      if (statusCode == HttpStatus.ok && delay is int && delay > 0) {
        return ConnectionLatencyResult(
          latencyMs: delay,
          elapsedMs: stopwatch.elapsedMilliseconds,
          source: ConnectionLatencySource.clashFallback,
          httpStatusCodes: const [HttpStatus.ok],
        );
      }
      return ConnectionLatencyResult(
        latencyMs: -1,
        elapsedMs: stopwatch.elapsedMilliseconds,
        failureKind: statusCode == HttpStatus.ok
            ? ConnectionLatencyFailureKind.serviceError
            : ConnectionLatencyFailureKind.httpError,
        source: ConnectionLatencySource.clashFallback,
        httpStatusCodes: statusCode == null ? const [] : [statusCode],
      );
    } on DioException catch (error) {
      stopwatch.stop();
      final statusCode = error.response?.statusCode;
      final failureKind = switch (error.type) {
        DioExceptionType.connectionTimeout ||
        DioExceptionType.sendTimeout ||
        DioExceptionType.receiveTimeout =>
          ConnectionLatencyFailureKind.timeout,
        DioExceptionType.cancel => ConnectionLatencyFailureKind.cancelled,
        _ when statusCode != null => ConnectionLatencyFailureKind.httpError,
        _ => ConnectionLatencyFailureKind.transportError,
      };
      return ConnectionLatencyResult(
        latencyMs: -1,
        elapsedMs: stopwatch.elapsedMilliseconds,
        failureKind: failureKind,
        source: ConnectionLatencySource.clashFallback,
        httpStatusCodes: statusCode == null ? const [] : [statusCode],
      );
    } catch (_) {
      stopwatch.stop();
      return ConnectionLatencyResult(
        latencyMs: -1,
        elapsedMs: stopwatch.elapsedMilliseconds,
        failureKind: ConnectionLatencyFailureKind.transportError,
        source: ConnectionLatencySource.clashFallback,
      );
    }
  }
}

class MacosLatencyFallbackRunner {
  MacosLatencyFallbackRunner({
    required MacosClashLatencyProbe probe,
    MacosLatencyRetryDelay? retryDelay,
    MacosLatencyFallbackLogger? logger,
    this.concurrency = 4,
  })  : _probe = probe,
        _retryDelay = retryDelay ?? Future<void>.delayed,
        _logger = logger {
    if (concurrency <= 0) {
      throw ArgumentError.value(concurrency, 'concurrency', 'must be positive');
    }
  }

  final MacosClashLatencyProbe _probe;
  final MacosLatencyRetryDelay _retryDelay;
  final MacosLatencyFallbackLogger? _logger;
  final int concurrency;

  Future<Map<String, ConnectionLatencyResult>> resolve({
    required List<String> nodeTags,
    required Map<String, ConnectionLatencyResult> primaryResults,
    required String testUrl,
    required int timeoutMs,
    required MacosLatencyCancellationCheck isCancelled,
    ConnectionLatencyResultCallback? onResult,
  }) async {
    final results = <String, ConnectionLatencyResult>{...primaryResults};
    final failedTags = nodeTags
        .where((tag) => !(primaryResults[tag]?.isSuccess ?? false))
        .toList(growable: false);
    var nextIndex = 0;

    Future<void> worker() async {
      while (!isCancelled()) {
        final index = nextIndex;
        if (index >= failedTags.length) return;
        nextIndex++;
        final nodeTag = failedTags[index];
        var result = await _probe(nodeTag, testUrl, timeoutMs);
        _logger?.call(nodeTag, result, 1);
        if (isCancelled()) return;

        if (_isRetryable(result, timeoutMs)) {
          await _retryDelay(const Duration(milliseconds: 200));
          if (isCancelled()) return;
          result = await _probe(nodeTag, testUrl, timeoutMs);
          _logger?.call(nodeTag, result, 2);
          if (isCancelled()) return;
        }

        results[nodeTag] = result;
        onResult?.call(nodeTag, result);
      }
    }

    final workerCount = min(concurrency, failedTags.length);
    if (workerCount > 0) {
      await Future.wait(List.generate(workerCount, (_) => worker()));
    }

    return Map<String, ConnectionLatencyResult>.unmodifiable(results);
  }

  bool _isRetryable(ConnectionLatencyResult result, int timeoutMs) {
    if (result.isSuccess) return false;
    if (result.failureKind == ConnectionLatencyFailureKind.transportError) {
      return result.elapsedMs < timeoutMs;
    }
    if (result.failureKind != ConnectionLatencyFailureKind.httpError) {
      return false;
    }
    return result.httpStatusCodes.any(
      (statusCode) =>
          statusCode == 502 || statusCode == 503 || statusCode == 504,
    );
  }
}
