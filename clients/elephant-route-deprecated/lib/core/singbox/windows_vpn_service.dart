import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';

import 'connection_latency_manager.dart';
import 'latency_test_policy.dart';
import 'vpn_manager.dart';
import 'vpn_state.dart';
import 'windows_latency_job_runner.dart';
import 'windows_service_protocol.dart';

typedef WindowsServiceAvailabilityDelay = Future<void> Function(Duration delay);

class WindowsVpnService implements VpnManager, ConnectionLatencyManager {
  WindowsVpnService({
    MethodChannel? methodChannel,
    EventChannel? eventChannel,
    WindowsLatencyJobDelay? latencyPollDelay,
    WindowsServiceAvailabilityDelay? serviceAvailabilityDelay,
    List<Duration>? serviceAvailabilityRetryDelays,
  })  : _methodChannel = methodChannel ??
            const MethodChannel(WindowsServiceProtocol.methodChannel),
        _eventChannel = eventChannel ??
            const EventChannel(WindowsServiceProtocol.eventChannel),
        _latencyPollDelay = latencyPollDelay,
        _serviceAvailabilityDelay =
            serviceAvailabilityDelay ?? _defaultServiceAvailabilityDelay,
        _serviceAvailabilityRetryDelays = serviceAvailabilityRetryDelays ??
            _defaultServiceAvailabilityRetryDelays {
    _eventSubscription = _eventChannel.receiveBroadcastStream().listen(
      _handleState,
      onError: (Object error, StackTrace stackTrace) {
        debugPrint('Windows service event stream failed: $error');
      },
    );
  }

  final MethodChannel _methodChannel;
  final EventChannel _eventChannel;
  final WindowsLatencyJobDelay? _latencyPollDelay;
  final WindowsServiceAvailabilityDelay _serviceAvailabilityDelay;
  final List<Duration> _serviceAvailabilityRetryDelays;
  final StreamController<VpnState> _stateController =
      StreamController<VpnState>.broadcast();

  static const List<Duration> _defaultServiceAvailabilityRetryDelays = [
    Duration(milliseconds: 250),
    Duration(milliseconds: 500),
    Duration(seconds: 1),
    Duration(seconds: 2),
    Duration(seconds: 3),
    Duration(seconds: 3),
  ];

  StreamSubscription<Object?>? _eventSubscription;
  VpnState _state = const VpnState(
    status: VpnStatus.disconnected,
    connectionMode: VpnConnectionMode.tun,
  );
  bool _disposed = false;
  int _latencyRunGeneration = 0;
  WindowsLatencyJobRunner? _latencyJobRunner;

  @override
  Future<bool> requestPermission() async {
    try {
      for (var attempt = 0;; attempt++) {
        final result = await _invokeMap('getStatus');
        final serviceUnavailable =
            result['error_code']?.toString() == 'service_unavailable';
        if (!serviceUnavailable ||
            attempt >= _serviceAvailabilityRetryDelays.length) {
          _handleState(result);
          return !serviceUnavailable;
        }

        await _serviceAvailabilityDelay(
          _serviceAvailabilityRetryDelays[attempt],
        );
      }
    } on PlatformException catch (error) {
      _setUnavailable(error.message);
      return false;
    } on MissingPluginException {
      _setUnavailable('Windows 后台服务桥接未安装');
      return false;
    }
  }

  static Future<void> _defaultServiceAvailabilityDelay(Duration delay) =>
      Future<void>.delayed(delay);

  @override
  Future<void> start(String config) async {
    WindowsServiceProtocol.validateConfig(config);
    _updateState(_state.copyWith(
      status: VpnStatus.connecting,
      connectionMode: VpnConnectionMode.tun,
      resetErrorMessage: true,
      resetFailureReason: true,
    ));

    try {
      final networkProfile = await _networkProfile();
      if (networkProfile == null) return;
      final runtimeDir = _runtimeDirectory();
      final sanitized = _sanitizeConfig(
        config,
        runtimeDir,
        defaultInterface: networkProfile.defaultInterface,
        tunIpv4Address: networkProfile.tunIpv4Address,
        strictRoute: networkProfile.strictRoute,
      );
      final result = await _invokeMap('start', {
        'protocol_version': WindowsServiceProtocol.protocolVersion,
        'config': sanitized,
      });
      _handleState(result);
    } on PlatformException catch (error) {
      _updateState(VpnState(
        status: VpnStatus.error,
        connectionMode: VpnConnectionMode.tun,
        errorMessage: error.message ?? 'Windows TUN 启动失败',
        failureReason: VpnFailureReason.coreStartFailed,
      ));
      rethrow;
    }
  }

  @override
  Future<void> prepareSpeedTest(String config) async {
    WindowsServiceProtocol.validateConfig(config);
    final networkProfile = await _networkProfile();
    if (networkProfile == null) return;
    await _invokeMap('prepareSpeedTest', {
      'protocol_version': WindowsServiceProtocol.protocolVersion,
      'config': _sanitizeConfig(
        config,
        _runtimeDirectory(),
        defaultInterface: networkProfile.defaultInterface,
        tunIpv4Address: networkProfile.tunIpv4Address,
        strictRoute: networkProfile.strictRoute,
      ),
    });
  }

  @override
  Future<void> stopSpeedTest() async {
    await _invokeMap('stopSpeedTest');
  }

  @override
  Future<void> stop({
    VpnStopReason reason = VpnStopReason.unspecified,
  }) async {
    if (_disposed) return;
    await stopConnectionLatencyTest();
    _updateState(_state.copyWith(status: VpnStatus.disconnecting));
    try {
      final result = await _invokeMap('stop');
      _handleState(result);
    } on PlatformException catch (error) {
      _updateState(VpnState(
        status: VpnStatus.restoreFailed,
        connectionMode: VpnConnectionMode.tun,
        errorMessage: error.message ?? 'Windows TUN 清理失败',
        failureReason: VpnFailureReason.restoreFailed,
      ));
      rethrow;
    }
  }

  @override
  Future<int> urlTest(String groupTag) async {
    final result = await _invokeMap('urlTest', {'group_tag': groupTag});
    final delay = result['delay'];
    if (delay is int) return delay;
    return int.tryParse(delay?.toString() ?? '') ?? -1;
  }

  @override
  Future<void> selectOutbound(String groupTag, String outboundTag) async {
    final result = await _invokeMap('selectOutbound', {
      'group_tag': groupTag,
      'outbound_tag': outboundTag,
    });
    _handleState(result);
  }

  @override
  Future<Map<String, ConnectionLatencyResult>> testConnectionLatencies({
    required List<String> nodeTags,
    required String testUrl,
    required int timeoutMs,
    required int concurrency,
    ConnectionLatencyResultCallback? onResult,
  }) async {
    if (_state.status != VpnStatus.connected) {
      throw const ConnectionLatencyUnavailableException('请先开启加速后再测速');
    }
    await stopConnectionLatencyTest();
    final generation = _latencyRunGeneration;
    final usesDefaultProbe =
        testUrl == LatencyTestPolicy.connectionDefaultProbeUrl;
    final published = <String>{};

    void publish(String nodeTag, ConnectionLatencyResult result) {
      if (!_disposed &&
          generation == _latencyRunGeneration &&
          published.add(nodeTag)) {
        onResult?.call(nodeTag, result);
      }
    }

    final primaryResults = await _runLatencyJob(
      nodeTags: nodeTags,
      testUrl: testUrl,
      timeoutMs: timeoutMs,
      concurrency: concurrency,
      generation: generation,
      onResult: (nodeTag, result) {
        if (!usesDefaultProbe || result.isSuccess) {
          publish(nodeTag, result);
        }
      },
    );
    if (!usesDefaultProbe || _disposed || generation != _latencyRunGeneration) {
      return primaryResults;
    }

    final retryNodeTags = nodeTags.where((nodeTag) {
      final result = primaryResults[nodeTag];
      return result != null && _isRetryableLatencyFailure(result);
    }).toList(growable: false);
    final retryNodeTagSet = retryNodeTags.toSet();
    for (final entry in primaryResults.entries) {
      if (!retryNodeTagSet.contains(entry.key)) {
        publish(entry.key, entry.value);
      }
    }
    if (retryNodeTags.isEmpty) {
      return primaryResults;
    }

    final fallbackResults = await _runLatencyJob(
      nodeTags: retryNodeTags,
      testUrl: LatencyTestPolicy.windowsConnectionFallbackProbeUrl,
      timeoutMs: timeoutMs,
      concurrency: concurrency,
      generation: generation,
      onResult: (nodeTag, result) {
        if (result.isSuccess) {
          publish(nodeTag, result);
        }
      },
    );
    final mergedResults = <String, ConnectionLatencyResult>{
      ...primaryResults,
    };
    for (final nodeTag in retryNodeTags) {
      final fallbackResult = fallbackResults[nodeTag];
      if (fallbackResult != null) {
        mergedResults[nodeTag] = fallbackResult;
      }
      final finalResult = mergedResults[nodeTag];
      if (finalResult != null) {
        publish(nodeTag, finalResult);
      }
    }
    return Map<String, ConnectionLatencyResult>.unmodifiable(mergedResults);
  }

  Future<Map<String, ConnectionLatencyResult>> _runLatencyJob({
    required List<String> nodeTags,
    required String testUrl,
    required int timeoutMs,
    required int concurrency,
    required int generation,
    ConnectionLatencyResultCallback? onResult,
  }) async {
    final runner = WindowsLatencyJobRunner(
      invoke: (method, arguments) => _invokeMap(method, arguments),
      delay: _latencyPollDelay,
    );
    _latencyJobRunner = runner;
    try {
      return await runner.run(
        nodeTags: nodeTags,
        testUrl: testUrl,
        timeoutMs: timeoutMs,
        concurrency: concurrency,
        isCancelled: () => _disposed || generation != _latencyRunGeneration,
        onResult: onResult,
      );
    } finally {
      if (identical(_latencyJobRunner, runner)) {
        _latencyJobRunner = null;
      }
    }
  }

  static bool _isRetryableLatencyFailure(ConnectionLatencyResult result) {
    return switch (result.failureKind) {
      ConnectionLatencyFailureKind.timeout ||
      ConnectionLatencyFailureKind.httpError ||
      ConnectionLatencyFailureKind.transportError =>
        true,
      _ => false,
    };
  }

  @override
  Future<void> stopConnectionLatencyTest() async {
    _latencyRunGeneration++;
    final runner = _latencyJobRunner;
    _latencyJobRunner = null;
    try {
      await runner?.cancel();
    } catch (error) {
      debugPrint('Windows latency cancellation failed: $error');
    }
  }

  @override
  VpnState get currentState => _state;

  @override
  Stream<VpnState> get stateStream => _stateController.stream;

  void _handleState(Object? value) {
    _updateState(WindowsServiceProtocol.parseState(value));
  }

  void _setUnavailable(String? message) {
    _updateState(VpnState(
      status: VpnStatus.error,
      connectionMode: VpnConnectionMode.tun,
      errorMessage: message ?? 'Windows 后台服务不可用，请重新安装客户端',
      failureReason: VpnFailureReason.coreStartFailed,
      runtimeDetails: const {'error_code': 'service_unavailable'},
    ));
  }

  void _updateState(VpnState next) {
    _state = next;
    if (!_disposed && !_stateController.isClosed) {
      _stateController.add(next);
    }
  }

  Future<Map<String, dynamic>> _invokeMap(
    String method, [
    Map<String, dynamic>? arguments,
  ]) async {
    assert(WindowsServiceProtocol.supportedMethods.contains(method));
    final result =
        await _methodChannel.invokeMethod<Object?>(method, arguments);
    if (result is Map) return Map<String, dynamic>.from(result);
    return const <String, dynamic>{};
  }

  String _runtimeDirectory() {
    final programData =
        Platform.environment['ProgramData'] ?? r'C:\ProgramData';
    return '$programData\\ElephantNetwork\\runtime';
  }

  Future<_WindowsNetworkProfile?> _networkProfile() async {
    final result = await _invokeMap('getNetworkProfile');
    final defaultInterface =
        result['default_interface']?.toString().trim() ?? '';
    final tunIpv4Address = result['tun_ipv4_address']?.toString().trim() ?? '';
    if (result['status'] == 'error' ||
        defaultInterface.isEmpty ||
        tunIpv4Address.isEmpty) {
      _handleState(result['status'] == 'error'
          ? result
          : const <String, dynamic>{
              'status': 'error',
              'error_code': 'default_interface_missing',
              'error_message': '未找到可用的 Windows 默认网络接口',
            });
      return null;
    }
    return _WindowsNetworkProfile(
      defaultInterface: defaultInterface,
      tunIpv4Address: tunIpv4Address,
      strictRoute: result['strict_route'] == true,
    );
  }

  String _sanitizeConfig(
    String jsonConfig,
    String runtimeDir, {
    required String defaultInterface,
    required String tunIpv4Address,
    required bool strictRoute,
  }) {
    final config = Map<String, dynamic>.from(jsonDecode(jsonConfig) as Map);
    config.remove('use_tun_mode');
    config['log'] = {'level': 'warn', 'timestamp': true};

    // ClashMi keeps IPv6 disabled by default on Windows. Preserve Elephant's
    // full-device TUN coverage while matching that destination behavior.
    final dns = config['dns'] is Map
        ? Map<String, dynamic>.from(config['dns'] as Map)
        : <String, dynamic>{};
    dns['strategy'] = 'ipv4_only';
    config['dns'] = dns;

    final inbounds = (config['inbounds'] as List?) ?? <dynamic>[];
    inbounds.removeWhere((dynamic inbound) =>
        inbound is Map &&
        (inbound['type'] == 'tun' || inbound['type'] == 'mixed'));
    inbounds.add({
      'type': 'tun',
      'tag': 'tun-in',
      'interface_name': 'ElephantNetwork',
      'address': [tunIpv4Address],
      'auto_route': true,
      'domain_strategy': 'ipv4_only',
      'endpoint_independent_nat': true,
      'mtu': 1500,
      // strict_route installs a Windows WFP kill switch. On Windows 10 it can
      // reject all traffic while the physical interface is still settling or
      // when another virtualization/VPN filter is present. Explicit interface
      // binding still prevents the sing-box outbound from looping into TUN.
      'strict_route': strictRoute,
      'stack': 'system',
      'sniff': true,
      'sniff_override_destination': true,
    });
    config['inbounds'] = inbounds;

    final outbounds = (config['outbounds'] as List?) ?? <dynamic>[];
    final hasBlockOutbound = outbounds.any(
      (dynamic outbound) =>
          outbound is Map &&
          outbound['type'] == 'block' &&
          outbound['tag'] == 'block',
    );
    if (!hasBlockOutbound) {
      outbounds.add({'type': 'block', 'tag': 'block'});
    }
    config['outbounds'] = outbounds;

    final route =
        config['route'] is Map ? config['route'] as Map : <String, dynamic>{};
    config['route'] = route;
    {
      // ClashMi's default Windows system-proxy path does not carry browser
      // QUIC. Reject it inside TUN so Chromium immediately retries over TCP.
      final rules = (route['rules'] as List?) ?? <dynamic>[];
      dynamic quicFallbackRule;
      for (final dynamic rule in rules) {
        if (rule is Map &&
            rule['network'] == 'udp' &&
            rule['port'] == 443 &&
            rule['outbound'] == 'block') {
          quicFallbackRule = rule;
          break;
        }
      }
      if (quicFallbackRule != null) {
        rules.remove(quicFallbackRule);
      } else {
        quicFallbackRule = {
          'network': 'udp',
          'port': 443,
          'outbound': 'block',
        };
      }
      rules.insert(0, quicFallbackRule);
      route['rules'] = rules;

      route['auto_detect_interface'] = false;
      route['default_interface'] = defaultInterface;
      final ruleSets = route['rule_set'];
      if (ruleSets is List) {
        for (var index = 0; index < ruleSets.length; index += 1) {
          final ruleSet = ruleSets[index];
          if (ruleSet is Map && ruleSet['type'] == 'remote') {
            final tag = ruleSet['tag']?.toString() ?? '';
            if (tag == 'geoip-cn' || tag == 'geosite-cn') {
              ruleSets[index] = {
                'tag': tag,
                'type': 'local',
                'format': ruleSet['format'] ?? 'binary',
                'path': '$runtimeDir\\$tag.srs',
              };
            }
          }
        }
      }
      route['final'] = '节点选择';
    }

    final experimental = config.putIfAbsent(
      'experimental',
      () => <String, dynamic>{},
    );
    if (experimental is Map) {
      final clashApi = experimental.putIfAbsent(
        'clash_api',
        () => <String, dynamic>{},
      );
      if (clashApi is Map) {
        clashApi['external_controller'] = '127.0.0.1:9090';
        clashApi['default_mode'] ??= 'rule';
      }
      final cacheFile = experimental['cache_file'];
      if (cacheFile is Map) cacheFile['path'] = '$runtimeDir\\cache.db';
    }

    return jsonEncode(config);
  }

  @override
  void dispose() {
    if (_disposed) return;
    unawaited(stop().catchError((Object error) {
      debugPrint('Windows service cleanup during dispose failed: $error');
    }));
    _disposed = true;
    _eventSubscription?.cancel();
    _stateController.close();
  }
}

final class _WindowsNetworkProfile {
  const _WindowsNetworkProfile({
    required this.defaultInterface,
    required this.tunIpv4Address,
    required this.strictRoute,
  });

  final String defaultInterface;
  final String tunIpv4Address;
  final bool strictRoute;
}
