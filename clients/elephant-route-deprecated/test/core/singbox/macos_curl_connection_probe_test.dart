import 'package:elephant_network/core/singbox/connection_latency_manager.dart';
import 'package:elephant_network/core/singbox/macos_curl_connection_probe.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('parses two reused-connection timings and selects the lower value', () {
    final result = MacosCurlConnectionProbe.parseOutput(
      '204 1.141234\n204 0.261432\n',
      elapsedMs: 1427,
    );

    expect(result.attempts, [1141, 261]);
    expect(result.latencyMs, 261);
    expect(result.elapsedMs, 1427);
    expect(result.isSuccess, isTrue);
    expect(result.httpStatusCodes, [204, 204]);
  });

  test('ignores a failed response when the other request is valid', () {
    final result = MacosCurlConnectionProbe.parseOutput(
      '503 0.800000\n204 0.250000\n',
      elapsedMs: 1100,
    );

    expect(result.attempts, [-1, 250]);
    expect(result.latencyMs, 250);
    expect(result.failureKind, isNull);
    expect(result.httpStatusCodes, [503, 204]);
  });

  test('keeps a completed first response when the second request times out',
      () {
    final result = MacosCurlConnectionProbe.parseOutput(
      '204 1.100000\n',
      elapsedMs: 5000,
    );

    expect(result.attempts, [1100]);
    expect(result.latencyMs, 1100);
  });

  test('classifies fast HTTP failures without calling them timeouts', () {
    final result = MacosCurlConnectionProbe.parseOutput(
      '503 0.395000\n503 0.410000\n',
      elapsedMs: 820,
      processExitCode: 0,
    );

    expect(result.latencyMs, -1);
    expect(result.failureKind, ConnectionLatencyFailureKind.httpError);
    expect(result.httpStatusCodes, [503, 503]);
  });

  test('classifies HTTP 000 and non-zero curl exit as transport errors', () {
    final result = MacosCurlConnectionProbe.parseOutput(
      '000 0.395000\n',
      elapsedMs: 395,
      processExitCode: 7,
    );

    expect(result.latencyMs, -1);
    expect(result.failureKind, ConnectionLatencyFailureKind.transportError);
    expect(result.httpStatusCodes, [0]);
    expect(result.processExitCode, 7);
  });

  test('classifies the Dart deadline as a real timeout', () {
    final result = MacosCurlConnectionProbe.parseOutput(
      '',
      elapsedMs: 5000,
      timedOut: true,
      processExitCode: 15,
    );

    expect(result.latencyMs, -1);
    expect(result.failureKind, ConnectionLatencyFailureKind.timeout);
  });

  test('returns transport error for empty or malformed fast output', () {
    final result = MacosCurlConnectionProbe.parseOutput(
      'curl failed',
      elapsedMs: 500,
      processExitCode: 7,
    );

    expect(result.attempts, isEmpty);
    expect(result.latencyMs, -1);
    expect(result.failureKind, ConnectionLatencyFailureKind.transportError);
  });
}
