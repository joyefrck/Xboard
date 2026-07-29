import 'dart:convert';

import 'connection_latency_manager.dart';
import 'vpn_state.dart';

enum WindowsLatencyJobStatus {
  running,
  completed,
  cancelled,
  error,
}

final class WindowsLatencySnapshot {
  const WindowsLatencySnapshot({
    required this.runId,
    required this.status,
    required this.completed,
    required this.total,
    required this.results,
    this.errorCode,
    this.errorMessage,
  });

  final String runId;
  final WindowsLatencyJobStatus status;
  final int completed;
  final int total;
  final Map<String, ConnectionLatencyResult> results;
  final String? errorCode;
  final String? errorMessage;

  bool get isTerminal => status != WindowsLatencyJobStatus.running;
}

final class WindowsServiceProtocol {
  WindowsServiceProtocol._();

  static const methodChannel = 'com.elephant.network/windows_service';
  static const eventChannel = 'com.elephant.network/windows_service/events';
  static const protocolVersion = 1;
  static const maxConfigBytes = 4 * 1024 * 1024;

  static const supportedMethods = <String>{
    'getStatus',
    'getNetworkProfile',
    'start',
    'stop',
    'urlTest',
    'selectOutbound',
    'prepareSpeedTest',
    'stopSpeedTest',
    'startLatencyTest',
    'getLatencyTest',
    'cancelLatencyTest',
    'protectSecret',
    'unprotectSecret',
    'deleteSecret',
    'deleteAllSecrets',
  };

  static VpnState parseState(Object? value) {
    if (value is! Map) {
      return const VpnState(
        status: VpnStatus.error,
        failureReason: VpnFailureReason.unknown,
        connectionMode: VpnConnectionMode.tun,
        errorMessage: 'Windows 后台服务返回了无效状态',
      );
    }

    final map = Map<String, dynamic>.from(value);
    final errorCode = map['error_code']?.toString();
    final latencyMap = <String, int>{};
    final rawLatency = map['latency_map'];
    if (rawLatency is Map) {
      for (final entry in rawLatency.entries) {
        final latency = _toInt(entry.value);
        if (latency != null) latencyMap[entry.key.toString()] = latency;
      }
    }

    return VpnState(
      status: _status(map['status']?.toString()),
      errorMessage: _optionalString(map['error_message']),
      failureReason: _failureReason(errorCode),
      connectionMode: VpnConnectionMode.tun,
      upSpeed: _toInt(map['up_speed']) ?? 0,
      downSpeed: _toInt(map['down_speed']) ?? 0,
      totalUp: _toInt(map['total_up']) ?? 0,
      totalDown: _toInt(map['total_down']) ?? 0,
      latencyMap: latencyMap.isEmpty ? null : latencyMap,
      runtimeDetails: map,
    );
  }

  static void validateConfig(String config) {
    if (config.trim().isEmpty) {
      throw const FormatException('Windows TUN 配置不能为空');
    }
    if (utf8.encode(config).length > maxConfigBytes) {
      throw const FormatException('Windows TUN 配置超过 4 MiB 限制');
    }
  }

  static WindowsLatencySnapshot parseLatencySnapshot(Object? value) {
    if (value is! Map) {
      throw const FormatException('Windows 测速服务返回了无效状态');
    }
    final map = Map<String, dynamic>.from(value);
    final status = switch (map['latency_test_status']?.toString()) {
      'running' => WindowsLatencyJobStatus.running,
      'completed' => WindowsLatencyJobStatus.completed,
      'cancelled' => WindowsLatencyJobStatus.cancelled,
      'error' => WindowsLatencyJobStatus.error,
      _ => throw const FormatException('Windows 测速任务状态无效'),
    };
    final rawResults = map['latency_results_json'];
    if (rawResults is! String) {
      throw const FormatException('Windows 测速结果缺失');
    }
    final decoded = jsonDecode(rawResults);
    if (decoded is! Map) {
      throw const FormatException('Windows 测速结果格式无效');
    }
    final results = <String, ConnectionLatencyResult>{};
    for (final entry in decoded.entries) {
      final rawResult = entry.value;
      if (rawResult is! Map) {
        throw const FormatException('Windows 节点测速结果格式无效');
      }
      final result = Map<String, dynamic>.from(rawResult);
      final latencyMs = _toInt(result['latency_ms']);
      final elapsedMs = _toInt(result['elapsed_ms']);
      if (latencyMs == null || elapsedMs == null) {
        throw const FormatException('Windows 节点测速结果字段无效');
      }
      results[entry.key.toString()] = ConnectionLatencyResult(
        latencyMs: latencyMs,
        elapsedMs: elapsedMs,
        attempts: _toIntList(result['attempts']),
        failureKind: _latencyFailure(result['failure_kind']?.toString()),
        source: ConnectionLatencySource.connectionProbe,
        httpStatusCodes: _toIntList(result['http_status_codes']),
      );
    }
    return WindowsLatencySnapshot(
      runId: map['run_id']?.toString() ?? '',
      status: status,
      completed: _toInt(map['latency_completed']) ?? results.length,
      total: _toInt(map['latency_total']) ?? results.length,
      results: Map<String, ConnectionLatencyResult>.unmodifiable(results),
      errorCode: _optionalString(map['error_code']),
      errorMessage: _optionalString(map['error_message']),
    );
  }

  static VpnStatus _status(String? value) {
    switch (value) {
      case 'connecting':
        return VpnStatus.connecting;
      case 'core_starting':
        return VpnStatus.coreStarting;
      case 'connected':
        return VpnStatus.connected;
      case 'disconnecting':
        return VpnStatus.disconnecting;
      case 'restore_failed':
        return VpnStatus.restoreFailed;
      case 'error':
        return VpnStatus.error;
      case 'idle':
      case 'disconnected':
      default:
        return VpnStatus.disconnected;
    }
  }

  static VpnFailureReason? _failureReason(String? code) {
    switch (code) {
      case null:
      case '':
        return null;
      case 'permission_denied':
        return VpnFailureReason.permissionDenied;
      case 'config_invalid':
      case 'core_config_invalid':
        return VpnFailureReason.invalidConfig;
      case 'binary_missing':
        return VpnFailureReason.missingBinary;
      case 'tun_conflict':
      case 'tun_address_unavailable':
      case 'tun_start_failed':
        return VpnFailureReason.routeConflict;
      case 'default_interface_missing':
      case 'control_port_in_use':
      case 'core_blocked_or_crashed':
      case 'core_exited':
      case 'control_api_timeout':
      case 'core_start_timeout':
      case 'core_start_failed':
      case 'service_unavailable':
        return VpnFailureReason.coreStartFailed;
      case 'restore_failed':
        return VpnFailureReason.restoreFailed;
      default:
        return VpnFailureReason.unknown;
    }
  }

  static int? _toInt(Object? value) {
    if (value is int) return value;
    if (value is num) return value.toInt();
    return int.tryParse(value?.toString() ?? '');
  }

  static List<int> _toIntList(Object? value) {
    if (value == null) return const <int>[];
    if (value is! List) {
      throw const FormatException('Windows 测速数组字段无效');
    }
    return List<int>.unmodifiable(
      value.map((item) {
        final parsed = _toInt(item);
        if (parsed == null) {
          throw const FormatException('Windows 测速数组值无效');
        }
        return parsed;
      }),
    );
  }

  static ConnectionLatencyFailureKind? _latencyFailure(String? value) {
    return switch (value) {
      null || '' => null,
      'timeout' => ConnectionLatencyFailureKind.timeout,
      'httpError' => ConnectionLatencyFailureKind.httpError,
      'transportError' => ConnectionLatencyFailureKind.transportError,
      'serviceError' => ConnectionLatencyFailureKind.serviceError,
      'cancelled' => ConnectionLatencyFailureKind.cancelled,
      _ => throw const FormatException('Windows 测速失败类型无效'),
    };
  }

  static String? _optionalString(Object? value) {
    final text = value?.toString().trim() ?? '';
    return text.isEmpty ? null : text;
  }
}
