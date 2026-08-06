# Android Latency IPv4 and Failure Reporting Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Android node latency probes resolve destinations to IPv4 inside hidden worker routes and preserve the real native failure category through the Flutter UI.

**Architecture:** Add a worker-inbound-only sing-box `resolve` action before each hidden selector route, leaving the main VPN route untouched. Extend the existing Android OkHttp probe result with HTTP status and failure fields, then strictly parse them into the shared Dart latency model.

**Tech Stack:** Flutter/Dart, Kotlin, OkHttp, sing-box 1.12 route actions, Flutter Test, JUnit/Gradle, ADB.

---

### Task 1: Resolve hidden Android latency traffic to IPv4

**Files:**
- Modify: `lib/core/singbox/android_latency_config.dart:82-89`
- Test: `test/core/singbox/android_latency_config_test.dart:47-82`

- [ ] **Step 1: Write the failing config assertions**

Assert that each latency inbound first receives an IPv4-only resolve action and
then an explicit selector route action:

```dart
expect(rules.take(2), [
  {
    'inbound': ['__elephant_latency_in_0'],
    'action': 'resolve',
    'strategy': 'ipv4_only',
  },
  {
    'inbound': ['__elephant_latency_in_0'],
    'action': 'route',
    'outbound': '__elephant_latency_worker_0',
  },
]);
```

- [ ] **Step 2: Run the focused test and verify it fails**

Run:

```bash
flutter test test/core/singbox/android_latency_config_test.dart
```

Expected: FAIL because the first worker rule has no `resolve` action.

- [ ] **Step 3: Add paired resolve and route actions**

Build two rules per worker before all original rules:

```dart
for (var index = 0; index < workerPorts.length; index++) ...[
  <String, dynamic>{
    'inbound': <String>['$androidLatencyInboundPrefix$index'],
    'action': 'resolve',
    'strategy': 'ipv4_only',
  },
  <String, dynamic>{
    'inbound': <String>['$androidLatencyInboundPrefix$index'],
    'action': 'route',
    'outbound': '$androidLatencySelectorPrefix$index',
  },
],
```

- [ ] **Step 4: Run the focused test and verify it passes**

Run the same Flutter test. Expected: all Android latency config tests PASS.

### Task 2: Return typed failures from the native Android probe

**Files:**
- Modify: `android/app/src/main/kotlin/com/elephantroute/AndroidConnectionProbeManager.kt:16-128`
- Modify: `android/app/src/main/kotlin/com/elephantroute/MainActivity.kt:135-174`
- Test: `android/app/src/test/kotlin/com/elephantroute/AndroidConnectionProbeManagerTest.kt`

- [ ] **Step 1: Add failing native tests**

Cover these result contracts:

```kotlin
assertEquals(listOf(204, 204), result.httpStatusCodes)
assertEquals(null, result.failureKind)
assertEquals("httpError", httpFailure.failureKind)
assertEquals("transportError", transportFailure.failureKind)
assertEquals("timeout", timeoutFailure.failureKind)
assertEquals("cancelled", cancelledFailure.failureKind)
```

Also verify `nodeKey` is deterministic, 12 hexadecimal characters long, and
does not contain the raw node tag.

- [ ] **Step 2: Run the Android unit test and verify it fails**

Run:

```bash
./android/gradlew -p android app:testDebugUnitTest \
  --tests com.elephantroute.AndroidConnectionProbeManagerTest
```

Expected: Kotlin compilation FAIL because the result fields and `nodeKey` do
not exist.

- [ ] **Step 3: Implement failure classification**

Add `failureKind` and `httpStatusCodes` to `AndroidConnectionProbeResult`.
Track a zero status for attempts that receive no HTTP response. Classify
`SocketTimeoutException` and non-cancelled `InterruptedIOException` as timeout,
other `IOException` as transport error, non-200/204 responses as HTTP error,
and cancelled sessions as cancelled. If any attempt succeeds, return no
failure and the lowest successful latency.

- [ ] **Step 4: Add safe native correlation logging**

Require a `nodeTag` method-channel argument, hash it with SHA-256, and log only
the first 12 lowercase hexadecimal characters:

```kotlin
val nodeKey = AndroidConnectionProbeManager.nodeKey(nodeTag)
Log.d(
    "AndroidConnectionProbe",
    "[SPEED_TEST_NATIVE] nodeKey=$nodeKey proxyPort=$proxyPort " +
        "attempts=${probeResult.attempts} statuses=${probeResult.httpStatusCodes} " +
        "failure=${probeResult.failureKind ?: "none"}",
)
```

Never log the raw node tag or native exception message.

- [ ] **Step 5: Run the focused Android test**

Run the command from Step 2. Expected: all tests in
`AndroidConnectionProbeManagerTest` PASS.

### Task 3: Parse typed Android failures in Dart

**Files:**
- Modify: `lib/core/singbox/android_connection_probe.dart`
- Modify: `lib/core/singbox/android_latency_session.dart`
- Test: `test/core/singbox/android_connection_probe_test.dart`
- Test: `test/core/singbox/android_latency_session_test.dart`

- [ ] **Step 1: Write failing bridge and session tests**

Verify that `nodeTag` is forwarded, `httpStatusCodes` are parsed, each native
failure string maps to its shared enum, unknown or missing failure data is
rejected, and stopped/unstarted nodes become `cancelled` rather than timeout or
service error.

- [ ] **Step 2: Run focused Flutter tests and verify they fail**

Run:

```bash
flutter test \
  test/core/singbox/android_connection_probe_test.dart \
  test/core/singbox/android_latency_session_test.dart
```

Expected: FAIL because `AndroidNodeProbe.run` does not accept `nodeTag` and the
native payload parser ignores failure/status fields.

- [ ] **Step 3: Extend the probe interface and strict parser**

Add `required String nodeTag` to `AndroidNodeProbe.run`, forward it over the
method channel, parse `failureKind` with an exhaustive switch, and parse the
HTTP status list as integers. Enforce these invariants:

```dart
if (latencyMs > 0 && failureKind != null) {
  throw StateError('Successful Android latency probe cannot have a failure');
}
if (latencyMs <= 0 && failureKind == null) {
  throw StateError('Failed Android latency probe must include a failure');
}
```

Pass the current node tag from `AndroidLatencySession`. When the session is
stopped, classify all unstarted nodes as `cancelled`. Selector update and other
session-layer exceptions remain `serviceError`.

- [ ] **Step 4: Run focused Flutter tests**

Run the command from Step 2. Expected: all focused tests PASS.

### Task 4: Verify behavior and device acceptance

**Files:**
- Verify all files above
- Device: connected Pixel 6 Pro with package `com.elephantroute`

- [ ] **Step 1: Run formatting and diff checks**

```bash
dart format lib/core/singbox/android_latency_config.dart \
  lib/core/singbox/android_connection_probe.dart \
  lib/core/singbox/android_latency_session.dart \
  test/core/singbox/android_latency_config_test.dart \
  test/core/singbox/android_connection_probe_test.dart \
  test/core/singbox/android_latency_session_test.dart
git diff --check
```

Expected: formatting exits zero and `git diff --check` has no output.

- [ ] **Step 2: Run the complete focused regression set**

```bash
flutter test \
  test/core/singbox/android_latency_config_test.dart \
  test/core/singbox/android_connection_probe_test.dart \
  test/core/singbox/android_latency_session_test.dart \
  test/providers/node_provider_latency_test.dart \
  test/utils/node_latency_display_test.dart
./android/gradlew -p android app:testDebugUnitTest \
  --tests com.elephantroute.AndroidConnectionProbeManagerTest
flutter analyze
```

Expected: zero test failures and no new analyzer errors.

- [ ] **Step 3: Build and install a test APK without clearing data**

```bash
flutter build apk --debug
adb install -r build/app/outputs/flutter-apk/app-debug.apk
```

Expected: build exits zero and ADB reports `Success`. Existing application data
is retained.

- [ ] **Step 4: Repeat device latency testing and inspect logs**

Start the VPN and run the complete latency test twice. Confirm logs contain
`nodeKey`, statuses, and typed failures; contain no remote gstatic IPv6
`network is unreachable` failures for the earlier four nodes; retain a true
timeout for a node that exhausts five seconds; and never contain a raw node
tag or subscription credential.

- [ ] **Step 5: Review final diff and commit the implementation**

Review `git diff`, stage only the planned files, then commit with:

```bash
git commit -m "fix: make Android latency probes IPv4-aware"
```
