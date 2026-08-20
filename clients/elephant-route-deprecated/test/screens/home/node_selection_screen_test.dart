import 'dart:async';

import 'package:elephant_network/core/api/dio_client.dart';
import 'package:elephant_network/core/api/domain_resolver.dart';
import 'package:elephant_network/core/singbox/vpn_manager.dart';
import 'package:elephant_network/core/singbox/vpn_state.dart';
import 'package:elephant_network/models/proxy_node.dart';
import 'package:elephant_network/providers/config_provider.dart';
import 'package:elephant_network/providers/node_provider.dart';
import 'package:elephant_network/providers/vpn_provider.dart';
import 'package:elephant_network/screens/home/node_selection_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:provider/provider.dart';

import '../../test_bootstrap.dart';

void main() {
  configureTestEnvironment();

  testWidgets('refreshes nodes once whenever the node page is opened',
      (tester) async {
    final vpnManager = _FakeVpnManager();
    final configProvider = ConfigProvider();
    final dioClient = DioClient(domainResolver: _FixedDomainResolver());
    final provider = _RefreshTrackingNodeProvider(
      dioClient,
      vpnManager,
      configProvider,
    );
    final vpnProvider = VpnProvider(
      dioClient,
      vpnManager,
      configProvider,
      usesNativeTrafficOnly: true,
    );
    addTearDown(() {
      provider.dispose();
      vpnProvider.dispose();
      vpnManager.dispose();
    });

    await tester.pumpWidget(
      MultiProvider(
        providers: [
          ChangeNotifierProvider<NodeProvider>.value(value: provider),
          ChangeNotifierProvider<VpnProvider>.value(value: vpnProvider),
        ],
        child: const MaterialApp(home: NodeSelectionScreen()),
      ),
    );
    await tester.pump();

    expect(provider.fetchCount, 1);
  });

  testWidgets('keeps the page open until node migration succeeds',
      (tester) async {
    final harness = _SelectionHarness();
    addTearDown(harness.dispose);
    await harness.openNodeScreen(tester);

    await tester.tap(find.text('existing-node'));
    await tester.pump();

    expect(harness.nodeProvider.selectionCount, 1);
    expect(find.text('选择节点'), findsOneWidget);

    harness.nodeProvider.completeSelection(true);
    await tester.pumpAndSettle();

    expect(find.text('选择节点'), findsNothing);
  });

  testWidgets('keeps the page open and reports a failed node migration',
      (tester) async {
    final harness = _SelectionHarness();
    addTearDown(harness.dispose);
    await harness.openNodeScreen(tester);

    await tester.tap(find.text('existing-node'));
    await tester.pump();
    harness.nodeProvider.completeSelection(
      false,
      errorMessage: '节点切换失败: switch rejected',
    );
    await tester.pump();

    expect(find.text('选择节点'), findsOneWidget);
    expect(find.textContaining('节点切换失败'), findsOneWidget);

    await tester.pump(const Duration(seconds: 3));
  });

  testWidgets('ignores duplicate node taps while migration is pending',
      (tester) async {
    final harness = _SelectionHarness();
    addTearDown(harness.dispose);
    await harness.openNodeScreen(tester);

    await tester.tap(find.text('existing-node'));
    await tester.pump();
    await tester.tap(find.text('existing-node'));
    await tester.pump();

    expect(harness.nodeProvider.selectionCount, 1);

    harness.nodeProvider.completeSelection(true);
    await tester.pumpAndSettle();
  });
}

class _RefreshTrackingNodeProvider extends NodeProvider {
  // The remaining NodeProvider constructor parameter names are library-private.
  // ignore: use_super_parameters
  _RefreshTrackingNodeProvider(
    DioClient dioClient,
    VpnManager vpnManager,
    ConfigProvider configProvider,
  ) : super(dioClient, vpnManager, configProvider);

  int fetchCount = 0;
  int selectionCount = 0;
  Completer<bool> selectionCompleter = Completer<bool>();
  String? selectionErrorMessage;

  @override
  String? get errorMessage => selectionErrorMessage ?? super.errorMessage;

  @override
  List<ProxyNode> get nodes => [
        ProxyNode(
          name: 'existing-node',
          type: 'vless',
          server: '127.0.0.1',
          port: 443,
        ),
      ];

  @override
  Future<void> fetchNodes() async {
    fetchCount++;
  }

  @override
  Future<bool> selectNode(ProxyNode node) async {
    selectionCount++;
    return selectionCompleter.future;
  }

  void completeSelection(bool applied, {String? errorMessage}) {
    selectionErrorMessage = errorMessage;
    selectionCompleter.complete(applied);
    notifyListeners();
  }
}

class _SelectionHarness {
  _SelectionHarness()
      : vpnManager = _FakeVpnManager(),
        configProvider = ConfigProvider(),
        dioClient = DioClient(domainResolver: _FixedDomainResolver()) {
    nodeProvider = _RefreshTrackingNodeProvider(
      dioClient,
      vpnManager,
      configProvider,
    );
    vpnProvider = VpnProvider(
      dioClient,
      vpnManager,
      configProvider,
      usesNativeTrafficOnly: true,
    );
  }

  final _FakeVpnManager vpnManager;
  final ConfigProvider configProvider;
  final DioClient dioClient;
  late final _RefreshTrackingNodeProvider nodeProvider;
  late final VpnProvider vpnProvider;

  Future<void> openNodeScreen(WidgetTester tester) async {
    await tester.pumpWidget(
      MultiProvider(
        providers: [
          ChangeNotifierProvider<NodeProvider>.value(value: nodeProvider),
          ChangeNotifierProvider<VpnProvider>.value(value: vpnProvider),
        ],
        child: MaterialApp(
          home: Builder(
            builder: (context) => Scaffold(
              body: Center(
                child: ElevatedButton(
                  onPressed: () => Navigator.of(context).push(
                    MaterialPageRoute<void>(
                      builder: (_) => const NodeSelectionScreen(),
                    ),
                  ),
                  child: const Text('open nodes'),
                ),
              ),
            ),
          ),
        ),
      ),
    );
    await tester.tap(find.text('open nodes'));
    await tester.pumpAndSettle();
  }

  void dispose() {
    nodeProvider.dispose();
    vpnProvider.dispose();
    vpnManager.dispose();
  }
}

class _FixedDomainResolver extends DomainResolver {
  @override
  Future<String> resolve({bool force = false}) async => 'https://example.com';
}

class _FakeVpnManager implements VpnManager {
  final StreamController<VpnState> _states =
      StreamController<VpnState>.broadcast();

  @override
  VpnState get currentState => const VpnState(status: VpnStatus.disconnected);

  @override
  Stream<VpnState> get stateStream => _states.stream;

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

  @override
  Future<void> selectOutbound(String groupTag, String outboundTag) async {}

  @override
  void dispose() {
    _states.close();
  }
}
