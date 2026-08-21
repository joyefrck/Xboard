# macOS Isolated Latency Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore an isolated, no-TUN macOS latency path so displayed node delays are not inflated by the currently selected tunnel, while retaining the live Clash API as a per-node fallback.

**Architecture:** `MacosVpnService` selects a current or refreshed sanitized configuration, starts the existing `MacosLatencySession` as the primary probe, streams successful isolated results, and then sends only failed nodes to `MacosLatencyFallbackRunner`. Session ownership remains inside the service so cancellation, disconnect, timeout, and disposal close the child sing-box and curl processes.

**Tech Stack:** Flutter/Dart, Dio 5, sing-box 1.13.15-xboard.1, macOS curl, Flutter test, macOS arm64 DMG packaging

---

### Task 1: Reinstate the isolated latency orchestration contract

**Files:**
- Modify: `test/core/singbox/macos_vpn_lifecycle_contract_test.dart`
- Modify: `test/core/singbox/macos_vpn_service_latency_test.dart`

- [ ] **Step 1: Replace the obsolete live-core-only contract with the primary-and-fallback contract**

Update the source contract to extract `_runConnectionLatencies` and assert that it constructs `MacosLatencySession`, passes its results as `primaryResults`, and keeps `_latencyFallbackRunner.resolve`. Also assert that the method does not stop or restart the production core:

```dart
expect(body, contains('MacosLatencySession('));
expect(body, contains('primaryResults: primaryResults'));
expect(body, contains('_latencyFallbackRunner.resolve'));
expect(body, isNot(contains('_runtime.stopCore')));
expect(body, isNot(contains('_runtime.startTunMode')));
```

- [ ] **Step 2: Restore lifecycle deadline expectations**

Add the cache deadline assertion because the service will again read a refreshed subscription configuration before constructing the isolated session:

```dart
expect(
  () => MacosVpnService(latencyCacheReadTimeout: Duration.zero),
  throwsArgumentError,
);
```

- [ ] **Step 3: Run the focused tests and verify the red state**

Run:

```bash
flutter test --no-pub \
  test/core/singbox/macos_vpn_service_latency_test.dart \
  test/core/singbox/macos_vpn_lifecycle_contract_test.dart
```

Expected: the lifecycle constructor test fails because `latencyCacheReadTimeout` is absent, and the source contract fails because the service still uses `primaryResults: const {}` without `MacosLatencySession`.

### Task 2: Restore isolated primary probing and per-node fallback

**Files:**
- Modify: `lib/core/singbox/macos_vpn_service.dart`

- [ ] **Step 1: Restore the isolated latency dependencies and owned state**

Reintroduce `SubscriptionConfigCache`, `MacosLatencySession`, and `MacosLatencySourceSelector` imports. Extend the constructor with a cache and one-second cache-read deadline, validate that deadline, and restore these fields:

```dart
final SubscriptionConfigCache _subscriptionConfigCache;
final Duration _latencyCacheReadTimeout;
String? _singboxBinPath;
MacosLatencySession? _latencySession;
```

Save `binFile.path` into `_singboxBinPath` after the production configuration validates.

- [ ] **Step 2: Restore source selection and isolated session execution**

In `_runConnectionLatencies`, require the active sanitized config and binary path, cancel the prior generation, then attempt a bounded cache refresh. Use `MacosLatencySourceSelector.select` to split requested nodes into eligible and missing sets. Mark only missing nodes as `serviceError`.

Create `MacosLatencySession` with the selected source configuration and caller-provided worker count:

```dart
final session = MacosLatencySession(
  binaryPath: binaryPath,
  sourceConfig: sourceSelection.sourceConfig,
  nodeTags: sourceSelection.eligibleNodeTags,
  testUrl: LatencyTestPolicy.macosConnectionProbeUrls(testUrl).first,
  timeoutMs: timeoutMs,
  workerCount: concurrency,
);
```

Stream only successful primary results immediately. If session startup or execution throws, convert every eligible node into a typed `serviceError` primary result so fallback can continue.

- [ ] **Step 3: Keep live-core fallback restricted to failed primary nodes**

Pass `primaryResults` to the existing fallback runner:

```dart
final resolvedResults = await _latencyFallbackRunner.resolve(
  nodeTags: sourceSelection.eligibleNodeTags,
  primaryResults: primaryResults,
  testUrls: LatencyTestPolicy.macosConnectionProbeUrls(testUrl),
  timeoutMs: timeoutMs,
  isCancelled: isCancelled,
  onResult: (nodeTag, result) {
    if (!isCancelled()) onResult?.call(nodeTag, result);
  },
);
```

Merge resolved and unavailable results in the original node order. Do not stop or restart the connected production core.

- [ ] **Step 4: Restore cancellation cleanup**

Make `stopConnectionLatencyTest` increment the generation, detach the owned session, and await `session.close()`. This guarantees that repeat tests, disconnect, and disposal terminate curl and the temporary sing-box process.

- [ ] **Step 5: Format and run the focused test suite**

Run:

```bash
dart format \
  lib/core/singbox/macos_vpn_service.dart \
  test/core/singbox/macos_vpn_service_latency_test.dart \
  test/core/singbox/macos_vpn_lifecycle_contract_test.dart
flutter test --no-pub \
  test/core/singbox/macos_latency_config_test.dart \
  test/core/singbox/macos_latency_session_test.dart \
  test/core/singbox/macos_latency_source_selector_test.dart \
  test/core/singbox/macos_latency_fallback_test.dart \
  test/core/singbox/macos_vpn_service_latency_test.dart \
  test/core/singbox/macos_vpn_lifecycle_contract_test.dart
```

Expected: all isolated-session, fallback, service lifecycle, and source-contract tests pass.

### Task 3: Verify the real symptom and package v1.6.5

**Files:**
- Verify: `pubspec.yaml`
- Verify: `assets/bin/sing-box-darwin-arm64.version`
- Create: `build/macos-beta/ElephantRoute-macos-arm64-v1.6.5.dmg`

- [ ] **Step 1: Run a real isolated probe against the installed configuration**

Use a temporary diagnostic test that reads the installed sanitized config without printing node credentials. Probe at least two Tokyo nodes through one isolated worker and confirm that the selected minimum values are near the observed 116–120ms range rather than the live-core 208–256ms range. Remove the temporary diagnostic file afterward.

- [ ] **Step 2: Run the full static and test verification**

Run:

```bash
flutter analyze --no-pub
flutter test --no-pub
```

Expected: analyzer exits with no errors and the full Flutter test suite reports zero failures.

- [ ] **Step 3: Verify version invariants and build the arm64 DMG**

Confirm `pubspec.yaml` remains `1.6.5+10605` and the bundled core remains `1.13.15-xboard.1`. Run the repository macOS release command used by the existing v1.6.5 packaging workflow, then verify:

```bash
hdiutil verify build/macos-beta/ElephantRoute-macos-arm64-v1.6.5.dmg
shasum -a 256 build/macos-beta/ElephantRoute-macos-arm64-v1.6.5.dmg
```

Expected: `hdiutil` reports the image is valid and SHA-256 is recorded for delivery.

- [ ] **Step 4: Commit and publish the implementation**

Stage only the latency service, tests, plan, and resulting intended release metadata. Commit with:

```bash
git commit -m "fix: isolate macOS node latency tests"
```

Push the current `master`, verify the remote SHA and CI status, then fast-forward the already-authorized production source checkout. Because this is a desktop-client-only change, do not restart backend containers.
