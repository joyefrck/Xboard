# macOS Deterministic Disconnect Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make one macOS power-switch click visibly and reliably stop a long-running TUN session without an accidental immediate restart.

**Architecture:** Publish disconnect intent before auxiliary cleanup, bound that cleanup, and make the privileged helper verify process termination with a SIGKILL fallback. Add a short provider-level settling guard so repeated clicks from the same interaction cannot reconnect immediately after a successful user stop.

**Tech Stack:** Flutter/Dart, macOS Swift, NSXPC privileged helper, Flutter test, shell-based release verification.

---

### Task 1: Guard the completed user disconnect from immediate retoggle

**Files:**
- Modify: `lib/providers/vpn_provider.dart`
- Test: `test/providers/vpn_provider_test.dart`

- [ ] **Step 1: Write the failing provider test**

Add a controllable clock and assert that a second toggle during the settling
interval does not call `start`, while a later toggle does:

```dart
var now = DateTime(2026, 8, 21, 12);
vpnProvider = VpnProvider(
  dioClient,
  vpnManager,
  ConfigProvider(),
  now: () => now,
  disconnectSettleDuration: const Duration(seconds: 2),
);
await vpnProvider.connect();
await vpnProvider.toggle();
await vpnProvider.toggle();
expect(vpnManager.startCalls, 1);
now = now.add(const Duration(seconds: 3));
await vpnProvider.toggle();
expect(vpnManager.startCalls, 2);
```

- [ ] **Step 2: Run the focused test and verify it fails**

Run: `flutter test --no-pub test/providers/vpn_provider_test.dart`

Expected: compilation fails because `now` and `disconnectSettleDuration` are
not constructor parameters yet.

- [ ] **Step 3: Implement the settling guard**

Add injectable time and duration fields, record only successful
`VpnStopReason.userToggle` completion, and reject reconnect toggles inside the
interval:

```dart
typedef VpnProviderClock = DateTime Function();

final VpnProviderClock _now;
final Duration _disconnectSettleDuration;
DateTime? _lastUserDisconnectAt;

if (!_state.isConnected && _lastUserDisconnectAt != null &&
    _now().difference(_lastUserDisconnectAt!) < _disconnectSettleDuration) {
  return;
}
if (_state.isConnected) {
  await disconnect(reason: VpnStopReason.userToggle);
  if (_state.status == VpnStatus.disconnected) {
    _lastUserDisconnectAt = _now();
  }
} else {
  await connect();
}
```

- [ ] **Step 4: Run the focused test and verify it passes**

Run: `flutter test --no-pub test/providers/vpn_provider_test.dart`

Expected: all provider tests pass.

- [ ] **Step 5: Commit the provider change**

```bash
git add clients/elephant-route-deprecated/lib/providers/vpn_provider.dart clients/elephant-route-deprecated/test/providers/vpn_provider_test.dart
git commit -m "fix: settle macOS disconnect toggle intent"
```

### Task 2: Publish disconnect intent before bounded latency cleanup

**Files:**
- Modify: `lib/core/singbox/macos_vpn_service.dart`
- Test: `test/core/singbox/macos_vpn_lifecycle_contract_test.dart`

- [ ] **Step 1: Write the failing lifecycle contract assertions**

Extract the `stop` method body and assert ordering plus a bounded cleanup call:

```dart
expect(
  stopBody.indexOf('_updateState(VpnStatus.disconnecting'),
  lessThan(stopBody.indexOf('stopConnectionLatencyTest()')),
);
expect(stopBody, contains('timeout(_latencyStopTimeout'));
expect(stopBody, contains('macOS latency cleanup timed out during stop'));
```

- [ ] **Step 2: Run the contract test and verify it fails**

Run: `flutter test --no-pub test/core/singbox/macos_vpn_lifecycle_contract_test.dart`

Expected: ordering and timeout assertions fail.

- [ ] **Step 3: Implement immediate state and bounded cleanup**

Add `latencyStopTimeout` to the constructor, then update stop ordering:

```dart
_updateState(VpnStatus.disconnecting, resetError: true);
try {
  await stopConnectionLatencyTest().timeout(_latencyStopTimeout);
} on TimeoutException {
  await AppLogger.instance.warn(
    'macOS latency cleanup timed out during stop; continuing runtime stop',
  );
}
final result = await _runtime.stopCore(reason: reason.wireValue);
```

Reject a native result with `stopped == false` while retaining the result in
`runtimeDetails` and still distinguishing proxy restoration failure.

- [ ] **Step 4: Run the focused lifecycle tests**

Run: `flutter test --no-pub test/core/singbox/macos_vpn_lifecycle_contract_test.dart test/core/singbox/macos_latency_session_test.dart`

Expected: all tests pass.

- [ ] **Step 5: Commit the Dart lifecycle change**

```bash
git add clients/elephant-route-deprecated/lib/core/singbox/macos_vpn_service.dart clients/elephant-route-deprecated/test/core/singbox/macos_vpn_lifecycle_contract_test.dart
git commit -m "fix: bound macOS disconnect preparation"
```

### Task 3: Force and verify long-lived helper process termination

**Files:**
- Modify: `macos/ElephantTunHelper/main.swift`
- Modify: `macos/Runner/AppDelegate.swift`
- Test: `test/core/services/mac_runtime_service_contract_test.dart`

- [ ] **Step 1: Write failing native contract assertions**

Require a SIGKILL fallback and truthful propagation:

```dart
expect(helperSource, contains('SIGKILL'));
expect(helperSource, contains('CORE_STOP_FAILED'));
expect(helperSource, contains('"ok": !coreStillRunning'));
expect(appSource, contains('let helperStopped'));
expect(appSource, contains('"stopped": helperStopped'));
```

- [ ] **Step 2: Run the native contract test and verify it fails**

Run: `flutter test --no-pub test/core/services/mac_runtime_service_contract_test.dart`

Expected: the new termination-contract assertions fail.

- [ ] **Step 3: Add verified SIGKILL fallback in the helper**

After the existing graceful waits, force the remaining matching process and
return the actual result:

```swift
if isCoreRunning(), let pattern = currentCoreProcessPattern() {
  _ = runCommand("/usr/bin/pkill", args: ["-KILL", "-f", pattern])
  waitForCoreExit(timeout: 1.0)
}
let coreStillRunning = isCoreRunning()
return [
  "ok": !coreStillRunning,
  "stopped": !coreStillRunning,
  "coreRunning": coreStillRunning,
  "code": coreStillRunning ? "CORE_STOP_FAILED" : "OK"
]
```

- [ ] **Step 4: Propagate the helper result from AppDelegate**

Compute `helperStopped` from `helperStopResult` and return
`"stopped": helperStopped`; include the helper error/code when false while
always running proxy restoration.

- [ ] **Step 5: Run the native contract test and compile the macOS app**

Run:

```bash
flutter test --no-pub test/core/services/mac_runtime_service_contract_test.dart
flutter build macos --release --no-pub
```

Expected: contract tests pass and Xcode build completes successfully.

- [ ] **Step 6: Commit native termination changes**

```bash
git add clients/elephant-route-deprecated/macos/ElephantTunHelper/main.swift clients/elephant-route-deprecated/macos/Runner/AppDelegate.swift clients/elephant-route-deprecated/test/core/services/mac_runtime_service_contract_test.dart
git commit -m "fix: verify macOS tunnel shutdown"
```

### Task 4: Full verification and 1.6.5 artifact

**Files:**
- Verify: `pubspec.yaml`
- Generate: `build/macos-beta/ElephantRoute-macos-arm64-v1.6.5.dmg`

- [ ] **Step 1: Run formatting and static analysis**

Run:

```bash
dart format --output=none --set-exit-if-changed lib test
flutter analyze --no-pub
```

Expected: formatting check and analysis exit zero.

- [ ] **Step 2: Run the complete Flutter test suite**

Run: `flutter test --no-pub`

Expected: all runnable tests pass; only documented environment skips remain.

- [ ] **Step 3: Build the existing 1.6.5 macOS release flow**

Run `MACOS_BUILD_NAME=1.6.5 MACOS_BUILD_NUMBER=10605 ./build_macos_beta.sh`.
The shared `pubspec.yaml` remains at the Windows release line; the macOS build
script applies the requested independent version through explicit Flutter
build flags.

Expected: `build/macos-beta/ElephantRoute-macos-arm64-v1.6.5.dmg` exists.

- [ ] **Step 4: Verify the release artifact**

Run `hdiutil verify`, mount the DMG read-only, inspect `CFBundleShortVersionString`,
`CFBundleVersion`, `file` architecture, `codesign --verify --deep --strict`, and
`shasum -a 256`.

Expected: version `1.6.5`, build `10605`, arm64, valid signatures, valid disk
image, and a recorded SHA-256.

- [ ] **Step 5: Perform local stop acceptance**

With a connected local app, issue one UI stop and confirm from `dart.log`,
`native.log`, `runtime-state.json`, and `pgrep` that exactly one user stop is
accepted, the status becomes disconnected, the managed sing-box process exits,
and no unrequested start follows.

- [ ] **Step 6: Commit any release metadata changes only if generated files are tracked**

Stage only intended tracked release metadata; do not add build output or local
diagnostics.
