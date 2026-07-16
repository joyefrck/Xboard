typedef ConnectionLatencyResultCallback = void Function(
  String nodeTag,
  ConnectionLatencyResult result,
);

enum ConnectionLatencyFailureKind {
  timeout,
  httpError,
  transportError,
  serviceError,
  cancelled,
}

enum ConnectionLatencySource {
  connectionProbe,
  clashFallback,
}

abstract interface class ConnectionLatencyManager {
  Future<Map<String, ConnectionLatencyResult>> testConnectionLatencies({
    required List<String> nodeTags,
    required String testUrl,
    required int timeoutMs,
    required int concurrency,
    ConnectionLatencyResultCallback? onResult,
  });

  Future<void> stopConnectionLatencyTest();
}

class ConnectionLatencyUnavailableException implements Exception {
  const ConnectionLatencyUnavailableException(this.message);

  final String message;

  @override
  String toString() => message;
}

class ConnectionLatencyResult {
  const ConnectionLatencyResult({
    required this.latencyMs,
    required this.elapsedMs,
    this.attempts = const [],
    this.failureKind,
    this.source = ConnectionLatencySource.connectionProbe,
    this.httpStatusCodes = const [],
    this.processExitCode,
  });

  final int latencyMs;
  final int elapsedMs;
  final List<int> attempts;
  final ConnectionLatencyFailureKind? failureKind;
  final ConnectionLatencySource source;
  final List<int> httpStatusCodes;
  final int? processExitCode;

  bool get isSuccess => latencyMs > 0 && failureKind == null;
}
