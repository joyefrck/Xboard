import 'dart:async';

import 'package:dio/dio.dart';
import 'package:elephant_network/core/api/dio_client.dart';
import 'package:elephant_network/core/api/domain_resolver.dart';
import 'package:elephant_network/core/singbox/connection_latency_manager.dart';
import 'package:elephant_network/core/singbox/vpn_manager.dart';
import 'package:elephant_network/core/singbox/vpn_state.dart';
import 'package:elephant_network/providers/config_provider.dart';
import 'package:elephant_network/providers/node_provider.dart';
import 'package:elephant_network/utils/constants.dart';
import 'package:flutter_test/flutter_test.dart';

import '../test_bootstrap.dart';

void main() {
  configureTestEnvironment();

  late _LatencyVpnManager vpnManager;
  late NodeProvider provider;

  setUp(() async {
    final dioClient = DioClient(domainResolver: _FixedDomainResolver());
    dioClient.dio.interceptors.add(InterceptorsWrapper(
      onRequest: (options, handler) {
        if (options.path == ApiConstants.getSubscribe) {
          handler.resolve(Response<dynamic>(
            requestOptions: options,
            statusCode: 200,
            data: const {
              'data': {
                'subscribe_url':
                    'https://example.com/api/v1/client/subscribe/test-token',
              },
            },
          ));
          return;
        }
        if (options.path == ApiConstants.subscribe) {
          handler.resolve(Response<dynamic>(
            requestOptions: options,
            statusCode: 200,
            data: const {
              'outbounds': [
                {
                  'type': 'vless',
                  'tag': 'node-timeout',
                  'server': '127.0.0.2',
                  'server_port': 443,
                },
                {
                  'type': 'vless',
                  'tag': 'node-failed',
                  'server': '127.0.0.3',
                  'server_port': 443,
                },
                {
                  'type': 'vless',
                  'tag': 'node-good',
                  'server': '127.0.0.1',
                  'server_port': 443,
                },
              ],
            },
          ));
          return;
        }
        handler.next(options);
      },
    ));
    vpnManager = _LatencyVpnManager();
    provider = NodeProvider(
      dioClient,
      vpnManager,
      ConfigProvider(),
      connectionLatencyDelay: const Duration(milliseconds: 20),
      connectionLatencySafetyTimeout: const Duration(milliseconds: 40),
    );
    await provider.fetchNodes();
  });

  tearDown(() {
    provider.dispose();
    vpnManager.dispose();
  });

  test('publishes typed final results and selects only positive latency',
      () async {
    await provider.testAllLatencies();

    expect(provider.latencyResultFor('node-good')?.latencyMs, 80);
    expect(
      provider.latencyResultFor('node-good')?.source,
      ConnectionLatencySource.clashFallback,
    );
    expect(
      provider.latencyResultFor('node-timeout')?.failureKind,
      ConnectionLatencyFailureKind.timeout,
    );
    expect(
      provider.latencyResultFor('node-failed')?.failureKind,
      ConnectionLatencyFailureKind.serviceError,
    );
    expect(provider.autoSelectedRealNode?.name, 'node-good');
    expect(vpnManager.selectedNodes.last, 'node-good');
  });

  test('refresh clears typed results and does not preserve failed latency',
      () async {
    await provider.testAllLatencies();
    await provider.fetchNodes();

    expect(provider.latencyResultFor('node-good'), isNull);
    expect(provider.latencyResultFor('node-timeout'), isNull);
    expect(
      provider.nodes.singleWhere((node) => node.name == 'node-good').latency,
      80,
    );
    expect(
      provider.nodes.singleWhere((node) => node.name == 'node-timeout').latency,
      isNull,
    );
  });

  test('only the latest connected transition starts a latency test', () async {
    vpnManager.emit(VpnStatus.disconnected);
    vpnManager.emit(VpnStatus.connected);
    await Future<void>.delayed(const Duration(milliseconds: 5));
    vpnManager.emit(VpnStatus.disconnected);
    vpnManager.emit(VpnStatus.connected);

    await Future<void>.delayed(const Duration(milliseconds: 50));

    expect(vpnManager.latencyTestCalls, 1);
  });

  test('safety timeout releases a hanging latency test', () async {
    vpnManager.hangLatencyTest = true;

    await provider.testAllLatencies();

    expect(provider.isTestingLatency, isFalse);
    expect(provider.isLoadingNodes, isFalse);
    expect(vpnManager.stopLatencyTestCalls, 1);
    for (final node in provider.realNodes) {
      expect(
        provider.latencyResultFor(node.name)?.failureKind,
        ConnectionLatencyFailureKind.timeout,
      );
    }
  });

  test('disconnect releases latency state and ignores a late callback',
      () async {
    vpnManager.hangLatencyTest = true;
    final running = provider.testAllLatencies();
    await vpnManager.latencyTestStarted.future;

    expect(provider.isTestingLatency, isTrue);
    expect(provider.isLoadingNodes, isFalse);

    vpnManager.emit(VpnStatus.disconnected);
    await running;

    expect(provider.isTestingLatency, isFalse);
    expect(vpnManager.stopLatencyTestCalls, 1);

    vpnManager.emitLateResult(
      'node-good',
      const ConnectionLatencyResult(
        latencyMs: 12,
        elapsedMs: 12,
        source: ConnectionLatencySource.connectionProbe,
      ),
    );
    await Future<void>.delayed(Duration.zero);

    expect(provider.latencyResultFor('node-good'), isNull);
  });
}

class _FixedDomainResolver extends DomainResolver {
  @override
  Future<String> resolve({bool force = false}) async => 'https://example.com';
}

class _LatencyVpnManager implements VpnManager, ConnectionLatencyManager {
  final StreamController<VpnState> _stateController =
      StreamController<VpnState>.broadcast();
  final List<String> selectedNodes = [];
  VpnState _currentState = const VpnState(status: VpnStatus.connected);
  int latencyTestCalls = 0;
  int stopLatencyTestCalls = 0;
  bool hangLatencyTest = false;
  Completer<void> latencyTestStarted = Completer<void>();
  Completer<Map<String, ConnectionLatencyResult>>? _hangingLatencyTest;
  ConnectionLatencyResultCallback? _hangingResultCallback;

  @override
  VpnState get currentState => _currentState;

  @override
  Stream<VpnState> get stateStream => _stateController.stream;

  @override
  Future<Map<String, ConnectionLatencyResult>> testConnectionLatencies({
    required List<String> nodeTags,
    required String testUrl,
    required int timeoutMs,
    int concurrency = 4,
    ConnectionLatencyResultCallback? onResult,
  }) async {
    latencyTestCalls++;
    if (hangLatencyTest) {
      if (!latencyTestStarted.isCompleted) {
        latencyTestStarted.complete();
      }
      _hangingResultCallback = onResult;
      _hangingLatencyTest = Completer<Map<String, ConnectionLatencyResult>>();
      return _hangingLatencyTest!.future;
    }
    const results = <String, ConnectionLatencyResult>{
      'node-good': ConnectionLatencyResult(
        latencyMs: 80,
        elapsedMs: 90,
        source: ConnectionLatencySource.clashFallback,
      ),
      'node-timeout': ConnectionLatencyResult(
        latencyMs: -1,
        elapsedMs: 5000,
        failureKind: ConnectionLatencyFailureKind.timeout,
        source: ConnectionLatencySource.clashFallback,
      ),
      'node-failed': ConnectionLatencyResult(
        latencyMs: -1,
        elapsedMs: 300,
        failureKind: ConnectionLatencyFailureKind.serviceError,
        source: ConnectionLatencySource.clashFallback,
      ),
    };
    for (final nodeTag in nodeTags) {
      onResult?.call(nodeTag, results[nodeTag]!);
    }
    return results;
  }

  @override
  Future<void> stopConnectionLatencyTest() async {
    stopLatencyTestCalls++;
    final hanging = _hangingLatencyTest;
    if (hanging != null && !hanging.isCompleted) {
      hanging.complete(const <String, ConnectionLatencyResult>{});
    }
  }

  void emitLateResult(String nodeTag, ConnectionLatencyResult result) {
    _hangingResultCallback?.call(nodeTag, result);
  }

  @override
  Future<void> selectOutbound(String groupTag, String outboundTag) async {
    selectedNodes.add(outboundTag);
  }

  @override
  Future<bool> requestPermission() async => true;

  @override
  Future<void> start(String config) async {}

  @override
  Future<void> prepareSpeedTest(String config) async {}

  @override
  Future<void> stopSpeedTest() async {}

  @override
  Future<void> stop({
    VpnStopReason reason = VpnStopReason.unspecified,
  }) async {}

  @override
  Future<int> urlTest(String groupTag) async => -1;

  void emit(VpnStatus status) {
    _currentState = _currentState.copyWith(status: status);
    _stateController.add(_currentState);
  }

  @override
  void dispose() {
    if (!_stateController.isClosed) {
      _stateController.close();
    }
  }
}
