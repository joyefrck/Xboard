import '../core/singbox/connection_latency_manager.dart';

String? nodeLatencyLabel({
  required int? latency,
  ConnectionLatencyResult? result,
}) {
  if (latency == null) return null;
  if (latency > 0) return '${latency}ms';
  final failureKind = result?.failureKind;
  if (failureKind == null ||
      failureKind == ConnectionLatencyFailureKind.timeout) {
    return '超时';
  }
  return '失败';
}
