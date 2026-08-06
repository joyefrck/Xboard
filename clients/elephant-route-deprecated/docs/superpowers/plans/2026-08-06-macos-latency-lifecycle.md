# macOS Latency Lifecycle Repair Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Guarantee that macOS node latency testing completes or cancels within a bounded time and never leaves the node-selection UI stuck on `测速中`.

**Architecture:** Keep the existing macOS primary and fallback probes unchanged. Add a bounded service preflight and whole-run deadline, then add a provider generation token and provider safety deadline so disconnects and stale callbacks cannot retain or overwrite UI state. Expose refresh and latency state separately for the node-selection button.

**Tech Stack:** Flutter/Dart, Provider, `Future.timeout`, existing `ConnectionLatencyManager`, Flutter test.

---

### Task 1: Provider lifecycle regression tests

**Files:**
- Modify: `test/providers/node_provider_latency_test.dart`

- [ ] **Step 1: Add a controllable hanging latency manager**

Extend `_LatencyVpnManager` with a mode that returns an uncompleted future, stores the result callback, counts stop calls, and can emit a late success after cancellation.

- [ ] **Step 2: Add failing lifecycle tests**

Add tests that assert:

```dart
expect(provider.isTestingLatency, isTrue);
vpnManager.emit(VpnStatus.disconnected);
await Future<void>.delayed(Duration.zero);
expect(provider.isTestingLatency, isFalse);
expect(vpnManager.stopLatencyTestCalls, greaterThan(0));
```

Also assert that a late callback from the cancelled run does not write latency and that `isLoadingNodes` and `isTestingLatency` are independent.

- [ ] **Step 3: Run the focused test and confirm RED**

Run:

```bash
flutter test test/providers/node_provider_latency_test.dart
```

Expected: failure because the new getters, cancellation behavior, and stale callback suppression are not implemented.

### Task 2: Provider generation and safety deadline

**Files:**
- Modify: `lib/providers/node_provider.dart`
- Modify: `lib/screens/home/node_selection_screen.dart`
- Test: `test/providers/node_provider_latency_test.dart`

- [ ] **Step 1: Add separate state getters and run generation**

Add `isLoadingNodes`, `isTestingLatency`, `_latencyRunGeneration`, and an injectable `connectionLatencySafetyTimeout` whose production default is 65 seconds.

- [ ] **Step 2: Cancel on disconnect**

When VPN state leaves `connected`, increment the generation, clear `_isTestingLatency`, notify listeners, and call `stopConnectionLatencyTest()` without blocking the state stream.

- [ ] **Step 3: Guard results and finalization**

Capture the generation at test start. Accept result callbacks and clear the flag in `finally` only when that generation is still current. Wrap the manager future in the provider safety deadline; on timeout, call the manager stop method and publish timeout results for unfinished nodes.

- [ ] **Step 4: Bind the button to latency state only**

Replace `final isTesting = provider.isLoading;` with:

```dart
final isTesting = provider.isTestingLatency;
```

Keep the list-loading branch bound to node-loading state.

- [ ] **Step 5: Run provider tests and confirm GREEN**

Run:

```bash
flutter test test/providers/node_provider_latency_test.dart
```

Expected: all provider latency tests pass.

### Task 3: macOS service deadlines

**Files:**
- Modify: `lib/core/singbox/macos_vpn_service.dart`
- Modify: `test/core/singbox/macos_vpn_service_latency_test.dart`

- [ ] **Step 1: Add deadline configuration**

Add injectable `latencyCacheReadTimeout` and `latencyRunTimeout` constructor values with production defaults of 1 second and 60 seconds.

- [ ] **Step 2: Bound cache preflight**

Apply the cache timeout to `_subscriptionConfigCache.read()`. On timeout or error, log a warning and continue with `_lastSanitizedConfig`.

- [ ] **Step 3: Bound the complete service run**

Move the existing source-selection, session, cleanup, and fallback flow into a private run method. Wrap it with `latencyRunTimeout`; on timeout increment the cancellation generation, close the owned session, suppress later callbacks, and return timeout results for nodes without a successful result.

- [ ] **Step 4: Add service contract coverage**

Add tests for constructor deadline validation/defaults and idle cancellation idempotence. The observable hanging-manager behavior remains covered at the provider boundary because it reproduces the user-visible defect without starting a native process.

- [ ] **Step 5: Run macOS latency tests**

Run:

```bash
flutter test test/core/singbox/macos_vpn_service_latency_test.dart test/core/singbox/macos_latency_session_test.dart test/core/singbox/macos_latency_fallback_test.dart
```

Expected: all selected tests pass.

### Task 4: Verification

**Files:**
- Verify all modified source and test files

- [ ] **Step 1: Format and diff check**

Run:

```bash
dart format lib/providers/node_provider.dart lib/screens/home/node_selection_screen.dart lib/core/singbox/macos_vpn_service.dart test/providers/node_provider_latency_test.dart test/core/singbox/macos_vpn_service_latency_test.dart
git diff --check
```

Expected: formatter completes and `git diff --check` exits 0.

- [ ] **Step 2: Run the complete focused regression set**

Run:

```bash
flutter test test/providers/node_provider_latency_test.dart test/core/singbox/macos_vpn_service_latency_test.dart test/core/singbox/macos_latency_session_test.dart test/core/singbox/macos_latency_fallback_test.dart test/core/singbox/macos_latency_source_selector_test.dart test/core/singbox/latency_test_policy_test.dart
```

Expected: zero failures.

- [ ] **Step 3: Run static analysis on changed source**

Run:

```bash
flutter analyze lib/providers/node_provider.dart lib/screens/home/node_selection_screen.dart lib/core/singbox/macos_vpn_service.dart
```

Expected: no new errors.

- [ ] **Step 4: Build the macOS release app**

Run:

```bash
flutter build macos --release
```

Expected: exit 0 and a release `.app` under `build/macos/Build/Products/Release/`.

- [ ] **Step 5: Review final diff**

Run:

```bash
git status --short
git diff --stat HEAD
git diff HEAD -- lib/providers/node_provider.dart lib/screens/home/node_selection_screen.dart lib/core/singbox/macos_vpn_service.dart test/providers/node_provider_latency_test.dart test/core/singbox/macos_vpn_service_latency_test.dart
```

Expected: only the scoped lifecycle, UI binding, tests, and documentation are changed.
