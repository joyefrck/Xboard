import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';

import 'android_latency_config.dart';
import 'android_latency_session.dart';
import 'connection_latency_manager.dart';
import 'vpn_manager.dart';
import 'vpn_state.dart';

/// 真实的 VPN 服务实现(对接原生 sing-box 插件)
class RealVpnService implements VpnManager, ConnectionLatencyManager {
  static const MethodChannel _channel =
      MethodChannel('com.elephant.network/vpn');
  static const EventChannel _eventChannel =
      EventChannel('com.elephant.network/vpn_state');

  final _stateController = StreamController<VpnState>.broadcast();
  VpnState _currentState = const VpnState(status: VpnStatus.disconnected);
  StreamSubscription? _eventSubscription;
  AndroidLatencySession? _latencySession;
  List<int> _latencyWorkerPorts = const <int>[];
  Set<String> _latencyNodeTags = const <String>{};
  bool _latencyWorkersReady = false;

  RealVpnService() {
    // 监听原生端回传的状态与流量数据
    _eventSubscription = _eventChannel.receiveBroadcastStream().listen((data) {
      if (data != null) {
        _handleNativeStateUpdate(data);
      }
    });
  }

  @override
  Stream<VpnState> get stateStream => _stateController.stream;

  @override
  VpnState get currentState => _currentState;

  @override
  Future<bool> requestPermission() async {
    try {
      final bool? result = await _channel.invokeMethod('requestPermission');
      return result ?? false;
    } on PlatformException catch (e) {
      debugPrint("请求权限失败: ${e.message}");
      return false;
    }
  }

  @override
  Future<int> urlTest(String groupTag) async {
    try {
      // Send URL_TEST action (Fire and Forget)
      // Result comes back via stateStream
      await _channel.invokeMethod('urlTest', {'groupTag': groupTag});
      return 0; // Return 0 to indicate request sent
    } on PlatformException catch (e) {
      debugPrint("延迟测试失败: ${e.message}");
      return -1;
    }
  }

  // ... (start/stop/selectOutbound existing)

  @override
  Future<void> start(String config) async {
    await stopConnectionLatencyTest();
    var runtimeConfig = config;
    _latencyWorkersReady = false;
    _latencyWorkerPorts = const <int>[];
    _latencyNodeTags = const <String>{};
    if (!kIsWeb && Platform.isAndroid) {
      try {
        final ports = await _allocateWorkerPorts(4);
        final latencyConfig = AndroidLatencyConfigBuilder.build(
          sourceConfig: config,
          workerPorts: ports,
        );
        runtimeConfig = latencyConfig.configJson;
        _latencyWorkerPorts = List<int>.unmodifiable(ports);
        _latencyNodeTags = Set<String>.unmodifiable(latencyConfig.nodeTags);
        _latencyWorkersReady = true;
        debugPrint(
          'RealVpnService: Android latency workers prepared '
          'count=${ports.length} nodes=${latencyConfig.nodeTags.length}',
        );
      } catch (error) {
        // Never log the source config because it contains proxy credentials.
        debugPrint(
          'RealVpnService: Android latency workers unavailable: '
          '${error.runtimeType}',
        );
      }
    }
    try {
      // 在原生端启动 sing-box
      debugPrint(
          'RealVpnService: invoking start with config length: ${runtimeConfig.length}');
      await _channel.invokeMethod('start', {'config': runtimeConfig});
      debugPrint('RealVpnService: start invoked successfully');
    } on PlatformException catch (e) {
      debugPrint('RealVpnService: start failed with exception: $e');
      _updateState(VpnStatus.disconnected, errorMessage: e.message);
    }
  }

  @override
  Future<void> prepareSpeedTest(String config) async {
    try {
      await _channel.invokeMethod('prepareSpeedTest', {'config': config});
    } on PlatformException catch (e) {
      debugPrint("准备测速核心失败: ${e.message}");
    }
  }

  @override
  Future<void> stopSpeedTest() async {
    try {
      await _channel.invokeMethod('stopSpeedTest');
    } on PlatformException catch (e) {
      debugPrint("停止测速核心失败: ${e.message}");
    }
  }

  @override
  Future<void> stop({
    VpnStopReason reason = VpnStopReason.unspecified,
  }) async {
    try {
      await stopConnectionLatencyTest();
      await _channel.invokeMethod('stop');
    } on PlatformException catch (e) {
      debugPrint("停止失败: ${e.message}");
    }
  }

  @override
  Future<void> selectOutbound(String groupTag, String outboundTag) async {
    try {
      await _channel.invokeMethod('selectOutbound', {
        'groupTag': groupTag,
        'outboundTag': outboundTag,
      });
    } on PlatformException catch (e) {
      debugPrint("切换节点失败: ${e.message}");
    }
  }

  @override
  Future<Map<String, ConnectionLatencyResult>> testConnectionLatencies({
    required List<String> nodeTags,
    required String testUrl,
    required int timeoutMs,
    required int concurrency,
    ConnectionLatencyResultCallback? onResult,
  }) async {
    if (_currentState.status != VpnStatus.connected) {
      throw StateError('请先开启加速后再测速');
    }
    if (!_latencyWorkersReady || _latencyWorkerPorts.isEmpty) {
      throw const ConnectionLatencyUnavailableException(
        '测速服务未就绪，请重新连接后重试',
      );
    }
    if (nodeTags.any((tag) => !_latencyNodeTags.contains(tag))) {
      throw const ConnectionLatencyUnavailableException(
        '测速服务未就绪，请重新连接后重试',
      );
    }

    await stopConnectionLatencyTest();
    final session = AndroidLatencySession(
      workerPorts: _latencyWorkerPorts,
    );
    _latencySession = session;
    try {
      return await session.run(
        nodeTags: nodeTags,
        testUrl: testUrl,
        timeoutMs: timeoutMs,
        concurrency: concurrency,
        onResult: onResult,
      );
    } finally {
      if (identical(_latencySession, session)) {
        _latencySession = null;
      }
      await session.stop();
    }
  }

  @override
  Future<void> stopConnectionLatencyTest() async {
    final session = _latencySession;
    _latencySession = null;
    await session?.stop();
  }

  @override
  void dispose() {
    unawaited(stopConnectionLatencyTest());
    _eventSubscription?.cancel();
    _stateController.close();
  }

  static Future<List<int>> _allocateWorkerPorts(int count) async {
    final sockets = <ServerSocket>[];
    try {
      for (var index = 0; index < count; index++) {
        sockets.add(await ServerSocket.bind(InternetAddress.loopbackIPv4, 0));
      }
      return sockets.map((socket) => socket.port).toList(growable: false);
    } finally {
      await Future.wait(sockets.map((socket) => socket.close()));
    }
  }

  /// 处理来自原生端的数据更新
  void _handleNativeStateUpdate(dynamic data) {
    try {
      // debugPrint('RealVpnService: received native update: $data'); // reduce noise
      // 假设原生端传回的是 JSON 字符串或 Map
      Map<String, dynamic> map;
      if (data is String) {
        map = jsonDecode(data);
      } else {
        map = Map<String, dynamic>.from(data);
      }

      // 根据控制台数据更新状态
      final statusStr = map['status'] as String?;
      VpnStatus status = _currentState.status;
      if (statusStr != null) {
        status = _parseVpnStatus(statusStr);
      }

      // Parse latency update if present
      Map<String, int>? latencyMap;
      if (map.containsKey('latency_update')) {
        final latencyJsonStr = map['latency_update'] as String;
        try {
          final latencyJson =
              jsonDecode(latencyJsonStr) as Map<String, dynamic>;
          latencyMap =
              latencyJson.map((key, value) => MapEntry(key, value as int));
          if (kDebugMode) {
            debugPrint(
              '[SPEED_TEST_DART] Parsed latency map size: ${latencyMap.length}',
            );
          }
        } catch (e) {
          debugPrint("[SPEED_TEST_DART] Error parsing latency update: $e");
        }
      }

      _currentState = _currentState.copyWith(
        status: status,
        upSpeed: map['up_speed'] ?? 0,
        downSpeed: map['down_speed'] ?? 0,
        totalUp: map['total_up'] ?? _currentState.totalUp,
        totalDown: map['total_down'] ?? _currentState.totalDown,
        errorMessage: map['error_message'],
        latencyMap: latencyMap, // Update latency map
        resetLatencyMap: !map.containsKey('latency_update'),
      );

      _stateController.add(_currentState);
    } catch (e) {
      debugPrint("解析原生状态数据失败: $e");
    }
  }

  VpnStatus _parseVpnStatus(String status) {
    switch (status) {
      case 'connected':
        return VpnStatus.connected;
      case 'connecting':
        return VpnStatus.connecting;
      case 'disconnecting':
        return VpnStatus.disconnecting;
      case 'disconnected':
      default:
        return VpnStatus.disconnected;
    }
  }

  void _updateState(VpnStatus status, {String? errorMessage}) {
    _currentState = _currentState.copyWith(
      status: status,
      errorMessage: errorMessage,
    );
    _stateController.add(_currentState);
  }
}
