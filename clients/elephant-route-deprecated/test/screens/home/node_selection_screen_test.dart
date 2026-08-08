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
