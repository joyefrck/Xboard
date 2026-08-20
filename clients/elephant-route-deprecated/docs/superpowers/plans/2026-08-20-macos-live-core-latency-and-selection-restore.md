# macOS Live-Core Latency and Selection Restore Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make macOS node delays stream promptly from the connected sing-box core and restore a saved concrete node after reconnect without restarting TUN.

**Architecture:** Replace the connected macOS production latency path with the existing typed Clash delay runner, starting it with an empty primary-result map so every node is measured on `127.0.0.1:9090`. Add a generation-aware connected transition in `NodeProvider` that hot-selects the saved manual node before scheduling automatic latency. Keep the isolated latency implementation available but remove it from the connected production path.

**Tech Stack:** Flutter/Dart, Dio 5, sing-box 1.12.25 Clash API, Flutter test, macOS arm64 release packaging

---

### Task 1: Make the live Clash core the macOS latency source

**Files:**
- Modify: `lib/core/singbox/macos_vpn_service.dart:27-435`
- Modify: `lib/core/singbox/macos_latency_fallback.dart:96-129`
- Modify: `test/core/singbox/macos_vpn_service_latency_test.dart`
- Modify: `test/core/singbox/macos_latency_fallback_test.dart`
- Modify: `test/core/singbox/macos_vpn_lifecycle_contract_test.dart`

- [ ] **Step 1: Write the failing service tests**

Append tests that inject a typed Clash runner into an otherwise idle
`MacosVpnService`. The old isolated implementation fails these tests before it
reaches the injected runner because no active temporary-core source exists.

```dart
test('streams live Clash results without an isolated config', () async {
  final callbacks = <String, ConnectionLatencyResult>{};
  final runner = MacosLatencyFallbackRunner(
    concurrency: 4,
    retryDelay: (_) async {},
    probe: (nodeTag, testUrl, timeoutMs) async => ConnectionLatencyResult(
      latencyMs: nodeTag == 'node-a' ? 120 : 180,
      elapsedMs: 10,
      source: ConnectionLatencySource.clashFallback,
    ),
  );
  final service = MacosVpnService(latencyFallbackRunner: runner);

  final results = await service.testConnectionLatencies(
    nodeTags: const ['node-a', 'node-b'],
    testUrl: 'https://www.gstatic.com/generate_204',
    timeoutMs: 5000,
    concurrency: 4,
    onResult: (nodeTag, result) => callbacks[nodeTag] = result,
  );

  expect(results.keys, containsAll(['node-a', 'node-b']));
  expect(callbacks.keys, containsAll(['node-a', 'node-b']));
  expect(callbacks['node-a']?.latencyMs, 120);
  expect(callbacks['node-b']?.latencyMs, 180);
  service.dispose();
});

test('publishes a typed live Clash failure immediately', () async {
  final callbacks = <ConnectionLatencyResult>[];
  final runner = MacosLatencyFallbackRunner(
    concurrency: 4,
    retryDelay: (_) async {},
    probe: (nodeTag, testUrl, timeoutMs) async =>
        const ConnectionLatencyResult(
      latencyMs: -1,
      elapsedMs: 5000,
      failureKind: ConnectionLatencyFailureKind.timeout,
      source: ConnectionLatencySource.clashFallback,
    ),
  );
  final service = MacosVpnService(latencyFallbackRunner: runner);

  await service.testConnectionLatencies(
    nodeTags: const ['node-timeout'],
    testUrl: 'https://www.gstatic.com/generate_204',
    timeoutMs: 5000,
    concurrency: 4,
    onResult: (nodeTag, result) => callbacks.add(result),
  );

  expect(callbacks, hasLength(1));
  expect(callbacks.single.failureKind, ConnectionLatencyFailureKind.timeout);
  service.dispose();
});
```

- [ ] **Step 2: Add the failing source contract**

Extend `macos_vpn_lifecycle_contract_test.dart` so the connected latency body
must use the live runner and must not instantiate `MacosLatencySession`.

```dart
test('macOS connected latency stays on the live core', () {
  final source =
      File('lib/core/singbox/macos_vpn_service.dart').readAsStringSync();
  final start = source.indexOf(
    'Future<Map<String, ConnectionLatencyResult>> _runConnectionLatencies',
  );
  final end = source.indexOf(
    '\n  @override\n  Future<void> stopConnectionLatencyTest()',
    start,
  );
  final body = source.substring(start, end);

  expect(body, contains('_latencyFallbackRunner.resolve'));
  expect(body, contains('primaryResults: const {}'));
  expect(body, isNot(contains('MacosLatencySession(')));
  expect(body, isNot(contains('_runtime.stopCore')));
  expect(body, isNot(contains('_runtime.startTunMode')));
});
```

- [ ] **Step 3: Run the focused tests and verify the red state**

Run:

```bash
flutter test --no-pub \
  test/core/singbox/macos_vpn_service_latency_test.dart \
  test/core/singbox/macos_vpn_lifecycle_contract_test.dart
```

Expected: the new service tests fail with `MacosLatencyException` because the
old path requires `_lastSanitizedConfig`, and the source contract fails because
the old body still constructs `MacosLatencySession`.

- [ ] **Step 4: Change the runner default to four workers**

Update `MacosLatencyFallbackRunner`:

```dart
MacosLatencyFallbackRunner({
  required MacosClashLatencyProbe probe,
  MacosLatencyRetryDelay? retryDelay,
  MacosLatencyFallbackLogger? logger,
  this.concurrency = 4,
})  : _probe = probe,
      _retryDelay = retryDelay ?? Future<void>.delayed,
      _logger = logger {
  if (concurrency <= 0) {
    throw ArgumentError.value(concurrency, 'concurrency', 'must be positive');
  }
}
```

Update the concurrency test to release all four workers and assert four are in
flight:

```dart
test('limits live Clash probes to four concurrent nodes', () async {
  var inFlight = 0;
  var maxInFlight = 0;
  final release = Completer<void>();
  final runner = MacosLatencyFallbackRunner(
    probe: (nodeTag, testUrl, timeoutMs) async {
      inFlight++;
      if (inFlight > maxInFlight) maxInFlight = inFlight;
      if (inFlight == 4 && !release.isCompleted) release.complete();
      await release.future;
      inFlight--;
      return const ConnectionLatencyResult(
        latencyMs: 200,
        elapsedMs: 220,
        source: ConnectionLatencySource.clashFallback,
      );
    },
  );

  await runner.resolve(
    nodeTags: const ['a', 'b', 'c', 'd'],
    primaryResults: const {},
    testUrl: 'https://www.gstatic.com/generate_204',
    timeoutMs: 5000,
    isCancelled: () => false,
  );

  expect(maxInFlight, 4);
});
```

- [ ] **Step 5: Replace the isolated production path with the live runner**

Reduce `_runConnectionLatencies` to one generation-aware live-core operation:

```dart
Future<Map<String, ConnectionLatencyResult>> _runConnectionLatencies({
  required List<String> nodeTags,
  required String testUrl,
  required int timeoutMs,
  required int concurrency,
  ConnectionLatencyResultCallback? onResult,
}) async {
  await stopConnectionLatencyTest();
  final generation = _latencyRunGeneration;
  bool isCancelled() => _disposed || generation != _latencyRunGeneration;

  await AppLogger.instance.info(
    'macOS live-core latency nodes=${nodeTags.length} '
    'concurrency=${_latencyFallbackRunner.concurrency}',
  );
  return _latencyFallbackRunner.resolve(
    nodeTags: nodeTags,
    primaryResults: const {},
    testUrl: testUrl,
    timeoutMs: timeoutMs,
    isCancelled: isCancelled,
    onResult: (nodeTag, result) {
      if (!isCancelled()) {
        onResult?.call(nodeTag, result);
      }
    },
  );
}
```

Remove the now-unused service imports, constructor inputs, fields, assignments,
and session cleanup for:

```dart
SubscriptionConfigCache
MacosLatencySession
MacosLatencySourceSelector
latencyCacheReadTimeout
_subscriptionConfigCache
_latencyCacheReadTimeout
_singboxBinPath
_latencySession
```

Keep the files `macos_latency_session.dart`, `macos_latency_config.dart`, and
their isolated unit tests unchanged.

Change `stopConnectionLatencyTest` to generation-only cancellation:

```dart
@override
Future<void> stopConnectionLatencyTest() async {
  _latencyRunGeneration++;
}
```

Update the deadline constructor test so it checks only
`latencyRunTimeout: Duration.zero`.

- [ ] **Step 6: Run the live-core latency tests**

Run:

```bash
dart format \
  lib/core/singbox/macos_vpn_service.dart \
  lib/core/singbox/macos_latency_fallback.dart \
  test/core/singbox/macos_vpn_service_latency_test.dart \
  test/core/singbox/macos_latency_fallback_test.dart \
  test/core/singbox/macos_vpn_lifecycle_contract_test.dart
flutter test --no-pub \
  test/core/singbox/macos_vpn_service_latency_test.dart \
  test/core/singbox/macos_latency_fallback_test.dart \
  test/core/singbox/macos_vpn_lifecycle_contract_test.dart
```

Expected: all focused tests pass; a service without an isolated config streams
both success and failure results from the injected Clash runner.

- [ ] **Step 7: Commit the live-core latency repair**

```bash
git add \
  lib/core/singbox/macos_vpn_service.dart \
  lib/core/singbox/macos_latency_fallback.dart \
  test/core/singbox/macos_vpn_service_latency_test.dart \
  test/core/singbox/macos_latency_fallback_test.dart \
  test/core/singbox/macos_vpn_lifecycle_contract_test.dart
git commit -m "fix: stream macOS latency from live core"
```

### Task 2: Restore a saved concrete node after macOS connects

**Files:**
- Modify: `lib/providers/node_provider.dart:55-100,649-919`
- Modify: `test/providers/node_provider_latency_test.dart`

- [ ] **Step 1: Extend the fake manager with ordered events**

Add an event list and record the boundaries used by the new assertions:

```dart
final List<String> events = [];

@override
Future<Map<String, ConnectionLatencyResult>> testConnectionLatencies({
  required List<String> nodeTags,
  required String testUrl,
  required int timeoutMs,
  int concurrency = 4,
  ConnectionLatencyResultCallback? onResult,
}) async {
  events.add('latency');
  latencyTestCalls++;
  if (hangLatencyTest) {
    if (!latencyTestStarted.isCompleted) {
      latencyTestStarted.complete();
    }
    _hangingResultCallback = onResult;
    _hangingLatencyTest = Completer<Map<String, ConnectionLatencyResult>>();
    return _hangingLatencyTest!.future;
  }
  for (final nodeTag in nodeTags) {
    onResult?.call(nodeTag, latencyResults[nodeTag]!);
  }
  return latencyResults;
}

@override
Future<void> selectOutbound(String groupTag, String outboundTag) async {
  events.add('select:$outboundTag');
  selectionAttempts.add(outboundTag);
  if (delaySelections) {
    final completer = Completer<void>();
    _selectionCompleters[outboundTag] = completer;
    await completer.future;
  }
  selectedNodes.add(outboundTag);
}
```

- [ ] **Step 2: Write the failing restoration tests**

```dart
test('replays a saved concrete node before connected latency starts',
    () async {
  final manualNode = provider.nodes.singleWhere(
    (node) => node.name == 'node-good',
  );
  await provider.selectNode(manualNode);
  vpnManager.events.clear();
  vpnManager.selectedNodes.clear();

  vpnManager.emit(VpnStatus.disconnected);
  vpnManager.emit(VpnStatus.connected);
  await Future<void>.delayed(const Duration(milliseconds: 50));

  expect(
    vpnManager.events,
    containsAllInOrder(['select:node-good', 'latency']),
  );
  expect(vpnManager.selectedNodes.first, 'node-good');
});

test('auto mode starts connected latency without replaying a node', () async {
  vpnManager.events.clear();
  vpnManager.selectedNodes.clear();
  vpnManager.hangLatencyTest = true;

  vpnManager.emit(VpnStatus.disconnected);
  vpnManager.emit(VpnStatus.connected);
  await vpnManager.latencyTestStarted.future;

  expect(vpnManager.events.first, 'latency');
  expect(vpnManager.selectedNodes, isEmpty);
});

test('a newer explicit choice supersedes a delayed reconnect restore',
    () async {
  final oldNode = provider.nodes.singleWhere(
    (node) => node.name == 'node-timeout',
  );
  final newNode = provider.nodes.singleWhere(
    (node) => node.name == 'node-good',
  );
  await provider.selectNode(oldNode);
  vpnManager.selectionAttempts.clear();
  vpnManager.selectedNodes.clear();
  vpnManager.delaySelections = true;

  vpnManager.emit(VpnStatus.disconnected);
  vpnManager.emit(VpnStatus.connected);
  await _waitFor(() => vpnManager.selectionAttempts == ['node-timeout']);

  final newest = provider.selectNode(newNode);
  vpnManager.completeSelection('node-timeout');
  await _waitFor(() => vpnManager.selectionAttempts.length == 2);
  vpnManager.completeSelection('node-good');
  await newest;

  expect(vpnManager.selectionAttempts, ['node-timeout', 'node-good']);
  expect(provider.selectedNode?.name, 'node-good');
  expect(vpnManager.selectedNodes.last, 'node-good');
});
```

- [ ] **Step 3: Run the provider test and verify the red state**

Run:

```bash
flutter test --no-pub test/providers/node_provider_latency_test.dart
```

Expected: the concrete-node test starts with `latency` instead of restoration,
and the stale-restoration assertion cannot observe a reconnect selection.

- [ ] **Step 4: Add a generation-aware connected transition**

Replace the connected timer block in the state listener with:

```dart
if (_lastVpnStatus != VpnStatus.connected &&
    state.status == VpnStatus.connected) {
  unawaited(_handleConnectedTransition());
}
```

Invalidate selection work during the connected-to-disconnected transition:

```dart
if (_lastVpnStatus == VpnStatus.connected &&
    state.status != VpnStatus.connected) {
  _selectionGeneration++;
  _autoEvaluationGeneration++;
  _cancelActiveLatencyTest();
  _hasAuthoritativeConnectionLatencies = false;
  _ignoreNativeLatencyUpdatesUntil = null;
  _latencyResults.clear();
}
```

Add the connected handler and timer helper:

```dart
Future<void> _handleConnectedTransition() async {
  final generation = _selectionGeneration;
  final selected = _selectedNode;
  if (!_isAutoMode && selected != null && selected.type != 'auto') {
    await _enqueueOutboundSelection(selected.name, generation);
  }
  if (!_isSelectionCurrent(generation) ||
      _vpnManager.currentState.status != VpnStatus.connected) {
    return;
  }
  _scheduleConnectedLatencyTest(generation);
}

void _scheduleConnectedLatencyTest(int generation) {
  _connectionLatencyTimer?.cancel();
  _connectionLatencyTimer = Timer(_connectionLatencyDelay, () {
    _connectionLatencyTimer = null;
    if (_isSelectionCurrent(generation) &&
        _vpnManager.currentState.status == VpnStatus.connected) {
      unawaited(testAllLatencies());
    }
  });
}
```

The handler schedules latency after either restoration success or failure. The
existing `_enqueueOutboundSelection` reports failure through `_errorMessage`
and rejects stale generations.

- [ ] **Step 5: Run the provider and screen tests**

Run:

```bash
dart format lib/providers/node_provider.dart \
  test/providers/node_provider_latency_test.dart
flutter test --no-pub \
  test/providers/node_provider_latency_test.dart \
  test/screens/home/node_selection_screen_test.dart
```

Expected: all provider and node-screen tests pass, including restoration order,
auto-mode bypass, stale-generation suppression, existing focused confirmation,
and explicit selection serialization.

- [ ] **Step 6: Commit connected selection restoration**

```bash
git add \
  lib/providers/node_provider.dart \
  test/providers/node_provider_latency_test.dart
git commit -m "fix: restore macOS node after reconnect"
```

### Task 3: Run complete verification and build the replacement 1.6.5 DMG

**Files:**
- Verify: `lib/core/singbox/macos_vpn_service.dart`
- Verify: `lib/providers/node_provider.dart`
- Output: `build/macos-beta/ElephantRoute-macos-arm64-v1.6.5.dmg`

- [ ] **Step 1: Run formatting and diff checks**

```bash
dart format \
  lib/core/singbox/macos_vpn_service.dart \
  lib/core/singbox/macos_latency_fallback.dart \
  lib/providers/node_provider.dart \
  test/core/singbox/macos_vpn_service_latency_test.dart \
  test/core/singbox/macos_latency_fallback_test.dart \
  test/core/singbox/macos_vpn_lifecycle_contract_test.dart \
  test/providers/node_provider_latency_test.dart
git diff --check
git status --short
```

Expected: formatting makes no further changes, `git diff --check` is silent,
and status contains no uncommitted source changes.

- [ ] **Step 2: Run static analysis and the full Flutter suite**

```bash
flutter analyze
flutter test --no-pub
```

Expected: analysis reports `No issues found`; the Flutter suite reports zero
failures, with any platform-only skip counted separately.

- [ ] **Step 3: Run repository Node contracts from the repository root**

```bash
cd ../..
node --test tests/*.test.js
```

Expected: all relevant contracts pass. If the existing
`tests/macos-package-slimming.test.js` assertion about
`APP_DISTRIBUTION_URL` remains the only failure, report it as unchanged and do
not expand this repair into packaging-domain behavior.

- [ ] **Step 4: Build the same-version arm64 replacement package**

Because the installed app may be connected, do not launch the packaged binary
for smoke testing without explicit permission. Execute the release script with
the launch-smoke call replaced only in the process stream:

```bash
MACOS_BUILD_NAME=1.6.5 \
MACOS_BUILD_NUMBER=10605 \
BASE_URL=https://www.elephant223.com \
ALLOW_INSECURE_CERTS=false \
/bin/bash <(sed \
  -e 's|^smoke_test_app "${APP_BUNDLE}"$|echo "==> Skipping launch smoke while installed VPN is active"|' \
  build_macos_beta.sh)
```

Expected: Flutter release build, arm64 pruning, ad-hoc signing, and DMG creation
all exit zero and output:

```text
build/macos-beta/ElephantRoute-macos-arm64-v1.6.5.dmg
```

- [ ] **Step 5: Verify the mounted DMG**

Mount the DMG read-only at a fresh temporary directory, then verify:

```bash
release_dmg="build/macos-beta/ElephantRoute-macos-arm64-v1.6.5.dmg"
verify_mount="$(mktemp -d /tmp/elephant-1.6.5-verify.XXXXXX)"
hdiutil verify "${release_dmg}"
hdiutil attach -nobrowse -readonly -mountpoint "${verify_mount}" \
  "${release_dmg}"
mounted_app="${verify_mount}/大象网络.app"
/usr/libexec/PlistBuddy -c 'Print :CFBundleShortVersionString' \
  "${mounted_app}/Contents/Info.plist"
/usr/libexec/PlistBuddy -c 'Print :CFBundleVersion' \
  "${mounted_app}/Contents/Info.plist"
find "${mounted_app}/Contents" -type f -exec /usr/bin/file {} \; \
  | grep 'Mach-O'
codesign --verify --deep --strict --verbose=4 "${mounted_app}"
codesign -d --verbose=4 \
  "${mounted_app}/Contents/MacOS/ElephantTunHelper" 2>&1 \
  | grep '^Identifier='
shasum -a 256 "${release_dmg}"
hdiutil detach "${verify_mount}"
rmdir "${verify_mount}"
```

Expected:

```text
CFBundleShortVersionString=1.6.5
CFBundleVersion=10605
every Mach-O line contains arm64 and none contains x86_64
app signature is valid on disk
Identifier=com.elphantroute.elephantNetwork.tunhelper
```

- [ ] **Step 6: Confirm repository and artifact state**

```bash
git status --short
git log --oneline -5
ls -lh build/macos-beta/ElephantRoute-macos-arm64-v1.6.5.dmg
shasum -a 256 build/macos-beta/ElephantRoute-macos-arm64-v1.6.5.dmg
```

Expected: the worktree is clean, the two repair commits are present, and the
replacement DMG has a stable SHA-256 suitable for update metadata.

- [ ] **Step 7: Hand off live acceptance without silently installing**

Report that same-version update detection will not offer `1.6.5+10605` over an
installed `1.6.5+10605`. The user must quit the installed app, mount the DMG,
drag `大象网络.app` to `Applications`, and choose replace. After the user confirms
installation, verify without changing the connection:

```bash
/usr/libexec/PlistBuddy -c 'Print :CFBundleShortVersionString' \
  '/Applications/大象网络.app/Contents/Info.plist'
curl --noproxy '*' --max-time 2 -sS \
  http://127.0.0.1:9090/proxies/%E8%8A%82%E7%82%B9%E9%80%89%E6%8B%A9
tail -n 300 \
  "$HOME/Library/Application Support/com.elphantroute.elephantNetwork/logs/dart.log" \
  | rg 'live-core latency|outbound hot switch|latency fallback'
```

Success requires UI/live selector agreement, latency callbacks beginning
promptly, `测速中` clearing, and no `stopCore`/TUN restart event during selection.
