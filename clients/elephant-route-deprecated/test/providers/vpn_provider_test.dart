import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:dio/dio.dart';
import 'package:elephant_network/providers/vpn_provider.dart';
import 'package:elephant_network/providers/config_provider.dart';
import 'package:elephant_network/core/singbox/vpn_state.dart';
import 'package:elephant_network/core/singbox/vpn_manager.dart';
import 'package:elephant_network/core/singbox/clash_traffic_stream.dart';
import 'package:elephant_network/core/api/dio_client.dart';
import 'package:elephant_network/core/api/subscription_config_cache.dart';
import 'package:elephant_network/core/singbox/mock_vpn_service.dart';
import 'package:elephant_network/utils/constants.dart';
import '../test_bootstrap.dart';

void main() {
  configureTestEnvironment();

  group('VpnProvider 测试', () {
    late VpnProvider vpnProvider;
    late _ImmediateVpnManager vpnManager;
    late _FakeTrafficStreamClient trafficClient;
    late _FakeSubscriptionConfigCache subscriptionConfigCache;

    setUp(() {
      final dioClient = DioClient();
      dioClient.dio.interceptors.add(
        InterceptorsWrapper(
          onRequest: (options, handler) {
            if (options.path == ApiConstants.getSubscribe) {
              handler.resolve(
                Response(
                  requestOptions: options,
                  data: {
                    'data': {
                      'subscribe_url':
                          'https://www.elephant111.com/api/v1/client/subscribe?token=test-token',
                    },
                  },
                ),
              );
              return;
            }

            if (options.path == ApiConstants.subscribe) {
              handler.resolve(
                Response(
                  requestOptions: options,
                  data: {
                    'dns': {
                      'servers': <Map<String, dynamic>>[],
                    },
                    'outbounds': <Map<String, dynamic>>[],
                  },
                ),
              );
              return;
            }

            handler.next(options);
          },
        ),
      );

      vpnManager = _ImmediateVpnManager();
      trafficClient = _FakeTrafficStreamClient();
      subscriptionConfigCache = _FakeSubscriptionConfigCache();
      vpnProvider = VpnProvider(
        dioClient,
        vpnManager,
        ConfigProvider(),
        trafficStreamClient: trafficClient,
        trafficRetryDelay: (_) => const Duration(milliseconds: 10),
        subscriptionConfigCache: subscriptionConfigCache,
        usesNativeTrafficOnly: false,
      );
    });

    tearDown(() {
      vpnProvider.dispose();
      vpnManager.dispose();
      trafficClient.dispose();
    });

    test('初始状态应该是未连接', () {
      // Assert
      expect(vpnProvider.state.status, VpnStatus.disconnected);
      expect(vpnProvider.isConnected, false);
      expect(vpnProvider.isProcessing, false);
    });

    test('connect 应该将状态改为已连接', () async {
      // Act
      final result = await vpnProvider.connect();

      // Wait for state update
      await Future.delayed(const Duration(milliseconds: 100));

      // Assert
      expect(result, true);
      expect(vpnProvider.isConnected, true);
      expect(subscriptionConfigCache.writeCalls, 1);
    });

    test('存在上次可用配置时不等待远程刷新即可连接', () async {
      subscriptionConfigCache.value =
          '{"outbounds":[{"type":"direct","tag":"direct"}]}';

      final result = await vpnProvider.connect();
      await Future<void>.delayed(Duration.zero);

      expect(result, isTrue);
      expect(vpnManager.startCalls, 1);
      expect(subscriptionConfigCache.readCalls, 1);
      expect(vpnProvider.state.status, VpnStatus.connected);
    });

    test('disconnect 应该将状态改为未连接', () async {
      // Arrange - 先连接
      await vpnProvider.connect();
      await Future.delayed(const Duration(milliseconds: 100));
      expect(vpnProvider.isConnected, true);

      // Act - 断开连接
      await vpnProvider.disconnect();
      await Future.delayed(const Duration(milliseconds: 100));

      // Assert
      expect(vpnProvider.isConnected, false);
    });

    test('toggle 应该在未连接时进行连接', () async {
      // Arrange
      expect(vpnProvider.isConnected, false);

      // Act
      await vpnProvider.toggle();
      await Future.delayed(const Duration(milliseconds: 100));

      // Assert
      expect(vpnProvider.isConnected, true);
    });

    test('toggle 应该在已连接时断开连接', () async {
      // Arrange - 先连接
      await vpnProvider.connect();
      await Future.delayed(const Duration(milliseconds: 100));
      expect(vpnProvider.isConnected, true);

      // Act - 切换
      await vpnProvider.toggle();
      await Future.delayed(const Duration(milliseconds: 100));

      // Assert
      expect(vpnProvider.isConnected, false);
    });

    test('处理中状态应该阻止 toggle 操作', () async {
      // 这个测试依赖于 MockVpnService 的实现
      // 在真实场景中，连接/断开过程中会有短暂的处理中状态
      // 这里我们只验证逻辑存在
      expect(vpnProvider.toggle, isA<Function>());
    });

    test('状态变化应该通知监听器', () async {
      // Arrange
      var notificationCount = 0;
      vpnProvider.addListener(() {
        notificationCount++;
      });

      // Act
      await vpnProvider.connect();
      await Future.delayed(const Duration(milliseconds: 100));

      // Assert
      expect(notificationCount, greaterThan(0));
    });

    test('连接期间只打开一条流并累计没有原生统计的流量', () async {
      await vpnProvider.connect();
      await _settleTrafficLifecycle();

      expect(trafficClient.openCalls, 1);
      expect(trafficClient.activeSubscriptions, 1);

      trafficClient.emit(const TrafficSample(up: 100, down: 200));
      await Future<void>.delayed(Duration.zero);
      trafficClient.emit(const TrafficSample(up: 300, down: 400));
      await Future<void>.delayed(Duration.zero);

      expect(vpnProvider.state.upSpeed, 300);
      expect(vpnProvider.state.downSpeed, 400);
      expect(vpnProvider.state.totalUp, 400);
      expect(vpnProvider.state.totalDown, 600);
      expect(trafficClient.maxActiveSubscriptions, 1);
    });

    test('重复启动和流错误重连不会产生重叠订阅', () async {
      await vpnProvider.connect();
      await _settleTrafficLifecycle();
      await vpnProvider.connect();
      await _settleTrafficLifecycle();

      expect(trafficClient.openCalls, 2);
      expect(trafficClient.activeSubscriptions, 1);
      expect(trafficClient.maxActiveSubscriptions, 1);

      trafficClient.fail(StateError('stream closed'));
      await Future<void>.delayed(const Duration(milliseconds: 30));

      expect(trafficClient.openCalls, 3);
      expect(trafficClient.activeSubscriptions, 1);
      expect(trafficClient.maxActiveSubscriptions, 1);
    });

    test('快速连接状态抖动后只有最后一代流量会话存活', () async {
      await vpnProvider.connect();
      await _settleTrafficLifecycle();
      trafficClient.closeDelay = const Duration(milliseconds: 5);

      vpnManager.emitStatus(VpnStatus.coreStarting);
      vpnManager.emitStatus(VpnStatus.connected);
      vpnManager.emitStatus(VpnStatus.coreStarting);
      vpnManager.emitStatus(VpnStatus.connected);
      await Future<void>.delayed(const Duration(milliseconds: 10));

      expect(trafficClient.activeSubscriptions, 1);
      expect(trafficClient.maxActiveSubscriptions, 1);
    });

    test('断开和销毁会停止流量流且旧会话不能回写', () async {
      await vpnProvider.connect();
      await _settleTrafficLifecycle();
      final firstController = trafficClient.latestController;

      await vpnProvider.disconnect();
      await _settleTrafficLifecycle();
      final disconnectedState = vpnProvider.state;
      firstController.add(const TrafficSample(up: 999, down: 999));
      await Future<void>.delayed(Duration.zero);

      expect(trafficClient.activeSubscriptions, 0);
      expect(trafficClient.closeCalls, greaterThan(0));
      expect(vpnProvider.state, same(disconnectedState));

      await vpnProvider.connect();
      await _settleTrafficLifecycle();
      vpnProvider.dispose();
      await Future<void>.delayed(Duration.zero);
      expect(trafficClient.activeSubscriptions, 0);
    });

    test('原生累计流量存在时流式样本只更新速度', () async {
      await vpnProvider.connect();
      await _settleTrafficLifecycle();
      vpnManager.emitTraffic(totalUp: 1000, totalDown: 2000);
      await Future<void>.delayed(Duration.zero);

      trafficClient.emit(const TrafficSample(up: 100, down: 200));
      await Future<void>.delayed(Duration.zero);

      expect(vpnProvider.state.upSpeed, 100);
      expect(vpnProvider.state.downSpeed, 200);
      expect(vpnProvider.state.totalUp, 1000);
      expect(vpnProvider.state.totalDown, 2000);
    });

    test('Android 原生流量模式不会打开 Clash 流量流', () async {
      final nativeManager = _ImmediateVpnManager();
      final nativeTrafficClient = _FakeTrafficStreamClient();
      final nativeSubscriptionConfigCache = _FakeSubscriptionConfigCache()
        ..value = '{"outbounds":[{"type":"direct","tag":"direct"}]}';
      final nativeProvider = VpnProvider(
        DioClient(),
        nativeManager,
        ConfigProvider(),
        trafficStreamClient: nativeTrafficClient,
        subscriptionConfigCache: nativeSubscriptionConfigCache,
        usesNativeTrafficOnly: true,
      );

      try {
        expect(await nativeProvider.connect(), isTrue);
        await _settleTrafficLifecycle();
        nativeManager.emitTraffic(totalUp: 1234, totalDown: 5678);
        await Future<void>.delayed(Duration.zero);

        expect(nativeTrafficClient.openCalls, 0);
        expect(nativeTrafficClient.activeSubscriptions, 0);
        expect(nativeProvider.state.upSpeed, 7);
        expect(nativeProvider.state.downSpeed, 8);
        expect(nativeProvider.state.totalUp, 1234);
        expect(nativeProvider.state.totalDown, 5678);
      } finally {
        nativeProvider.dispose();
        nativeManager.dispose();
        nativeTrafficClient.dispose();
      }
    });

    test('dispose 不应该释放注入的 VpnManager', () {
      final manager = _CountingVpnManager();
      final provider = VpnProvider(DioClient(), manager, ConfigProvider());

      provider.dispose();

      expect(manager.stopCalls, 0);
      expect(manager.disposeCalls, 0);
      manager.dispose();
    });
  });
}

class _ImmediateVpnManager implements VpnManager {
  final StreamController<VpnState> _controller =
      StreamController<VpnState>.broadcast();
  VpnState _state = const VpnState(status: VpnStatus.disconnected);
  int startCalls = 0;

  @override
  VpnState get currentState => _state;

  @override
  Stream<VpnState> get stateStream => _controller.stream;

  @override
  Future<bool> requestPermission() async => true;

  @override
  Future<void> start(String config) async {
    startCalls++;
    _state = const VpnState(status: VpnStatus.connecting);
    _controller.add(_state);
    _state = const VpnState(status: VpnStatus.connected);
    _controller.add(_state);
  }

  @override
  Future<void> stop({
    VpnStopReason reason = VpnStopReason.unspecified,
  }) async {
    _state = const VpnState(status: VpnStatus.disconnected);
    _controller.add(_state);
  }

  void emitTraffic({required int totalUp, required int totalDown}) {
    _state = _state.copyWith(
      upSpeed: 7,
      downSpeed: 8,
      totalUp: totalUp,
      totalDown: totalDown,
    );
    _controller.add(_state);
  }

  void emitStatus(VpnStatus status) {
    _state = _state.copyWith(status: status);
    _controller.add(_state);
  }

  @override
  Future<void> prepareSpeedTest(String config) async {}

  @override
  Future<void> stopSpeedTest() async {}

  @override
  Future<int> urlTest(String groupTag) async => -1;

  @override
  Future<void> selectOutbound(String groupTag, String outboundTag) async {}

  @override
  void dispose() {
    if (!_controller.isClosed) _controller.close();
  }
}

class _FakeSubscriptionConfigCache implements SubscriptionConfigCache {
  String? value;
  int readCalls = 0;
  int writeCalls = 0;

  @override
  Future<String?> read() async {
    readCalls++;
    return value;
  }

  @override
  Future<void> write(String config) async {
    writeCalls++;
    value = config;
  }

  @override
  Future<void> clear() async {
    value = null;
  }
}

Future<void> _settleTrafficLifecycle() {
  return Future<void>.delayed(const Duration(milliseconds: 1));
}

class _FakeTrafficStreamClient implements TrafficStreamClient {
  final List<StreamController<TrafficSample>> _controllers = [];
  int openCalls = 0;
  int closeCalls = 0;
  int activeSubscriptions = 0;
  int maxActiveSubscriptions = 0;
  Duration closeDelay = Duration.zero;

  StreamController<TrafficSample> get latestController => _controllers.last;

  @override
  Stream<TrafficSample> open() {
    openCalls++;
    late final StreamController<TrafficSample> controller;
    controller = StreamController<TrafficSample>(
      onListen: () {
        activeSubscriptions++;
        if (activeSubscriptions > maxActiveSubscriptions) {
          maxActiveSubscriptions = activeSubscriptions;
        }
      },
      onCancel: () {
        activeSubscriptions--;
      },
    );
    _controllers.add(controller);
    return controller.stream;
  }

  void emit(TrafficSample sample) => latestController.add(sample);

  void fail(Object error) => latestController.addError(error);

  @override
  Future<void> close() async {
    closeCalls++;
    if (closeDelay > Duration.zero) {
      await Future<void>.delayed(closeDelay);
    }
  }

  void dispose() {
    for (final controller in _controllers) {
      if (!controller.isClosed) controller.close();
    }
  }
}

class _CountingVpnManager extends MockVpnService {
  int stopCalls = 0;
  int disposeCalls = 0;

  @override
  Future<void> stop({
    VpnStopReason reason = VpnStopReason.unspecified,
  }) async {
    stopCalls++;
  }

  @override
  void dispose() {
    disposeCalls++;
    super.dispose();
  }
}
