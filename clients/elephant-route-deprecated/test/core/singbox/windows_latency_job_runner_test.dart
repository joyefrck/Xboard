import 'dart:async';
import 'dart:convert';

import 'package:elephant_network/core/singbox/connection_latency_manager.dart';
import 'package:elephant_network/core/singbox/windows_latency_job_runner.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('publishes each completed service result once', () async {
    final calls = <String>[];
    var polls = 0;
    final runner = WindowsLatencyJobRunner(
      invoke: (method, arguments) async {
        calls.add(method);
        if (method == 'startLatencyTest') {
          return latencySnapshot(
            status: 'running',
            completed: 0,
            total: 2,
          );
        }
        polls++;
        return polls == 1
            ? latencySnapshot(
                status: 'running',
                completed: 1,
                total: 2,
                results: {
                  'Tokyo': latencyResultJson(80, attempts: const [150, 80]),
                },
              )
            : latencySnapshot(
                status: 'completed',
                completed: 2,
                total: 2,
                results: {
                  'Tokyo': latencyResultJson(80, attempts: const [150, 80]),
                  'Osaka': latencyResultJson(95, attempts: const [180, 95]),
                },
              );
      },
      delay: (_) async {},
    );
    final callbacks = <String>[];

    final results = await runner.run(
      nodeTags: const ['Tokyo', 'Osaka'],
      testUrl: 'https://www.gstatic.com/generate_204',
      timeoutMs: 5000,
      concurrency: 4,
      isCancelled: () => false,
      onResult: (tag, _) => callbacks.add(tag),
    );

    expect(results.keys.toSet(), {'Tokyo', 'Osaka'});
    expect(callbacks, ['Tokyo', 'Osaka']);
    expect(calls.first, 'startLatencyTest');
    expect(calls.where((method) => method == 'getLatencyTest'), hasLength(2));
  });

  test('passes the configured probe contract unchanged', () async {
    Map<String, dynamic>? startArguments;
    final runner = WindowsLatencyJobRunner(
      invoke: (method, arguments) async {
        if (method == 'startLatencyTest') {
          startArguments = arguments;
          return latencySnapshot(
            status: 'completed',
            completed: 1,
            total: 1,
            results: {'Tokyo': latencyResultJson(42)},
          );
        }
        throw StateError('unexpected method $method');
      },
      delay: (_) async {},
    );

    await runner.run(
      nodeTags: const ['Tokyo'],
      testUrl: 'https://probe.example/generate_204',
      timeoutMs: 5000,
      concurrency: 3,
      isCancelled: () => false,
    );

    expect(
      jsonDecode(startArguments!['node_tags_json'] as String),
      ['Tokyo'],
    );
    expect(
      startArguments!['test_url'],
      'https://probe.example/generate_204',
    );
    expect(startArguments!['timeout_ms'], 5000);
    expect(startArguments!['concurrency'], 3);
  });

  test('cancels the matching service run without stale callbacks', () async {
    var cancelled = false;
    final methods = <String>[];
    final runner = WindowsLatencyJobRunner(
      invoke: (method, arguments) async {
        methods.add(method);
        if (method == 'startLatencyTest') {
          cancelled = true;
          return latencySnapshot(
            status: 'running',
            completed: 0,
            total: 1,
          );
        }
        if (method == 'cancelLatencyTest') {
          expect(arguments['run_id'], 'run-1');
          return latencySnapshot(
            status: 'cancelled',
            completed: 1,
            total: 1,
            results: {
              'Tokyo': latencyResultJson(
                -1,
                attempts: const [-1],
                failureKind: 'cancelled',
              ),
            },
          );
        }
        throw StateError('unexpected method $method');
      },
      delay: (_) async {},
    );
    final callbacks = <String>[];

    final results = await runner.run(
      nodeTags: const ['Tokyo'],
      testUrl: 'https://www.gstatic.com/generate_204',
      timeoutMs: 5000,
      concurrency: 1,
      isCancelled: () => cancelled,
      onResult: (tag, _) => callbacks.add(tag),
    );

    expect(methods, contains('cancelLatencyTest'));
    expect(callbacks, isEmpty);
    expect(
      results['Tokyo']?.failureKind,
      ConnectionLatencyFailureKind.cancelled,
    );
  });

  test('external cancel targets the active run', () async {
    final pollStarted = Completer<void>();
    final releasePoll = Completer<void>();
    final methods = <String>[];
    final runner = WindowsLatencyJobRunner(
      invoke: (method, arguments) async {
        methods.add(method);
        if (method == 'startLatencyTest') {
          return latencySnapshot(
            status: 'running',
            completed: 0,
            total: 1,
          );
        }
        if (method == 'getLatencyTest') {
          pollStarted.complete();
          await releasePoll.future;
          return latencySnapshot(
            status: 'cancelled',
            completed: 1,
            total: 1,
            results: {
              'Tokyo': latencyResultJson(
                -1,
                attempts: const [-1],
                failureKind: 'cancelled',
              ),
            },
          );
        }
        if (method == 'cancelLatencyTest') {
          expect(arguments['run_id'], 'run-1');
          return latencySnapshot(
            status: 'cancelled',
            completed: 1,
            total: 1,
          );
        }
        throw StateError('unexpected method $method');
      },
      delay: (_) async {},
    );

    final run = runner.run(
      nodeTags: const ['Tokyo'],
      testUrl: 'https://www.gstatic.com/generate_204',
      timeoutMs: 5000,
      concurrency: 1,
      isCancelled: () => false,
    );
    await pollStarted.future;
    await runner.cancel();
    releasePoll.complete();
    await run;

    expect(methods, contains('cancelLatencyTest'));
  });

  test('throws a stable unavailable error for service failure', () async {
    final runner = WindowsLatencyJobRunner(
      invoke: (method, arguments) async => latencySnapshot(
        status: 'error',
        completed: 0,
        total: 1,
        errorCode: 'latency_unavailable',
        errorMessage: 'Windows latency service is unavailable.',
      ),
      delay: (_) async {},
    );

    await expectLater(
      runner.run(
        nodeTags: const ['Tokyo'],
        testUrl: 'https://www.gstatic.com/generate_204',
        timeoutMs: 5000,
        concurrency: 1,
        isCancelled: () => false,
      ),
      throwsA(isA<ConnectionLatencyUnavailableException>()),
    );
  });

  test('cancels a hung service poll and preserves completed results', () async {
    final hungPoll = Completer<Map<String, dynamic>>();
    final methods = <String>[];
    var polls = 0;
    final runner = WindowsLatencyJobRunner(
      invoke: (method, arguments) async {
        methods.add(method);
        if (method == 'startLatencyTest') {
          return latencySnapshot(
            status: 'running',
            completed: 1,
            total: 2,
            results: {'Tokyo': latencyResultJson(42)},
          );
        }
        if (method == 'getLatencyTest') {
          polls++;
          return hungPoll.future;
        }
        if (method == 'cancelLatencyTest') {
          expect(arguments['run_id'], 'run-1');
          return latencySnapshot(
            status: 'cancelled',
            completed: 1,
            total: 2,
            results: {'Tokyo': latencyResultJson(42)},
          );
        }
        throw StateError('unexpected method $method');
      },
      delay: (_) async {},
      serviceCallTimeout: const Duration(milliseconds: 5),
      jobTimeout: const Duration(seconds: 1),
    );
    final callbacks = <String>[];

    final results = await runner.run(
      nodeTags: const ['Tokyo', 'Osaka'],
      testUrl: 'https://cp.cloudflare.com/generate_204',
      timeoutMs: 5000,
      concurrency: 4,
      isCancelled: () => false,
      onResult: (tag, _) => callbacks.add(tag),
    );

    expect(polls, 1);
    expect(methods, contains('cancelLatencyTest'));
    expect(callbacks, ['Tokyo', 'Osaka']);
    expect(results['Tokyo']?.latencyMs, 42);
    expect(
      results['Osaka']?.failureKind,
      ConnectionLatencyFailureKind.timeout,
    );
  });

  test('bounds a hung start call and cancels the active service job', () async {
    final hungStart = Completer<Map<String, dynamic>>();
    final cancelRunIds = <Object?>[];
    final runner = WindowsLatencyJobRunner(
      invoke: (method, arguments) async {
        if (method == 'startLatencyTest') return hungStart.future;
        if (method == 'cancelLatencyTest') {
          cancelRunIds.add(arguments['run_id']);
          return latencySnapshot(
            status: 'cancelled',
            completed: 0,
            total: 1,
          );
        }
        throw StateError('unexpected method $method');
      },
      serviceCallTimeout: const Duration(milliseconds: 5),
      jobTimeout: const Duration(seconds: 1),
    );
    final callbacks = <String>[];

    final results = await runner.run(
      nodeTags: const ['Tokyo'],
      testUrl: 'https://cp.cloudflare.com/generate_204',
      timeoutMs: 5000,
      concurrency: 1,
      isCancelled: () => false,
      onResult: (tag, _) => callbacks.add(tag),
    );

    expect(cancelRunIds, ['']);
    expect(callbacks, ['Tokyo']);
    expect(
      results['Tokyo']?.failureKind,
      ConnectionLatencyFailureKind.timeout,
    );
  });

  test('whole job deadline cancels a service that stays running', () async {
    final methods = <String>[];
    final runner = WindowsLatencyJobRunner(
      invoke: (method, arguments) async {
        methods.add(method);
        if (method == 'startLatencyTest' || method == 'getLatencyTest') {
          return latencySnapshot(
            status: 'running',
            completed: 0,
            total: 1,
          );
        }
        if (method == 'cancelLatencyTest') {
          return latencySnapshot(
            status: 'cancelled',
            completed: 0,
            total: 1,
          );
        }
        throw StateError('unexpected method $method');
      },
      delay: (_) => Future<void>.delayed(const Duration(milliseconds: 20)),
      serviceCallTimeout: const Duration(seconds: 1),
      jobTimeout: const Duration(milliseconds: 5),
    );

    final results = await runner.run(
      nodeTags: const ['Tokyo'],
      testUrl: 'https://cp.cloudflare.com/generate_204',
      timeoutMs: 5000,
      concurrency: 1,
      isCancelled: () => false,
    );

    expect(methods, contains('cancelLatencyTest'));
    expect(
      results['Tokyo']?.failureKind,
      ConnectionLatencyFailureKind.timeout,
    );
  });
}

Map<String, dynamic> latencySnapshot({
  required String status,
  required int completed,
  required int total,
  Map<String, dynamic> results = const {},
  String? errorCode,
  String? errorMessage,
}) {
  return {
    'run_id': 'run-1',
    'latency_test_status': status,
    'latency_completed': completed,
    'latency_total': total,
    'latency_results_json': jsonEncode(results),
    if (errorCode != null) 'error_code': errorCode,
    if (errorMessage != null) 'error_message': errorMessage,
  };
}

Map<String, dynamic> latencyResultJson(
  int latency, {
  List<int>? attempts,
  String? failureKind,
}) {
  return {
    'latency_ms': latency,
    'elapsed_ms': latency > 0 ? latency + 20 : 0,
    'attempts': attempts ?? [latency],
    if (failureKind != null) 'failure_kind': failureKind,
    'http_status_codes': latency > 0 ? [204, 204] : <int>[],
  };
}
