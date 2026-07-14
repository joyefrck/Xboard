import 'package:flutter/services.dart';

import 'connection_latency_manager.dart';

typedef AndroidProbeMethodInvoker = Future<Object?> Function(
  String method,
  Map<String, Object?>? arguments,
);

abstract interface class AndroidNodeProbe {
  Future<ConnectionLatencyResult> run({
    required int proxyPort,
    required String testUrl,
    required Duration timeout,
  });

  Future<void> stop();
}

class AndroidConnectionProbe implements AndroidNodeProbe {
  AndroidConnectionProbe({AndroidProbeMethodInvoker? methodInvoker})
      : _methodInvoker = methodInvoker ?? _invokePlatformMethod,
        _sessionId = 'android-latency-${_nextSessionId++}';

  static const MethodChannel _channel =
      MethodChannel('com.elephant.network/vpn');
  static int _nextSessionId = 1;

  final AndroidProbeMethodInvoker _methodInvoker;
  final String _sessionId;
  bool _stopped = false;

  @override
  Future<ConnectionLatencyResult> run({
    required int proxyPort,
    required String testUrl,
    required Duration timeout,
  }) async {
    final uri = Uri.tryParse(testUrl);
    if (uri == null ||
        !uri.hasScheme ||
        !uri.hasAuthority ||
        (uri.scheme != 'http' && uri.scheme != 'https')) {
      throw const FormatException('Latency URL must be HTTP or HTTPS');
    }
    if (_stopped) {
      return const ConnectionLatencyResult(
        latencyMs: -1,
        elapsedMs: 0,
        attempts: <int>[-1, -1],
      );
    }

    final raw = await _methodInvoker(
      'probeConnectionLatency',
      <String, Object?>{
        'sessionId': _sessionId,
        'proxyPort': proxyPort,
        'testUrl': testUrl,
        'timeoutMs': timeout.inMilliseconds,
      },
    );
    if (raw is! Map) {
      throw StateError('Android latency probe returned an invalid response');
    }
    final latencyMs = raw['latencyMs'];
    final elapsedMs = raw['elapsedMs'];
    final attempts = raw['attempts'];
    if (latencyMs is! int || elapsedMs is! int || attempts is! List) {
      throw StateError('Android latency probe returned incomplete data');
    }
    final parsedAttempts = attempts.whereType<int>().toList(growable: false);
    if (parsedAttempts.length != 2) {
      throw StateError('Android latency probe must return two attempts');
    }
    return ConnectionLatencyResult(
      latencyMs: latencyMs,
      elapsedMs: elapsedMs,
      attempts: parsedAttempts,
    );
  }

  @override
  Future<void> stop() async {
    if (_stopped) return;
    _stopped = true;
    await _methodInvoker(
      'cancelConnectionLatencyProbes',
      <String, Object?>{'sessionId': _sessionId},
    );
  }

  static Future<Object?> _invokePlatformMethod(
    String method,
    Map<String, Object?>? arguments,
  ) {
    return _channel.invokeMethod<Object?>(method, arguments);
  }
}
