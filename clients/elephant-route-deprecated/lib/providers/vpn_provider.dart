import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'dart:convert';
import 'dart:async';
import 'dart:io';
import 'package:flutter/services.dart';
import 'package:path_provider/path_provider.dart';

import '../core/api/dio_client.dart';
import '../core/api/services/user_service.dart';
import '../core/api/subscription_config_cache.dart';
import '../core/services/app_logger.dart';
import '../core/singbox/clash_traffic_stream.dart';
import '../core/singbox/vpn_manager.dart';
import '../core/singbox/vpn_state.dart';
import '../providers/config_provider.dart';
import '../../utils/constants.dart';

typedef TrafficRetryDelay = Duration Function(int attempt);

class VpnProvider with ChangeNotifier {
  final VpnManager _vpnManager;
  final UserService _userService;
  final ConfigProvider _configProvider;
  final TrafficStreamClient _trafficStreamClient;
  final TrafficRetryDelay _trafficRetryDelay;
  VpnState _state = const VpnState(status: VpnStatus.disconnected);
  StreamSubscription<TrafficSample>? _trafficSubscription;
  Timer? _trafficReconnectTimer;
  int _trafficGeneration = 0;
  int _trafficRetryAttempt = 0;
  bool _hasNativeTrafficTotals = false;
  bool _disposed = false;
  late final StreamSubscription<VpnState> _vpnStateSubscription;

  VpnProvider(
    DioClient dioClient,
    this._vpnManager,
    this._configProvider, {
    TrafficStreamClient? trafficStreamClient,
    TrafficRetryDelay? trafficRetryDelay,
    SubscriptionConfigCache? subscriptionConfigCache,
  })  : _userService = UserService(
          dioClient,
          configCache: subscriptionConfigCache ?? SubscriptionConfigCache(),
        ),
        _trafficStreamClient = trafficStreamClient ??
            ClashTrafficStreamClient(
              endpoint: Uri.parse(ApiConstants.clashTraffic),
            ),
        _trafficRetryDelay = trafficRetryDelay ?? _defaultTrafficRetryDelay {
    // 监听 VPN 状态变化
    _vpnStateSubscription = _vpnManager.stateStream.listen((state) {
      if (_disposed) return;

      final previousState = _state;
      if (state.totalUp > 0 || state.totalDown > 0) {
        _hasNativeTrafficTotals = true;
      }

      // 检测从已连接变为断开状态
      if (previousState.isConnected && state.status == VpnStatus.disconnected) {
        AppLogger.instance
            .info('VPN disconnected; persisting local traffic counters');
        // 保存本次会话的流量增量到本地
        _saveSessionTraffic(previousState.totalUp, previousState.totalDown);

        // 重置本地流量计数器
        _state = state.copyWith(
          totalUp: 0,
          totalDown: 0,
          upSpeed: 0,
          downSpeed: 0,
        );
        _hasNativeTrafficTotals = false;
      } else {
        final shouldPreserveFallbackTotals = !_hasNativeTrafficTotals &&
            state.status != VpnStatus.disconnected &&
            state.totalUp == 0 &&
            state.totalDown == 0;
        _state = shouldPreserveFallbackTotals
            ? state.copyWith(
                totalUp: previousState.totalUp,
                totalDown: previousState.totalDown,
              )
            : state;
      }

      if (!previousState.isConnected && _state.isConnected) {
        unawaited(_startTrafficMonitoring());
      } else if (previousState.isConnected && !_state.isConnected) {
        unawaited(_stopTrafficMonitoring());
      }
      notifyListeners();
    });
  }

  VpnState get state => _state;
  bool get isConnected => _state.isConnected;
  bool get isProcessing => _state.isProcessing;

  /// 切换 VPN 连接状态
  Future<void> toggle() async {
    if (_state.isProcessing) {
      return; // 正在处理中,忽略操作
    }

    if (_state.isConnected) {
      await disconnect();
    } else {
      await connect();
    }
  }

  /// 连接 VPN
  Future<bool> connect() async {
    try {
      // 0. 更新状态为连接中，防止点击无反应
      _state = _state.copyWith(
        status: VpnStatus.connecting,
        resetErrorMessage: true,
        resetFailureReason: true,
      );
      notifyListeners();

      // [FIX] Copy assets (srs files) to filesystem before starting
      await _copyAssetsToFilesystem();

      // 1. 请求 VPN 权限
      final hasPermission = await _vpnManager.requestPermission();
      if (!hasPermission) {
        final permissionState = _vpnManager.currentState;
        _state = permissionState.status == VpnStatus.error
            ? permissionState
            : _state.copyWith(
                status: VpnStatus.error,
                errorMessage: '未获得连接权限',
                failureReason: VpnFailureReason.permissionDenied,
              );
        notifyListeners();
        return false;
      }

      // 2. Prefer the last known-good config. A slow subscription endpoint must
      // not hold the power switch for 15+ seconds on every connection.
      String? config = await _userService.getCachedSubscriptionConfig();
      final usedCachedConfig = config != null;
      if (usedCachedConfig) {
        await AppLogger.instance.info(
          'Using cached subscription config for connect flow, length=${config.length}',
        );
      } else {
        config = await _fetchSubscriptionConfig();
      }

      if (config == null || config.isEmpty) {
        _state = _state.copyWith(
          status: VpnStatus.error,
          errorMessage: '获取订阅配置失败，请检查网络或套餐是否有效',
          failureReason: VpnFailureReason.emptySubscription,
        );
        notifyListeners();
        return false;
      }

      // macOS/Windows 主按钮固定使用 TUN 模式，避免正式桌面客户端
      // 退回到只能覆盖部分应用的系统代理模式。
      final Map<String, dynamic> configMap = jsonDecode(config);
      configMap['use_tun_mode'] = (Platform.isMacOS || Platform.isWindows)
          ? true
          : _configProvider.useTunMode;
      config = jsonEncode(configMap);

      // 注入用户自定义 DNS 配置
      config = _injectDnsConfig(config);

      // 4. 启动内核
      await _vpnManager.start(config);

      if (_vpnManager.currentState.status == VpnStatus.error ||
          _vpnManager.currentState.status == VpnStatus.restoreFailed) {
        _state = _vpnManager.currentState;
        notifyListeners();
        return false;
      }

      if (usedCachedConfig) {
        unawaited(_refreshSubscriptionConfigCache());
      }

      return true;
    } catch (e, stackTrace) {
      await AppLogger.instance
          .error('VPN connect failed', error: e, stackTrace: stackTrace);
      _state = _state.copyWith(
        status: VpnStatus.error,
        errorMessage: e.toString(),
        failureReason: VpnFailureReason.unknown,
      );
      notifyListeners();
      return false;
    }
  }

  Future<String?> _fetchSubscriptionConfig() async {
    try {
      final subscribeInfo = await _userService.getSubscribe();
      final subscribeUrl = subscribeInfo['subscribe_url'] as String?;
      if (subscribeUrl == null || subscribeUrl.isEmpty) return null;

      final uri = Uri.parse(subscribeUrl);
      final token = uri.queryParameters['token'] ??
          (uri.pathSegments.isNotEmpty ? uri.pathSegments.last : null);
      if (token == null || token.isEmpty) return null;

      await AppLogger.instance
          .info('Fetching subscription config for connect flow');
      final rawConfig = await _userService.getSubscriptionConfig(token);
      final config = jsonEncode(rawConfig);
      await AppLogger.instance
          .info('Subscription config encoded, length=${config.length}');
      return config;
    } catch (e) {
      await AppLogger.instance
          .error('Failed to fetch subscription config', error: e);
      return null;
    }
  }

  Future<void> _refreshSubscriptionConfigCache() async {
    final refreshed = await _fetchSubscriptionConfig();
    if (refreshed != null) {
      await AppLogger.instance
          .info('Subscription config refreshed in background');
    }
  }

  /// 断开 VPN
  Future<void> disconnect({
    VpnStopReason reason = VpnStopReason.userToggle,
  }) async {
    try {
      await _stopTrafficMonitoring();
      await _vpnManager.stop(reason: reason);
    } catch (e, stackTrace) {
      await AppLogger.instance
          .error('VPN disconnect failed', error: e, stackTrace: stackTrace);
    }
  }

  Future<void> _startTrafficMonitoring() async {
    final generation = await _clearTrafficMonitoring();
    if (_disposed || generation != _trafficGeneration || !_state.isConnected) {
      return;
    }

    _trafficRetryAttempt = 0;
    _openTrafficStream(generation);
  }

  void _openTrafficStream(int generation) {
    if (_disposed || generation != _trafficGeneration || !_state.isConnected) {
      return;
    }

    try {
      _trafficSubscription = _trafficStreamClient.open().listen(
        (sample) {
          if (_disposed ||
              generation != _trafficGeneration ||
              !_state.isConnected) {
            return;
          }

          _trafficRetryAttempt = 0;
          _state = _state.copyWith(
            upSpeed: sample.up,
            downSpeed: sample.down,
            totalUp: _hasNativeTrafficTotals
                ? _state.totalUp
                : _state.totalUp + sample.up,
            totalDown: _hasNativeTrafficTotals
                ? _state.totalDown
                : _state.totalDown + sample.down,
          );
          notifyListeners();
        },
        onError: (Object _, StackTrace __) {
          _scheduleTrafficReconnect(generation);
        },
        onDone: () {
          _scheduleTrafficReconnect(generation);
        },
        cancelOnError: true,
      );
    } catch (_) {
      _scheduleTrafficReconnect(generation);
    }
  }

  void _scheduleTrafficReconnect(int generation) {
    if (_disposed ||
        generation != _trafficGeneration ||
        !_state.isConnected ||
        _trafficReconnectTimer != null) {
      return;
    }

    final delay = _trafficRetryDelay(_trafficRetryAttempt++);
    _trafficReconnectTimer = Timer(delay, () {
      _trafficReconnectTimer = null;
      unawaited(_restartTrafficStream(generation));
    });
  }

  Future<void> _restartTrafficStream(int generation) async {
    final subscription = _trafficSubscription;
    _trafficSubscription = null;
    await subscription?.cancel();
    await _trafficStreamClient.close();

    if (_disposed || generation != _trafficGeneration || !_state.isConnected) {
      return;
    }
    _openTrafficStream(generation);
  }

  Future<void> _stopTrafficMonitoring() async {
    await _clearTrafficMonitoring();
  }

  Future<int> _clearTrafficMonitoring() async {
    final generation = ++_trafficGeneration;
    _trafficRetryAttempt = 0;
    _trafficReconnectTimer?.cancel();
    _trafficReconnectTimer = null;

    final subscription = _trafficSubscription;
    _trafficSubscription = null;
    await subscription?.cancel();
    await _trafficStreamClient.close();
    return generation;
  }

  /// 保存本次会话流量到本地存储（未上报到后端的流量）
  Future<void> _saveSessionTraffic(int up, int down) async {
    if (up == 0 && down == 0) return;

    try {
      final prefs = await SharedPreferences.getInstance();
      final savedUp = prefs.getInt('unreported_traffic_up') ?? 0;
      final savedDown = prefs.getInt('unreported_traffic_down') ?? 0;

      await prefs.setInt('unreported_traffic_up', savedUp + up);
      await prefs.setInt('unreported_traffic_down', savedDown + down);

      await AppLogger.instance.info(
          'Stored unreported traffic up=${savedUp + up} down=${savedDown + down}');
    } catch (e) {
      await AppLogger.instance
          .error('Failed to persist traffic counters', error: e);
    }
  }

  /// 获取未上报的流量总计（字节）
  Future<int> getUnreportedTraffic() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final up = prefs.getInt('unreported_traffic_up') ?? 0;
      final down = prefs.getInt('unreported_traffic_down') ?? 0;
      return up + down;
    } catch (e) {
      await AppLogger.instance
          .error('Failed to read unreported traffic', error: e);
      return 0;
    }
  }

  /// 清空未上报流量（当后端已同步时调用）
  Future<void> clearUnreportedTraffic() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      await prefs.remove('unreported_traffic_up');
      await prefs.remove('unreported_traffic_down');
      await AppLogger.instance.info('Cleared unreported traffic counters');
    } catch (e) {
      await AppLogger.instance
          .error('Failed to clear unreported traffic', error: e);
    }
  }

  /// Copy SRS assets to working directory
  Future<void> _copyAssetsToFilesystem() async {
    try {
      // Use getApplicationSupportDirectory to match Android's context.getFilesDir()
      final directory = await getApplicationSupportDirectory();
      final singBoxDir = Directory('${directory.path}/sing-box');
      if (!await singBoxDir.exists()) {
        await singBoxDir.create(recursive: true);
      }

      final files = ['geosite-cn.srs', 'geoip-cn.srs'];
      for (final fileName in files) {
        final file = File('${singBoxDir.path}/$fileName');
        // Always overwrite to ensure latest version
        final byteData = await rootBundle.load('assets/srs/$fileName');
        await file.writeAsBytes(byteData.buffer.asUint8List());
        await AppLogger.instance.info('Copied asset $fileName to ${file.path}');
      }
    } catch (e) {
      await AppLogger.instance.error('Error copying SRS assets', error: e);
    }
  }

  @override
  void dispose() {
    if (_disposed) return;
    _disposed = true;
    _trafficGeneration++;
    _trafficReconnectTimer?.cancel();
    _trafficReconnectTimer = null;
    final trafficSubscription = _trafficSubscription;
    _trafficSubscription = null;
    unawaited(() async {
      await trafficSubscription?.cancel();
      await _trafficStreamClient.close();
    }());
    _vpnStateSubscription.cancel();
    super.dispose();
  }

  /// 注入用户自定义 DNS 配置到 Sing-box 配置中
  String _injectDnsConfig(String jsonConfig) {
    try {
      final Map<String, dynamic> config = jsonDecode(jsonConfig);

      // 读取用户设置的 DNS
      final foreignDns = _configProvider.foreignDns;
      final domesticDns = _configProvider.domesticDns;

      if (config.containsKey('dns') && config['dns'] is Map) {
        final dnsConfig = config['dns'] as Map<String, dynamic>;
        if (dnsConfig.containsKey('servers') && dnsConfig['servers'] is List) {
          final servers = dnsConfig['servers'] as List<dynamic>;

          for (var server in servers) {
            if (server is Map) {
              final tag = server['tag'];
              // 替换 remote (国外) 的地址
              if (tag == 'remote' && server.containsKey('address')) {
                server['address'] = foreignDns;
                AppLogger.instance.info('Injected foreign DNS: $foreignDns');
              }
              // 替换 local/国内 DNS 的地址
              else if ((tag == 'local' || tag == 'domestic') &&
                  server.containsKey('address')) {
                server['address'] = domesticDns;
                AppLogger.instance.info('Injected domestic DNS: $domesticDns');
              }
            }
          }
        }
      }
      return jsonEncode(config);
    } catch (e) {
      AppLogger.instance.error('DNS injection failed', error: e);
      return jsonConfig; // 如果解析失败，返回原配置
    }
  }
}

Duration _defaultTrafficRetryDelay(int attempt) {
  const delays = <Duration>[
    Duration(seconds: 1),
    Duration(seconds: 2),
    Duration(seconds: 5),
    Duration(seconds: 10),
  ];
  return delays[attempt.clamp(0, delays.length - 1)];
}
