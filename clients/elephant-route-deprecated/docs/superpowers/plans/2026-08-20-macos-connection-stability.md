# macOS Connection Stability Repair Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Keep the macOS TUN alive during node selection, prevent noisy latency results from causing stale automatic switches, and harden the default remote DNS transport.

**Architecture:** Add a testable macOS Clash controller for hot selector changes, delegate `MacosVpnService` outbound changes to it without touching the runtime, and make `NodeProvider` serialize and confirm automatic changes. Apply a pure macOS DNS policy during config sanitization so unit tests can verify the exact transformation.

**Tech Stack:** Flutter/Dart 3.5, Dio 5, sing-box Clash API, Flutter test, macOS Flutter release build

---

### Task 1: Add the macOS Clash controller

**Files:**
- Create: `lib/core/singbox/macos_clash_controller.dart`
- Create: `test/core/singbox/macos_clash_controller_test.dart`
- Modify: `lib/core/singbox/macos_vpn_service.dart`

- [ ] **Step 1: Write failing controller tests**

Use an injected `HttpClientAdapter` to assert the exact request and typed
failure behavior:

```dart
test('hot-selects an outbound through the Clash API', () async {
  final adapter = _RecordingAdapter(statusCode: 204);
  final dio = Dio(BaseOptions(baseUrl: 'http://127.0.0.1:9090'))
    ..httpClientAdapter = adapter;
  final controller = MacosClashController(dio);

  await controller.selectOutbound('节点选择', '东京 01');

  expect(adapter.lastOptions?.method, 'PUT');
  expect(adapter.lastOptions?.path,
      '/proxies/%E8%8A%82%E7%82%B9%E9%80%89%E6%8B%A9');
  expect(adapter.lastOptions?.data, {'name': '东京 01'});
});

test('wraps a rejected selector response', () async {
  final controller = MacosClashController(_dioReturning(503));
  await expectLater(
    controller.selectOutbound('节点选择', 'Tokyo'),
    throwsA(isA<MacosClashControllerException>()),
  );
});
```

- [ ] **Step 2: Run the new test and confirm it fails**

Run: `flutter test --no-pub test/core/singbox/macos_clash_controller_test.dart`

Expected: compilation failure because `MacosClashController` does not exist.

- [ ] **Step 3: Implement the controller**

```dart
class MacosClashControllerException implements Exception {
  const MacosClashControllerException(this.message, {this.cause});
  final String message;
  final Object? cause;
}

class MacosClashController {
  MacosClashController(this._dio);
  final Dio _dio;

  Future<void> selectOutbound(String groupTag, String outboundTag) async {
    try {
      await _dio.put<void>(
        '/proxies/${Uri.encodeComponent(groupTag)}',
        data: {'name': outboundTag},
      );
    } on DioException catch (error) {
      throw MacosClashControllerException(
        'Clash API rejected outbound selection',
        cause: error,
      );
    }
  }
}
```

Move the existing macOS delay request behind the same controller so selector
and URL-test behavior share one HTTP boundary.

- [ ] **Step 4: Run the controller tests**

Run: `flutter test --no-pub test/core/singbox/macos_clash_controller_test.dart`

Expected: all controller tests pass.

- [ ] **Step 5: Commit the controller boundary**

```bash
git add clients/elephant-route-deprecated/lib/core/singbox/macos_clash_controller.dart clients/elephant-route-deprecated/test/core/singbox/macos_clash_controller_test.dart
git commit -m "feat: add macOS Clash controller"
```

### Task 2: Replace macOS TUN restart with hot selection

**Files:**
- Modify: `lib/core/singbox/macos_vpn_service.dart:487-590`
- Modify: `test/core/singbox/macos_vpn_lifecycle_contract_test.dart`

- [ ] **Step 1: Write the failing lifecycle contract**

Extract the `selectOutbound` body from source and assert:

```dart
expect(selectBody, contains('_clashController.selectOutbound'));
expect(selectBody, isNot(contains('_runtime.stopCore')));
expect(selectBody, isNot(contains('_runtime.startTunMode')));
expect(selectBody, isNot(contains('VpnStatus.coreStarting')));
```

- [ ] **Step 2: Run the lifecycle contract and confirm it fails**

Run: `flutter test --no-pub test/core/singbox/macos_vpn_lifecycle_contract_test.dart`

Expected: failure because `selectOutbound` still calls `_runtime.stopCore`.

- [ ] **Step 3: Implement hot switching**

Validate the selector and target, call the controller, and only then persist
the updated selector default:

```dart
final updatedConfig = _configWithSelectedOutbound(
  _lastSanitizedConfig!,
  groupTag,
  outboundTag,
);
await _clashController.selectOutbound(groupTag, outboundTag);
_lastSanitizedConfig = updatedConfig;
await File('$_singboxDirPath/config.json').writeAsString(updatedConfig);
await AppLogger.instance.info(
  'macOS outbound hot switch completed: $outboundTag',
);
```

On controller failure, log and rethrow without updating VPN state or invoking
the runtime. A persistence failure after a live switch must also avoid a TUN
restart.

- [ ] **Step 4: Run controller and lifecycle tests**

Run: `flutter test --no-pub test/core/singbox/macos_clash_controller_test.dart test/core/singbox/macos_vpn_lifecycle_contract_test.dart`

Expected: all tests pass and the contract contains no node-switch stop path.

- [ ] **Step 5: Commit hot switching**

```bash
git add clients/elephant-route-deprecated/lib/core/singbox/macos_vpn_service.dart clients/elephant-route-deprecated/test/core/singbox/macos_vpn_lifecycle_contract_test.dart
git commit -m "fix: hot-switch macOS proxy nodes"
```

### Task 3: Guard and serialize automatic selection

**Files:**
- Modify: `lib/providers/node_provider.dart:27-185,199-313,580-740`
- Modify: `lib/screens/home/node_selection_screen.dart:256-262,409-423`
- Modify: `test/providers/node_provider_latency_test.dart`

- [ ] **Step 1: Write failing provider tests**

Extend `_LatencyVpnManager` with controllable URL-test results and delayed
selection futures. Cover:

```dart
test('retains current auto node when focused confirmation succeeds', () async {
  vpnManager.urlTestResults['node-current'] = 45;
  await provider.testAllLatencies();
  expect(provider.autoSelectedRealNode?.name, 'node-current');
  expect(vpnManager.selectedNodes, isEmpty);
});

test('switches after bulk and focused checks both fail', () async {
  vpnManager.urlTestResults['node-current'] = -1;
  await provider.testAllLatencies();
  expect(provider.autoSelectedRealNode?.name, 'node-good');
  expect(vpnManager.selectedNodes, ['node-good']);
});
```

Add a delayed-manager test proving an older selection completion cannot
overwrite a newer explicit choice.

- [ ] **Step 2: Run the provider tests and confirm they fail**

Run: `flutter test --no-pub test/providers/node_provider_latency_test.dart`

Expected: failures because automatic confirmation and serialized selection do
not exist.

- [ ] **Step 3: Implement selection generations**

Make `selectNode` and `_evaluateAutoSelect` return `Future<void>`. Increment a
selection generation for every explicit user choice, apply a result only while
its generation remains current, and observe every future with `await` or
`unawaited`. Catch manager errors, retain the previous effective outbound,
populate `_errorMessage`, and notify listeners.

- [ ] **Step 4: Implement focused confirmation**

Before evicting an established automatic node with a failed bulk result:

```dart
final confirmedLatency = await _vpnManager.urlTest(currentNode.name);
if (generation != _selectionGeneration ||
    _vpnManager.currentState.status != VpnStatus.connected) {
  return;
}
if (confirmedLatency > 0) {
  _applyConfirmedLatency(currentNode.name, confirmedLatency);
  return;
}
```

Only then hot-switch to the best positive candidate. Initial and user-forced
automatic selection do not require confirmation.

- [ ] **Step 5: Observe UI selection futures**

Use `unawaited(provider.selectNode(node))` in both node-card callbacks before
closing the screen. Provider state owns any error.

- [ ] **Step 6: Run provider and screen tests**

Run: `flutter test --no-pub test/providers/node_provider_latency_test.dart test/screens/home/node_selection_screen_test.dart`

Expected: all tests pass with no delayed timer or stale-selection failures.

- [ ] **Step 7: Commit automatic-selection guards**

```bash
git add clients/elephant-route-deprecated/lib/providers/node_provider.dart clients/elephant-route-deprecated/lib/screens/home/node_selection_screen.dart clients/elephant-route-deprecated/test/providers/node_provider_latency_test.dart clients/elephant-route-deprecated/test/screens/home/node_selection_screen_test.dart
git commit -m "fix: guard automatic node selection"
```

### Task 4: Harden the macOS default remote DNS

**Files:**
- Create: `lib/core/singbox/macos_dns_policy.dart`
- Create: `test/core/singbox/macos_dns_policy_test.dart`
- Modify: `lib/core/singbox/macos_vpn_service.dart:796-891`

- [ ] **Step 1: Write failing pure policy tests**

```dart
test('upgrades the unchanged remote default to proxied DoH', () {
  final config = MacosDnsPolicy.apply(_config(remote: '8.8.8.8'));
  final remote = _remoteServer(config);
  expect(remote['address'], 'https://1.1.1.1/dns-query');
  expect(remote['detour'], '节点选择');
  expect(remote['strategy'], 'ipv4_only');
});

test('preserves custom remote and domestic DNS', () {
  final config = MacosDnsPolicy.apply(_config(remote: '9.9.9.9'));
  expect(_server(config, 'remote')['address'], '9.9.9.9');
  expect(_server(config, 'local')['address'], '223.5.5.5');
});
```

- [ ] **Step 2: Run the DNS tests and confirm they fail**

Run: `flutter test --no-pub test/core/singbox/macos_dns_policy_test.dart`

Expected: compilation failure because `MacosDnsPolicy` does not exist.

- [ ] **Step 3: Implement and apply the policy**

Add a pure config transformer that only replaces a remote server whose address
is exactly `8.8.8.8`. Preserve its detour when present, otherwise set
`节点选择`, and set `strategy=ipv4_only`. Call it from `_sanitizeConfig`.

- [ ] **Step 4: Validate the produced runtime config**

In `macos_dns_policy_test.dart`, create a temporary directory with
`Directory.systemTemp.createTemp`, write the transformed config, and run:

```dart
final result = await Process.run(
  'assets/bin/sing-box-darwin-arm64',
  ['check', '-c', configFile.path],
);
expect(result.exitCode, 0, reason: '${result.stdout}\n${result.stderr}');
```

Run: `flutter test --no-pub test/core/singbox/macos_dns_policy_test.dart`

Expected: pure policy assertions pass and the bundled arm64 sing-box config
check exits 0.

- [ ] **Step 5: Commit DNS hardening**

```bash
git add clients/elephant-route-deprecated/lib/core/singbox/macos_dns_policy.dart clients/elephant-route-deprecated/lib/core/singbox/macos_vpn_service.dart clients/elephant-route-deprecated/test/core/singbox/macos_dns_policy_test.dart
git commit -m "fix: harden macOS remote DNS transport"
```

### Task 5: Full verification and runtime acceptance

**Files:**
- Verify all changed files and update docs only if observed behavior differs
  from the approved design.

- [ ] **Step 1: Format and check whitespace**

Run `dart format` on all changed Dart files, followed by `git diff --check`.

Expected: both commands exit 0.

- [ ] **Step 2: Run focused tests**

Run: `flutter test --no-pub test/core/singbox/macos_clash_controller_test.dart test/core/singbox/macos_dns_policy_test.dart test/core/singbox/macos_vpn_lifecycle_contract_test.dart test/providers/node_provider_latency_test.dart test/screens/home/node_selection_screen_test.dart`

Expected: all focused tests pass.

- [ ] **Step 3: Run static analysis and complete test suites**

```bash
flutter analyze
flutter test --no-pub
node --test tests/*.test.js
```

Expected: every command exits 0; any unrelated baseline failure is reported
separately with its exact test name and output.

- [ ] **Step 4: Build the release application**

Run: `flutter build macos --release`

Expected: `build/macos/Build/Products/Release/ElephantRoute.app` is produced.

- [ ] **Step 5: Perform non-destructive local runtime acceptance**

Launch the release build, connect once, select a different node, and compare
timestamps in `dart.log` and `/Library/Logs/ElephantRoute/tun-helper.log`.
Acceptance requires a logged hot-switch completion, no `stopTun requested`
between selection and completion, a stable TUN interface, and no second
automatic latency run caused by the switch.

- [ ] **Step 6: Review repository state**

```bash
git status --short --branch
git log --oneline --decorate -8
git diff HEAD~4..HEAD --check
```

Expected: only intentional commits are present and no unrelated files are
included.
