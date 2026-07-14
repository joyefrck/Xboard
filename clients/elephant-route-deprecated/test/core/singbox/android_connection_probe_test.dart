import 'package:elephant_network/core/singbox/android_connection_probe.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('uses the native persistent-connection probe and selects its result',
      () async {
    final calls = <Map<String, Object?>>[];
    final probe = AndroidConnectionProbe(
      methodInvoker: (method, arguments) async {
        calls.add(<String, Object?>{
          'method': method,
          ...?arguments,
        });
        return <String, Object?>{
          'latencyMs': 92,
          'elapsedMs': 335,
          'attempts': <int>[240, 92],
          'connectionCount': 1,
        };
      },
    );

    final result = await probe.run(
      proxyPort: 31001,
      testUrl: 'https://www.gstatic.com/generate_204',
      timeout: const Duration(seconds: 5),
    );

    expect(calls, hasLength(1));
    expect(calls.single['method'], 'probeConnectionLatency');
    expect(calls.single['proxyPort'], 31001);
    expect(calls.single['timeoutMs'], 5000);
    expect(result.attempts, [240, 92]);
    expect(result.latencyMs, 92);
  });

  test('stop cancels only probes owned by this session', () async {
    final calls = <Map<String, Object?>>[];
    final probe = AndroidConnectionProbe(
      methodInvoker: (method, arguments) async {
        calls.add(<String, Object?>{
          'method': method,
          ...?arguments,
        });
        if (method == 'probeConnectionLatency') {
          return <String, Object?>{
            'latencyMs': 125,
            'elapsedMs': 130,
            'attempts': <int>[-1, 125],
            'connectionCount': 1,
          };
        }
        return null;
      },
    );

    await probe.run(
      proxyPort: 31001,
      testUrl: 'https://www.gstatic.com/generate_204',
      timeout: const Duration(seconds: 5),
    );
    await probe.stop();
    await probe.stop();

    expect(calls, hasLength(2));
    expect(calls.last['method'], 'cancelConnectionLatencyProbes');
    expect(calls.last['sessionId'], calls.first['sessionId']);
  });

  test('rejects malformed test URLs before invoking Android', () async {
    var invoked = false;
    final probe = AndroidConnectionProbe(
      methodInvoker: (method, arguments) async {
        invoked = true;
        return null;
      },
    );

    await expectLater(
      probe.run(
        proxyPort: 31001,
        testUrl: 'not-a-url',
        timeout: const Duration(seconds: 5),
      ),
      throwsA(isA<FormatException>()),
    );
    expect(invoked, isFalse);
  });

  test('rejects malformed native probe responses', () async {
    final probe = AndroidConnectionProbe(
      methodInvoker: (method, arguments) async => <String, Object?>{
        'attempts': <int>[100],
      },
    );

    await expectLater(
      probe.run(
        proxyPort: 31001,
        testUrl: 'https://www.gstatic.com/generate_204',
        timeout: const Duration(seconds: 5),
      ),
      throwsA(isA<StateError>()),
    );
  });
}
