import 'dart:async';

import 'package:dio/dio.dart';
import 'package:elephant_network/core/singbox/connection_latency_manager.dart';
import 'package:elephant_network/core/singbox/macos_latency_fallback.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  const failed503 = ConnectionLatencyResult(
    latencyMs: -1,
    elapsedMs: 395,
    failureKind: ConnectionLatencyFailureKind.httpError,
    source: ConnectionLatencySource.connectionProbe,
    httpStatusCodes: [503],
  );
  const timeout = ConnectionLatencyResult(
    latencyMs: -1,
    elapsedMs: 5000,
    failureKind: ConnectionLatencyFailureKind.timeout,
    source: ConnectionLatencySource.connectionProbe,
  );
  const success = ConnectionLatencyResult(
    latencyMs: 120,
    elapsedMs: 600,
    source: ConnectionLatencySource.connectionProbe,
  );

  test('production Clash probe returns a typed successful fallback', () async {
    final dio = Dio(BaseOptions(baseUrl: 'http://127.0.0.1:9090'));
    dio.interceptors.add(InterceptorsWrapper(
      onRequest: (options, handler) => handler.resolve(Response<dynamic>(
        requestOptions: options,
        statusCode: 200,
        data: const {'delay': 425},
      )),
    ));
    final probe = MacosProductionClashLatencyProbe(dio);

    final result = await probe.call(
      'node-a',
      'https://www.gstatic.com/generate_204',
      5000,
    );

    expect(result.latencyMs, 425);
    expect(result.source, ConnectionLatencySource.clashFallback);
    expect(result.httpStatusCodes, [200]);
  });

  test('production Clash probe accepts a JSON string response', () async {
    final dio = Dio(BaseOptions(baseUrl: 'http://127.0.0.1:9090'));
    dio.interceptors.add(InterceptorsWrapper(
      onRequest: (options, handler) => handler.resolve(Response<dynamic>(
        requestOptions: options,
        statusCode: 200,
        data: '{"delay":425}',
      )),
    ));
    final probe = MacosProductionClashLatencyProbe(dio);

    final result = await probe.call(
      'node-a',
      'https://www.gstatic.com/generate_204',
      5000,
    );

    expect(result.latencyMs, 425);
    expect(result.source, ConnectionLatencySource.clashFallback);
  });

  test('production Clash probe preserves fast 503 responses', () async {
    final dio = Dio(BaseOptions(baseUrl: 'http://127.0.0.1:9090'));
    dio.interceptors.add(InterceptorsWrapper(
      onRequest: (options, handler) => handler.resolve(Response<dynamic>(
        requestOptions: options,
        statusCode: 503,
        data: const {'message': 'delay test failed'},
      )),
    ));
    final probe = MacosProductionClashLatencyProbe(dio);

    final result = await probe.call(
      'node-a',
      'https://www.gstatic.com/generate_204',
      5000,
    );

    expect(result.failureKind, ConnectionLatencyFailureKind.httpError);
    expect(result.httpStatusCodes, [503]);
  });

  test('production Clash probe classifies Dio deadlines as timeouts', () async {
    final dio = Dio(BaseOptions(baseUrl: 'http://127.0.0.1:9090'));
    dio.interceptors.add(InterceptorsWrapper(
      onRequest: (options, handler) => handler.reject(DioException(
        requestOptions: options,
        type: DioExceptionType.receiveTimeout,
      )),
    ));
    final probe = MacosProductionClashLatencyProbe(dio);

    final result = await probe.call(
      'node-a',
      'https://www.gstatic.com/generate_204',
      5000,
    );

    expect(result.failureKind, ConnectionLatencyFailureKind.timeout);
  });

  test('does not re-probe successful connection results', () async {
    var probes = 0;
    final runner = MacosLatencyFallbackRunner(
      probe: (nodeTag, testUrl, timeoutMs) async {
        probes++;
        return success;
      },
    );

    final results = await runner.resolve(
      nodeTags: const ['node-a'],
      primaryResults: const {'node-a': success},
      testUrl: 'https://www.gstatic.com/generate_204',
      timeoutMs: 5000,
      isCancelled: () => false,
    );

    expect(probes, 0);
    expect(results['node-a'], same(success));
  });

  test('replaces a primary failure with a successful Clash fallback', () async {
    final callbacks = <ConnectionLatencyResult>[];
    final runner = MacosLatencyFallbackRunner(
      probe: (nodeTag, testUrl, timeoutMs) async =>
          const ConnectionLatencyResult(
        latencyMs: 425,
        elapsedMs: 430,
        source: ConnectionLatencySource.clashFallback,
      ),
    );

    final results = await runner.resolve(
      nodeTags: const ['node-a'],
      primaryResults: const {'node-a': failed503},
      testUrl: 'https://www.gstatic.com/generate_204',
      timeoutMs: 5000,
      isCancelled: () => false,
      onResult: (nodeTag, result) => callbacks.add(result),
    );

    expect(results['node-a']?.latencyMs, 425);
    expect(results['node-a']?.source, ConnectionLatencySource.clashFallback);
    expect(callbacks, hasLength(1));
  });

  test('treats a missing primary result as service error and falls back',
      () async {
    final runner = MacosLatencyFallbackRunner(
      probe: (nodeTag, testUrl, timeoutMs) async =>
          const ConnectionLatencyResult(
        latencyMs: 210,
        elapsedMs: 220,
        source: ConnectionLatencySource.clashFallback,
      ),
    );

    final results = await runner.resolve(
      nodeTags: const ['node-a'],
      primaryResults: const {},
      testUrl: 'https://www.gstatic.com/generate_204',
      timeoutMs: 5000,
      isCancelled: () => false,
    );

    expect(results['node-a']?.latencyMs, 210);
  });

  test('retries one transient 503 and keeps the successful retry', () async {
    var probes = 0;
    var delays = 0;
    final runner = MacosLatencyFallbackRunner(
      probe: (nodeTag, testUrl, timeoutMs) async {
        probes++;
        if (probes == 1) {
          return const ConnectionLatencyResult(
            latencyMs: -1,
            elapsedMs: 300,
            failureKind: ConnectionLatencyFailureKind.httpError,
            source: ConnectionLatencySource.clashFallback,
            httpStatusCodes: [503],
          );
        }
        return const ConnectionLatencyResult(
          latencyMs: 180,
          elapsedMs: 200,
          source: ConnectionLatencySource.clashFallback,
        );
      },
      retryDelay: (_) async => delays++,
    );

    final results = await runner.resolve(
      nodeTags: const ['node-a'],
      primaryResults: const {'node-a': failed503},
      testUrl: 'https://www.gstatic.com/generate_204',
      timeoutMs: 5000,
      isCancelled: () => false,
    );

    expect(probes, 2);
    expect(delays, 1);
    expect(results['node-a']?.latencyMs, 180);
  });

  test('does not retry a real fallback timeout', () async {
    var probes = 0;
    final runner = MacosLatencyFallbackRunner(
      probe: (nodeTag, testUrl, timeoutMs) async {
        probes++;
        return const ConnectionLatencyResult(
          latencyMs: -1,
          elapsedMs: 5000,
          failureKind: ConnectionLatencyFailureKind.timeout,
          source: ConnectionLatencySource.clashFallback,
        );
      },
    );

    final results = await runner.resolve(
      nodeTags: const ['node-a'],
      primaryResults: const {'node-a': timeout},
      testUrl: 'https://www.gstatic.com/generate_204',
      timeoutMs: 5000,
      isCancelled: () => false,
    );

    expect(probes, 1);
    expect(
        results['node-a']?.failureKind, ConnectionLatencyFailureKind.timeout);
  });

  test('does not retry a transport failure that exhausted the budget',
      () async {
    var probes = 0;
    final runner = MacosLatencyFallbackRunner(
      probe: (nodeTag, testUrl, timeoutMs) async {
        probes++;
        return const ConnectionLatencyResult(
          latencyMs: -1,
          elapsedMs: 5000,
          failureKind: ConnectionLatencyFailureKind.transportError,
          source: ConnectionLatencySource.clashFallback,
        );
      },
    );

    await runner.resolve(
      nodeTags: const ['node-a'],
      primaryResults: const {'node-a': timeout},
      testUrl: 'https://www.gstatic.com/generate_204',
      timeoutMs: 5000,
      isCancelled: () => false,
    );

    expect(probes, 1);
  });

  test('limits fallback probes to two concurrent nodes', () async {
    var inFlight = 0;
    var maxInFlight = 0;
    final release = Completer<void>();
    final runner = MacosLatencyFallbackRunner(
      probe: (nodeTag, testUrl, timeoutMs) async {
        inFlight++;
        if (inFlight > maxInFlight) maxInFlight = inFlight;
        if (inFlight == 2 && !release.isCompleted) release.complete();
        await release.future;
        inFlight--;
        return const ConnectionLatencyResult(
          latencyMs: 200,
          elapsedMs: 220,
          source: ConnectionLatencySource.clashFallback,
        );
      },
    );

    await runner.resolve(
      nodeTags: const ['a', 'b', 'c', 'd'],
      primaryResults: const {
        'a': failed503,
        'b': failed503,
        'c': failed503,
        'd': failed503,
      },
      testUrl: 'https://www.gstatic.com/generate_204',
      timeoutMs: 5000,
      isCancelled: () => false,
    );

    expect(maxInFlight, 2);
  });

  test('cancellation suppresses stale callbacks', () async {
    var cancelled = false;
    var callbacks = 0;
    final runner = MacosLatencyFallbackRunner(
      probe: (nodeTag, testUrl, timeoutMs) async {
        cancelled = true;
        return const ConnectionLatencyResult(
          latencyMs: 200,
          elapsedMs: 220,
          source: ConnectionLatencySource.clashFallback,
        );
      },
    );

    await runner.resolve(
      nodeTags: const ['node-a'],
      primaryResults: const {'node-a': failed503},
      testUrl: 'https://www.gstatic.com/generate_204',
      timeoutMs: 5000,
      isCancelled: () => cancelled,
      onResult: (nodeTag, result) => callbacks++,
    );

    expect(callbacks, 0);
  });
}
