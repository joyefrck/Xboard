# macOS Node Switch Connection Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (- [ ]) syntax for tracking.

**Goal:** Make a macOS node switch complete only after sing-box confirms the target selector and all tracked connections through the old selector member have been closed, without restarting the core.

**Architecture:** Extend the loopback Clash controller with typed selector and connection operations, then place the migration sequence in a focused MacosOutboundSwitchCoordinator. MacosVpnService invokes the coordinator before persisting the selected default, while NodeProvider and the node screen expose and await the real asynchronous outcome.

**Tech Stack:** Flutter/Dart, Dio loopback HTTP, sing-box Clash API, flutter_test.

---

## File Structure

- Modify clients/elephant-route-deprecated/lib/core/singbox/macos_clash_controller.dart: typed selector reads, connection snapshots, and per-connection close calls.
- Create clients/elephant-route-deprecated/lib/core/singbox/macos_outbound_switch_coordinator.dart: migration state machine with no UI or filesystem responsibility.
- Modify clients/elephant-route-deprecated/lib/core/singbox/macos_vpn_service.dart: coordinator wiring and post-migration atomic config persistence.
- Modify clients/elephant-route-deprecated/lib/providers/node_provider.dart: return a boolean selection result and persist only confirmed choices.
- Modify clients/elephant-route-deprecated/lib/screens/home/node_selection_screen.dart: wait for selection, block duplicate taps, close only on success, and show failure feedback.
- Add or modify matching tests under clients/elephant-route-deprecated/test.

### Task 1: Add typed Clash selector and connection operations

**Files:**
- Modify: clients/elephant-route-deprecated/test/core/singbox/macos_clash_controller_test.dart
- Modify: clients/elephant-route-deprecated/lib/core/singbox/macos_clash_controller.dart

- [ ] **Step 1: Write the failing controller tests**

Add queued HTTP responses and cover:

~~~dart
expect(await controller.selectedOutbound('节点选择'), '东京');

expect(
  await controller.activeConnections(),
  [
    const MacosClashConnection(
      id: 'old-1',
      chains: ['东京', '节点选择'],
    ),
  ],
);

await controller.closeConnection('old/1');
expect(adapter.requests.single.method, 'DELETE');
expect(adapter.requests.single.path, '/connections/old%2F1');
~~~

The connection payload must also contain malformed rows to prove that empty IDs and non-string chains are ignored. Add cases for an absent connections field, an empty selector now, a 404 close treated as already closed, and a non-404 close error preserving its HTTP status.

- [ ] **Step 2: Run the controller test and verify RED**

Run from clients/elephant-route-deprecated:

~~~bash
flutter test --no-pub test/core/singbox/macos_clash_controller_test.dart
~~~

Expected: compilation fails because the typed connection and new methods do not exist.

- [ ] **Step 3: Implement the typed controller boundary**

Add:

~~~dart
class MacosClashConnection {
  const MacosClashConnection({required this.id, required this.chains});

  final String id;
  final List<String> chains;
}

abstract interface class MacosClashControl {
  Future<String> selectedOutbound(String groupTag);
  Future<List<MacosClashConnection>> activeConnections();
  Future<void> selectOutbound(String groupTag, String outboundTag);
  Future<void> closeConnection(String connectionId);
  Future<int> urlTest(
    String proxyTag, {
    String testUrl = 'https://www.gstatic.com/generate_204',
    int timeoutMs = 3000,
  });
}
~~~

MacosClashController implements the interface. selectedOutbound uses GET /proxies/{group} and requires a non-empty string now. activeConnections uses GET /connections, returns an empty list when the field is absent, and skips malformed rows. closeConnection uses DELETE /connections/{encoded id}; 404 is idempotent success and every other Dio failure is wrapped.

- [ ] **Step 4: Run the controller test and verify GREEN**

~~~bash
flutter test --no-pub test/core/singbox/macos_clash_controller_test.dart
~~~

Expected: all controller tests pass.

- [ ] **Step 5: Commit**

~~~bash
git add clients/elephant-route-deprecated/lib/core/singbox/macos_clash_controller.dart clients/elephant-route-deprecated/test/core/singbox/macos_clash_controller_test.dart
git commit -m "feat: add macOS clash connection controls"
~~~

### Task 2: Implement the outbound migration coordinator

**Files:**
- Create: clients/elephant-route-deprecated/lib/core/singbox/macos_outbound_switch_coordinator.dart
- Create: clients/elephant-route-deprecated/test/core/singbox/macos_outbound_switch_coordinator_test.dart

- [ ] **Step 1: Write a failing coordinator test with a fake control**

The primary case must assert this exact event order:

~~~dart
expect(control.events, [
  'read:节点选择',
  'connections',
  'select:节点选择:香港',
  'read:节点选择',
  'close:old',
]);
~~~

Use four connections: old Tokyo plus selector, direct, Tokyo plus another group, and new Hong Kong plus selector. Only the first may be closed. Add tests proving a same-node choice is a no-op, a read-back mismatch closes nothing, and a close error reports already-closed and remaining counts.

- [ ] **Step 2: Run the coordinator test and verify RED**

~~~bash
flutter test --no-pub test/core/singbox/macos_outbound_switch_coordinator_test.dart
~~~

Expected: compilation fails because the coordinator types do not exist.

- [ ] **Step 3: Implement focused result and exception types**

~~~dart
class MacosOutboundSwitchResult {
  const MacosOutboundSwitchResult({
    required this.previousOutbound,
    required this.targetOutbound,
    required this.closedConnectionCount,
  });

  final String previousOutbound;
  final String targetOutbound;
  final int closedConnectionCount;
}

class MacosOutboundSwitchException implements Exception {
  const MacosOutboundSwitchException(
    this.message, {
    this.cause,
    this.closedConnectionCount = 0,
    this.remainingConnectionCount = 0,
  });

  final String message;
  final Object? cause;
  final int closedConnectionCount;
  final int remainingConnectionCount;
}
~~~

The coordinator reads the old member, returns immediately for a no-op, snapshots IDs whose chains contain both the group and old member, switches, confirms the target with a second read, then closes the captured IDs sequentially. It never restarts the core and never logs connection metadata.

- [ ] **Step 4: Run coordinator and controller tests**

~~~bash
flutter test --no-pub test/core/singbox/macos_clash_controller_test.dart test/core/singbox/macos_outbound_switch_coordinator_test.dart
~~~

Expected: all tests pass.

- [ ] **Step 5: Commit**

~~~bash
git add clients/elephant-route-deprecated/lib/core/singbox/macos_outbound_switch_coordinator.dart clients/elephant-route-deprecated/test/core/singbox/macos_outbound_switch_coordinator_test.dart
git commit -m "feat: migrate macOS node connections"
~~~

### Task 3: Wire migration into MacosVpnService

**Files:**
- Modify: clients/elephant-route-deprecated/lib/core/singbox/macos_vpn_service.dart
- Modify: clients/elephant-route-deprecated/test/core/singbox/macos_vpn_lifecycle_contract_test.dart

- [ ] **Step 1: Strengthen the lifecycle contract test**

Require _outboundSwitchCoordinator.switchOutbound to run before _lastSanitizedConfig is updated. Require temporary-file persistence with flush: true. Retain assertions that selectOutbound never invokes _runtime.stopCore, _runtime.startTunMode, or VpnStatus.coreStarting.

- [ ] **Step 2: Run the lifecycle contract and verify RED**

~~~bash
flutter test --no-pub test/core/singbox/macos_vpn_lifecycle_contract_test.dart
~~~

Expected: coordinator and atomic-persistence expectations fail.

- [ ] **Step 3: Integrate the coordinator**

Initialize MacosOutboundSwitchCoordinator from the existing controller. Replace the direct selector PUT with:

~~~dart
final result = await _outboundSwitchCoordinator.switchOutbound(
  groupTag: groupTag,
  targetOutbound: outboundTag,
);
_lastSanitizedConfig = updatedConfig;
await _persistSelectedConfig(updatedConfig);
await AppLogger.instance.info(
  'macOS outbound switch completed: target=$outboundTag '
  'closedOldConnections=${result.closedConnectionCount}',
);
~~~

The persistence helper writes config.json.tmp with flush: true and renames it over config.json. A failure is logged without addresses or connection metadata and is rethrown.

- [ ] **Step 4: Run focused macOS tests**

~~~bash
flutter test --no-pub test/core/singbox/macos_clash_controller_test.dart test/core/singbox/macos_outbound_switch_coordinator_test.dart test/core/singbox/macos_vpn_lifecycle_contract_test.dart
~~~

Expected: all tests pass.

- [ ] **Step 5: Commit**

~~~bash
git add clients/elephant-route-deprecated/lib/core/singbox/macos_vpn_service.dart clients/elephant-route-deprecated/test/core/singbox/macos_vpn_lifecycle_contract_test.dart
git commit -m "fix: confirm macOS outbound migration"
~~~

### Task 4: Return a confirmed result from NodeProvider

**Files:**
- Modify: clients/elephant-route-deprecated/lib/providers/node_provider.dart
- Modify: clients/elephant-route-deprecated/test/providers/node_provider_latency_test.dart

- [ ] **Step 1: Write provider result tests**

Make the fake VPN manager optionally throw, then assert:

~~~dart
expect(await provider.selectNode(target), isTrue);

vpnManager.selectionError = StateError('switch rejected');
expect(await provider.selectNode(target), isFalse);
expect(provider.selectedNode, previous);
expect(provider.errorMessage, contains('switch rejected'));
~~~

- [ ] **Step 2: Run provider tests and verify RED**

~~~bash
flutter test --no-pub test/providers/node_provider_latency_test.dart
~~~

Expected: the current Future<void> result cannot satisfy boolean assertions.

- [ ] **Step 3: Implement Future<bool> selectNode**

Return false for failed or superseded selections and true only after VPN selection and both storage writes finish. Preserve generation checks, auto-mode rollback, serialization, and reconnect replay. A storage failure returns false and exposes an error rather than claiming success.

- [ ] **Step 4: Run provider tests and verify GREEN**

~~~bash
flutter test --no-pub test/providers/node_provider_latency_test.dart
~~~

Expected: all provider tests pass.

- [ ] **Step 5: Commit**

~~~bash
git add clients/elephant-route-deprecated/lib/providers/node_provider.dart clients/elephant-route-deprecated/test/providers/node_provider_latency_test.dart
git commit -m "fix: expose confirmed node selection"
~~~

### Task 5: Await the actual result in the node screen

**Files:**
- Modify: clients/elephant-route-deprecated/lib/screens/home/node_selection_screen.dart
- Modify: clients/elephant-route-deprecated/test/screens/home/node_selection_screen_test.dart

- [ ] **Step 1: Add asynchronous widget tests**

Push NodeSelectionScreen over a host route and use a selection completer. Prove the screen stays visible while the Future is pending, pops only after true, stays visible and shows 节点切换失败 after false, and ignores a second tap while pending.

- [ ] **Step 2: Run widget tests and verify RED**

~~~bash
flutter test --no-pub test/screens/home/node_selection_screen_test.dart
~~~

Expected: the current screen pops immediately and permits duplicate taps.

- [ ] **Step 3: Implement one awaited handler**

~~~dart
Future<void> _selectNode(NodeProvider provider, ProxyNode node) async {
  if (_switchingNodeName != null) return;
  setState(() => _switchingNodeName = node.name);
  final applied = await provider.selectNode(node);
  if (!mounted) return;
  if (applied) {
    if (Navigator.canPop(context)) Navigator.pop(context);
    return;
  }
  setState(() => _switchingNodeName = null);
  ToastUtils.show(context, provider.errorMessage ?? '节点切换失败，请重试');
}
~~~

Route auto and regular cards through this handler, disable all taps during a switch, and show a progress indicator only on the requested card.

- [ ] **Step 4: Run widget and provider tests**

~~~bash
flutter test --no-pub test/screens/home/node_selection_screen_test.dart test/providers/node_provider_latency_test.dart
~~~

Expected: all tests pass.

- [ ] **Step 5: Commit**

~~~bash
git add clients/elephant-route-deprecated/lib/screens/home/node_selection_screen.dart clients/elephant-route-deprecated/test/screens/home/node_selection_screen_test.dart
git commit -m "fix: await node migration in selector UI"
~~~

### Task 6: Full verification and macOS 1.6.5 build

- [ ] **Step 1: Format modified Dart files and check whitespace**

~~~bash
dart format lib/core/singbox/macos_clash_controller.dart lib/core/singbox/macos_outbound_switch_coordinator.dart lib/core/singbox/macos_vpn_service.dart lib/providers/node_provider.dart lib/screens/home/node_selection_screen.dart test/core/singbox/macos_clash_controller_test.dart test/core/singbox/macos_outbound_switch_coordinator_test.dart test/core/singbox/macos_vpn_lifecycle_contract_test.dart test/providers/node_provider_latency_test.dart test/screens/home/node_selection_screen_test.dart
git diff --check
~~~

Expected: both commands exit 0.

- [ ] **Step 2: Run the focused regression suite**

~~~bash
flutter test --no-pub test/core/singbox/macos_clash_controller_test.dart test/core/singbox/macos_outbound_switch_coordinator_test.dart test/core/singbox/macos_vpn_lifecycle_contract_test.dart test/providers/node_provider_latency_test.dart test/screens/home/node_selection_screen_test.dart
~~~

Expected: 0 failures.

- [ ] **Step 3: Run static analysis and the full suite**

~~~bash
flutter analyze
flutter test --no-pub
~~~

Expected: analyzer reports no issues and the full suite has 0 failures.

- [ ] **Step 4: Build the same-version macOS package**

~~~bash
MACOS_BUILD_NAME=1.6.5 MACOS_BUILD_NUMBER=10605 ./build_macos_beta.sh
~~~

Expected: exit 0 and build/macos-beta/ElephantRoute-macos-arm64-v1.6.5.dmg exists.

- [ ] **Step 5: Verify the artifact and repository state**

Mount the DMG read-only. Verify CFBundleShortVersionString 1.6.5, CFBundleVersion 10605, bundled core version, arm64-only Mach-O architecture, deep app signature, and SHA-256. Then run git status --short and git log -6 --oneline.

## Acceptance Checklist

- [ ] Selector read-back equals the requested node before success is reported.
- [ ] Only connections containing both the old member and selector group are closed.
- [ ] No sing-box stop/start or TUN lifecycle call occurs during selection.
- [ ] UI stays open during migration and on failure.
- [ ] Duplicate taps are suppressed.
- [ ] Node persistence occurs only after confirmed migration.
- [ ] Version remains 1.6.5+10605.
- [ ] Focused tests, full tests, analysis, and macOS build all pass with fresh evidence.
