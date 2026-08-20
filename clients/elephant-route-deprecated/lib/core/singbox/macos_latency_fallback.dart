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
    MacosLatencyFallbackLogger? logger,
    this.concurrency = 4,
  })  : _probe = probe,
        _logger = logger {
    if (concurrency <= 0) {
      throw ArgumentError.value(concurrency, 'concurrency', 'must be positive');
    }
  }

  final MacosClashLatencyProbe _probe;
  final MacosLatencyFallbackLogger? _logger;
  final int concurrency;

  Future<Map<String, ConnectionLatencyResult>> resolve({
    required List<String> nodeTags,
    required Map<String, ConnectionLatencyResult> primaryResults,
    required List<String> testUrls,
    required int timeoutMs,
    required MacosLatencyCancellationCheck isCancelled,
    ConnectionLatencyResultCallback? onResult,
  }) async {
    if (testUrls.isEmpty) {
      throw ArgumentError.value(testUrls, 'testUrls', 'must not be empty');
    }
    final orderedTestUrls = testUrls.toSet().toList(growable: false);
    final perTargetTimeoutMs = max(1, timeoutMs ~/ orderedTestUrls.length);
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
        ConnectionLatencyResult? result;
        var elapsedMs = 0;
        final statusCodes = <int>[];
        for (var targetIndex = 0;
            targetIndex < orderedTestUrls.length;
            targetIndex++) {
          final attempt = await _probe(
            nodeTag,
            orderedTestUrls[targetIndex],
            perTargetTimeoutMs,
          );
          elapsedMs += attempt.elapsedMs;
          statusCodes.addAll(attempt.httpStatusCodes);
          _logger?.call(nodeTag, attempt, targetIndex + 1);
          if (isCancelled()) return;
          result = attempt;
          if (attempt.isSuccess) break;
        }

        if (result == null) return;
        if (!result.isSuccess && orderedTestUrls.length > 1) {
          result = ConnectionLatencyResult(
            latencyMs: -1,
            elapsedMs: elapsedMs,
            failureKind: result.failureKind,
            source: result.source,
            httpStatusCodes: List<int>.unmodifiable(statusCodes),
            processExitCode: result.processExitCode,
          );
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
}
