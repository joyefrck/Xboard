import 'package:elephant_network/core/singbox/macos_clash_controller.dart';
import 'package:elephant_network/core/singbox/macos_outbound_switch_coordinator.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('confirms the target then closes only old selector connections',
      () async {
    final control = _FakeMacosClashControl(
      selectorReads: ['东京', '香港'],
      connections: const [
        MacosClashConnection(
          id: 'old',
          chains: ['东京', '节点选择'],
        ),
        MacosClashConnection(id: 'direct', chains: ['direct']),
        MacosClashConnection(
          id: 'other-group',
          chains: ['东京', '其他组'],
        ),
        MacosClashConnection(
          id: 'new',
          chains: ['香港', '节点选择'],
        ),
      ],
    );

    final result = await MacosOutboundSwitchCoordinator(control).switchOutbound(
      groupTag: '节点选择',
      targetOutbound: '香港',
    );

    expect(control.events, [
      'read:节点选择',
      'connections',
      'select:节点选择:香港',
      'read:节点选择',
      'close:old',
    ]);
    expect(result.previousOutbound, '东京');
    expect(result.targetOutbound, '香港');
    expect(result.closedConnectionCount, 1);
  });

  test('selecting the current outbound is a no-op', () async {
    final control = _FakeMacosClashControl(
      selectorReads: ['香港'],
      connections: const [
        MacosClashConnection(
          id: 'existing',
          chains: ['香港', '节点选择'],
        ),
      ],
    );

    final result = await MacosOutboundSwitchCoordinator(control).switchOutbound(
      groupTag: '节点选择',
      targetOutbound: '香港',
    );

    expect(control.events, ['read:节点选择']);
    expect(result.previousOutbound, '香港');
    expect(result.closedConnectionCount, 0);
  });

  test('does not close connections when selector confirmation mismatches',
      () async {
    final control = _FakeMacosClashControl(
      selectorReads: ['东京', '东京'],
      connections: const [
        MacosClashConnection(
          id: 'old',
          chains: ['东京', '节点选择'],
        ),
      ],
    );

    await expectLater(
      MacosOutboundSwitchCoordinator(control).switchOutbound(
        groupTag: '节点选择',
        targetOutbound: '香港',
      ),
      throwsA(isA<MacosOutboundSwitchException>()),
    );

    expect(control.closedConnectionIds, isEmpty);
    expect(control.events, [
      'read:节点选择',
      'connections',
      'select:节点选择:香港',
      'read:节点选择',
    ]);
  });

  test('reports partial cleanup without touching unrelated connections',
      () async {
    final control = _FakeMacosClashControl(
      selectorReads: ['东京', '香港'],
      connections: const [
        MacosClashConnection(
          id: 'old-1',
          chains: ['东京', '节点选择'],
        ),
        MacosClashConnection(
          id: 'old-2',
          chains: ['节点选择', '东京'],
        ),
        MacosClashConnection(
          id: 'direct',
          chains: ['direct'],
        ),
      ],
      failingConnectionId: 'old-2',
    );

    await expectLater(
      MacosOutboundSwitchCoordinator(control).switchOutbound(
        groupTag: '节点选择',
        targetOutbound: '香港',
      ),
      throwsA(
        isA<MacosOutboundSwitchException>()
            .having(
              (error) => error.closedConnectionCount,
              'closedConnectionCount',
              1,
            )
            .having(
              (error) => error.remainingConnectionCount,
              'remainingConnectionCount',
              1,
            ),
      ),
    );

    expect(control.closedConnectionIds, ['old-1']);
    expect(control.events, [
      'read:节点选择',
      'connections',
      'select:节点选择:香港',
      'read:节点选择',
      'close:old-1',
      'close:old-2',
      'select:节点选择:东京',
    ]);
  });
}

class _FakeMacosClashControl implements MacosClashControl {
  _FakeMacosClashControl({
    required List<String> selectorReads,
    required this.connections,
    this.failingConnectionId,
  }) : _selectorReads = List<String>.of(selectorReads);

  final List<String> _selectorReads;
  final List<MacosClashConnection> connections;
  final String? failingConnectionId;
  final List<String> events = [];
  final List<String> closedConnectionIds = [];

  @override
  Future<String> selectedOutbound(String groupTag) async {
    events.add('read:$groupTag');
    return _selectorReads.removeAt(0);
  }

  @override
  Future<List<MacosClashConnection>> activeConnections() async {
    events.add('connections');
    return connections;
  }

  @override
  Future<void> selectOutbound(String groupTag, String outboundTag) async {
    events.add('select:$groupTag:$outboundTag');
  }

  @override
  Future<void> closeConnection(String connectionId) async {
    events.add('close:$connectionId');
    if (connectionId == failingConnectionId) {
      throw const MacosClashControllerException(
        'close rejected',
        statusCode: 503,
      );
    }
    closedConnectionIds.add(connectionId);
  }

  @override
  Future<int> urlTest(
    String proxyTag, {
    String testUrl = 'https://www.gstatic.com/generate_204',
    int timeoutMs = 3000,
  }) async =>
      -1;
}
