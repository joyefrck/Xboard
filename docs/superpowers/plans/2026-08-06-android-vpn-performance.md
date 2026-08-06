# Android VPN Performance Stabilization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the confirmed Android VPN logging, notification, duplicate-stream, stale-latency, and main-thread restart hot paths without changing user-visible VPN behavior.

**Architecture:** Keep the existing Flutter-to-Android service bridge and make native status the single Android traffic source. Production subscribes only to status, throttles notification rendering independently, emits one-shot latency payloads, and moves outbound restart work off the service main thread.

**Tech Stack:** Flutter/Dart, Kotlin, Android `VpnService`, libbox command clients, JUnit 4, Flutter test.

---

### Task 1: One-shot latency state

**Files:**
- Modify: `clients/elephant-route-deprecated/lib/core/singbox/vpn_state.dart`
- Modify: `clients/elephant-route-deprecated/lib/core/singbox/real_vpn_service.dart`
- Test: `clients/elephant-route-deprecated/test/core/singbox/vpn_state_test.dart`

- [ ] Add a failing test that creates a state with `latencyMap`, calls `copyWith(resetLatencyMap: true)`, and expects `latencyMap` to be null.
- [ ] Run `flutter test test/core/singbox/vpn_state_test.dart` and confirm the missing parameter fails.
- [ ] Add `bool resetLatencyMap = false` and select null when it is true.
- [ ] Pass `resetLatencyMap: !map.containsKey('latency_update')` when decoding native updates.
- [ ] Rerun the focused test and confirm it passes.

### Task 2: Android single traffic source

**Files:**
- Modify: `clients/elephant-route-deprecated/lib/providers/vpn_provider.dart`
- Test: `clients/elephant-route-deprecated/test/providers/vpn_provider_test.dart`

- [ ] Add an injectable `usesNativeTrafficOnly` flag that defaults to Android.
- [ ] Add a failing provider test that connects with the flag enabled and expects zero Clash stream opens while native counters still update provider state.
- [ ] Guard traffic-stream start/stop transitions with the flag.
- [ ] Preserve existing tests by explicitly selecting fallback-stream mode in their setup.
- [ ] Run the provider test file and confirm all cases pass.

### Task 3: Production command and notification policy

**Files:**
- Create: `clients/elephant-route-deprecated/android/app/src/main/kotlin/com/elephantroute/VpnRuntimePolicy.kt`
- Modify: `clients/elephant-route-deprecated/android/app/src/main/kotlin/com/elephantroute/SingboxVpnService.kt`
- Test: `clients/elephant-route-deprecated/android/app/src/test/kotlin/com/elephantroute/VpnRuntimePolicyTest.kt`

- [ ] Add failing JUnit tests asserting notification updates are denied inside ten seconds and release command subscriptions contain only `CommandStatus`.
- [ ] Implement a pure `VpnRuntimePolicy` with `shouldUpdateNotification(nowMs)` and `commands(isDebug)`.
- [ ] Set sing-box logging to `debug` for debug builds and `warn` for release builds; never force `trace`.
- [ ] Build command subscriptions from the policy so release excludes `CommandGroup` and `CommandLog`.
- [ ] Remove per-node/group logging and throttle `NotificationManager.notify()` to ten seconds.
- [ ] Make broadcasts package-explicit and explicitly disconnect the standalone command client.
- [ ] Run `./gradlew app:testDebugUnitTest` and confirm policy and existing Kotlin tests pass.

### Task 4: Non-blocking outbound restart

**Files:**
- Modify: `clients/elephant-route-deprecated/android/app/src/main/kotlin/com/elephantroute/SingboxVpnService.kt`

- [ ] Extract the existing outbound config mutation, cache cleanup, client disconnect, engine stop, and restart into `restartWithOutbound()`.
- [ ] Make the `SELECT_OUTBOUND` branch enqueue that function on a named background thread and return immediately.
- [ ] Reject a second restart while `isRestarting` is true and clear the flag in `finally`.
- [ ] Compile Kotlin tests to catch lifecycle and visibility errors.

### Task 5: Dart log and rebuild reduction

**Files:**
- Modify: `clients/elephant-route-deprecated/lib/providers/node_provider.dart`
- Modify: `clients/elephant-route-deprecated/lib/core/singbox/real_vpn_service.dart`

- [ ] Guard diagnostic latency/state messages with `kDebugMode` and remove long JSON payload logging.
- [ ] Preserve error logs and user-visible failure handling.
- [ ] Run focused node latency tests and provider tests.

### Task 6: Full verification and device comparison

**Files:**
- Verify all modified files and generated APK metadata; do not change release version tracking.

- [ ] Run `flutter analyze` from `clients/elephant-route-deprecated`.
- [ ] Run `flutter test --no-pub` and record the test count and exit code.
- [ ] Run `./gradlew app:testDebugUnitTest` from `clients/elephant-route-deprecated/android`.
- [ ] Run `flutter build apk --release --target-platform android-arm64` without invoking the version-incrementing release script.
- [ ] Install the generated APK with `adb install -r -d`, relaunch, reconnect, and verify VPN state and latency testing.
- [ ] Sample foreground/background CPU, PSS/RSS, thread count, notification posts, and release Logcat; compare with the 24-35% CPU and 584 MB RSS baseline.

