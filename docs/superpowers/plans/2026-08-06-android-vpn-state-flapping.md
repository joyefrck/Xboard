# Android VPN State Flapping Repair Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prevent stale Android command-client callbacks from alternating the VPN switch state and prevent false reconnect events from queuing repeated node latency tests.

**Architecture:** Add a pure Kotlin generation gate that owns delayed command-client attempts, then publish newly created clients only while their generation is current and the service is connected. Preserve the last valid status for status-less broadcasts, and replace Dart's uncancelable delayed latency call with one cancelable timer.

**Tech Stack:** Kotlin, Android `VpnService`, libbox command clients, JUnit 4, Flutter/Dart, `flutter_test`, ADB.

---

### Task 1: Command-client lifecycle generation

**Files:**
- Create: `clients/elephant-route-deprecated/android/app/src/main/kotlin/com/elephantroute/VpnCommandClientLifecycle.kt`
- Create: `clients/elephant-route-deprecated/android/app/src/test/kotlin/com/elephantroute/VpnCommandClientLifecycleTest.kt`

- [ ] **Step 1: Write failing lifecycle tests**

```kotlin
@Test
fun newerAttemptInvalidatesOlderAttempt() {
    val lifecycle = VpnCommandClientLifecycle()
    val first = lifecycle.beginConnect()
    val second = lifecycle.beginConnect()

    assertFalse(lifecycle.isCurrent(first, isConnected = true))
    assertTrue(lifecycle.isCurrent(second, isConnected = true))
}

@Test
fun invalidateAndDestroyRejectPendingAttempts() {
    val lifecycle = VpnCommandClientLifecycle()
    val invalidated = lifecycle.beginConnect()
    lifecycle.invalidate()
    assertFalse(lifecycle.isCurrent(invalidated, isConnected = true))

    val destroyed = lifecycle.beginConnect()
    lifecycle.destroy()
    assertFalse(lifecycle.isCurrent(destroyed, isConnected = true))
}
```

- [ ] **Step 2: Run the tests and confirm the missing class fails**

Run: `cd clients/elephant-route-deprecated/android && ./gradlew app:testDebugUnitTest --tests com.elephantroute.VpnCommandClientLifecycleTest`

Expected: compilation failure because `VpnCommandClientLifecycle` does not exist.

- [ ] **Step 3: Implement the synchronized generation gate**

```kotlin
internal class VpnCommandClientLifecycle {
    private var generation = 0L
    private var destroyed = false

    @Synchronized
    fun beginConnect(): Long = ++generation

    @Synchronized
    fun invalidate() {
        generation++
    }

    @Synchronized
    fun destroy() {
        destroyed = true
        generation++
    }

    @Synchronized
    fun isCurrent(token: Long, isConnected: Boolean): Boolean =
        !destroyed && isConnected && token == generation

    @Synchronized
    fun publishIfCurrent(token: Long, isConnected: Boolean, publish: () -> Unit): Boolean {
        if (!isCurrent(token, isConnected)) return false
        publish()
        return true
    }
}
```

- [ ] **Step 4: Run the focused Kotlin test and confirm it passes**

Run the command from Step 2. Expected: all lifecycle tests pass.

### Task 2: Make `SingboxVpnService` own delayed clients safely

**Files:**
- Modify: `clients/elephant-route-deprecated/android/app/src/main/kotlin/com/elephantroute/SingboxVpnService.kt`
- Test: `clients/elephant-route-deprecated/android/app/src/test/kotlin/com/elephantroute/VpnCommandClientLifecycleTest.kt`

- [ ] **Step 1: Add a publish-race test**

```kotlin
@Test
fun staleAttemptCannotPublishAfterInvalidation() {
    val lifecycle = VpnCommandClientLifecycle()
    val token = lifecycle.beginConnect()
    lifecycle.invalidate()
    var published = false

    assertFalse(lifecycle.publishIfCurrent(token, isConnected = true) { published = true })
    assertFalse(published)
}
```

- [ ] **Step 2: Run the focused test and verify it fails before implementation changes**

Expected: failure until `publishIfCurrent` is implemented.

- [ ] **Step 3: Integrate generation ownership into the service**

Add `private val commandClientLifecycle = VpnCommandClientLifecycle()`. In `connectCommandClient()`, capture `val token = commandClientLifecycle.beginConnect()` before starting the thread. After the delay, require `isCurrent(token, currentStatus == "connected")` before creating clients. Create the standalone client and subscriptions in local variables; publish them through `publishIfCurrent`. If publication fails, disconnect every local client.

Invalidate before outbound restart, VPN stop, and speed-test client cleanup. Call `destroy()` before `stopVpn()` in `onDestroy()`. Catch blocks close only clients created by that attempt and must not disconnect a newer generation.

- [ ] **Step 4: Compile and run Android unit tests**

Run: `cd clients/elephant-route-deprecated/android && ./gradlew app:testDebugUnitTest`

Expected: all Kotlin tests pass.

- [ ] **Step 5: Commit the native lifecycle repair**

```bash
git add clients/elephant-route-deprecated/android/app/src/main/kotlin/com/elephantroute/VpnCommandClientLifecycle.kt \
  clients/elephant-route-deprecated/android/app/src/main/kotlin/com/elephantroute/SingboxVpnService.kt \
  clients/elephant-route-deprecated/android/app/src/test/kotlin/com/elephantroute/VpnCommandClientLifecycleTest.kt
git commit -m "fix(android): own VPN command client lifecycle"
```

### Task 3: Preserve status for payloads without a status field

**Files:**
- Create: `clients/elephant-route-deprecated/android/app/src/main/kotlin/com/elephantroute/VpnBroadcastState.kt`
- Modify: `clients/elephant-route-deprecated/android/app/src/main/kotlin/com/elephantroute/MainActivity.kt`
- Create: `clients/elephant-route-deprecated/android/app/src/test/kotlin/com/elephantroute/VpnBroadcastStateTest.kt`

- [ ] **Step 1: Write the failing status preservation test**

```kotlin
@Test
fun missingStatusPreservesLastExplicitStatus() {
    val state = VpnBroadcastState()
    assertEquals("connected", state.resolve("connected"))
    assertEquals("connected", state.resolve(null))
    assertEquals("connected", state.resolve(""))
}
```

- [ ] **Step 2: Run the focused test and confirm the missing class fails**

Run: `cd clients/elephant-route-deprecated/android && ./gradlew app:testDebugUnitTest --tests com.elephantroute.VpnBroadcastStateTest`

- [ ] **Step 3: Implement and wire the pure status holder**

```kotlin
internal class VpnBroadcastState(initialStatus: String = "disconnected") {
    private var lastStatus = initialStatus

    @Synchronized
    fun resolve(incomingStatus: String?): String {
        if (!incomingStatus.isNullOrBlank()) lastStatus = incomingStatus
        return lastStatus
    }
}
```

`MainActivity.vpnStateReceiver` must call `broadcastState.resolve(it.getStringExtra("status"))` instead of defaulting a missing extra to `"disconnected"`.

- [ ] **Step 4: Run Android unit tests and commit**

Expected: all Kotlin tests pass.

### Task 4: Deduplicate connection-triggered latency timers

**Files:**
- Modify: `clients/elephant-route-deprecated/lib/providers/node_provider.dart`
- Modify: `clients/elephant-route-deprecated/test/providers/node_provider_latency_test.dart`

- [ ] **Step 1: Add a failing provider test**

Extend the fake VPN manager with a mutable current state, `emit(VpnStatus)`, and `latencyTestCalls`. Construct `NodeProvider` with an injectable short `connectionLatencyDelay`. Emit `connected`, then `disconnected`, then `connected` before the delay expires. After the delay, assert `latencyTestCalls == 1`. Dispose before another pending timer and assert the count remains unchanged.

- [ ] **Step 2: Run the provider test and verify it fails**

Run: `cd clients/elephant-route-deprecated && flutter test test/providers/node_provider_latency_test.dart`

Expected: constructor or behavior failure because delayed connection tests are not cancelable.

- [ ] **Step 3: Replace `Future.delayed` with one owned timer**

Add an optional `connectionLatencyDelay` constructor parameter defaulting to two seconds and a `Timer? _connectionLatencyTimer`. Cancel the previous timer before scheduling, cancel it on every non-connected state, and cancel it in `dispose()`. The timer callback must clear its field and recheck `VpnStatus.connected` before calling `testAllLatencies()`.

- [ ] **Step 4: Run the focused provider tests and commit**

Run: `flutter test test/providers/node_provider_latency_test.dart`. Expected: all tests pass.

### Task 5: Full automated verification and release APK

**Files:**
- Verify all changed client files and generated APK; do not change version metadata.

- [ ] **Step 1: Run formatting and diff checks**

Run `dart format` on changed Dart files, then `git diff --check`.

- [ ] **Step 2: Run Android unit tests**

Run: `cd clients/elephant-route-deprecated/android && ./gradlew app:testDebugUnitTest`

- [ ] **Step 3: Run Flutter analysis and full tests**

Run from `clients/elephant-route-deprecated`:

```bash
flutter analyze
flutter test --no-pub
```

- [ ] **Step 4: Build the release arm64 APK**

Run: `flutter build apk --release --target-platform android-arm64`

Expected artifact: `clients/elephant-route-deprecated/build/app/outputs/flutter-apk/app-release.apk` with version `1.6.7+10607`.

### Task 6: Pixel 6 Pro acceptance test

**Files:**
- No source changes unless device evidence exposes a distinct failed hypothesis.

- [ ] **Step 1: Install and relaunch**

Run `adb install -r -d build/app/outputs/flutter-apk/app-release.apk`, then launch `com.elephantroute/.MainActivity`.

- [ ] **Step 2: Clear bounded logs and reproduce automatic selection**

Select automatic node mode, toggle VPN on and off repeatedly, reconnect, and capture `adb logcat` plus `dumpsys activity broadcasts` and `dumpsys connectivity`.

- [ ] **Step 3: Verify acceptance criteria**

Require one native one-second status cadence, no alternating `connected/disconnected` Flutter states, no repeated delayed latency starts, stable `tun0`, and correct UI/manual switch behavior. Record the installed APK hash and final system VPN state.
