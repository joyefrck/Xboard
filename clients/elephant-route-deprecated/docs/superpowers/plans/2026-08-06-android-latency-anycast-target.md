# Android Latency Anycast Target Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make default Android connection-latency probes use a global HTTPS Anycast 204 endpoint without changing custom URLs or other platforms.

**Architecture:** Add an Android-specific value to `LatencyTestProfile`. Keep endpoint selection centralized in `LatencyTestPolicy`, then let the existing `NodeProvider` platform selection automatically pass the Android URL into the unchanged connection-probe session.

**Tech Stack:** Flutter, Dart, `flutter_test`, Android ADB runtime verification

---

### Task 1: Define Android-only endpoint policy

**Files:**
- Modify: `test/core/singbox/latency_test_policy_test.dart`
- Modify: `lib/core/singbox/latency_test_policy.dart`

- [ ] **Step 1: Write failing Android profile tests**

Add assertions that Android selects its own profile, default values select the
HTTPS Cloudflare Anycast endpoint, explicit custom values remain unchanged,
and the Android timeout remains 5000 ms:

```dart
test('Android connection profile uses HTTPS Anycast for defaults', () {
  for (final configured in <String>[
    '',
    'http://cp.cloudflare.com/generate_204',
    'https://www.gstatic.com/generate_204',
  ]) {
    expect(
      LatencyTestPolicy.probeUrls(
        configuredTestUrl: configured,
        profile: LatencyTestProfile.androidConnection,
      ),
      ['https://cp.cloudflare.com/generate_204'],
    );
  }
  expect(
    LatencyTestPolicy.timeoutMsFor(
      LatencyTestProfile.androidConnection,
    ),
    5000,
  );
});

test('Android connection profile preserves a custom probe URL', () {
  expect(
    LatencyTestPolicy.probeUrls(
      configuredTestUrl: ' https://example.com/custom_204 ',
      profile: LatencyTestProfile.androidConnection,
    ),
    ['https://example.com/custom_204'],
  );
});
```

Change the existing Android platform assertion to expect
`LatencyTestProfile.androidConnection`, while retaining the existing desktop
V2BOX assertions as non-Android regression coverage.

- [ ] **Step 2: Run the policy test and verify red**

Run:

```bash
flutter test test/core/singbox/latency_test_policy_test.dart
```

Expected: compilation fails because `LatencyTestProfile.androidConnection`
does not exist.

- [ ] **Step 3: Implement minimal policy routing**

Update the enum and endpoint selection:

```dart
enum LatencyTestProfile { standard, androidConnection, v2boxConnection }

static const String androidDefaultProbeUrl =
    'https://cp.cloudflare.com/generate_204';

if (profile == LatencyTestProfile.androidConnection) {
  if (configured.isEmpty || builtInProbeUrls.contains(configured)) {
    return const [androidDefaultProbeUrl];
  }
  return [configured];
}
```

Treat both connection profiles as `v2boxConnectionTimeoutMs`, and select
`androidConnection` before the general Windows/macOS branch:

```dart
if (!isWeb && isAndroid) return LatencyTestProfile.androidConnection;
if (!isWeb && (isWindows || isMacOS)) {
  return LatencyTestProfile.v2boxConnection;
}
return LatencyTestProfile.standard;
```

- [ ] **Step 4: Run the policy test and verify green**

Run:

```bash
flutter test test/core/singbox/latency_test_policy_test.dart
```

Expected: all policy tests pass.

- [ ] **Step 5: Commit policy implementation**

```bash
git add lib/core/singbox/latency_test_policy.dart \
  test/core/singbox/latency_test_policy_test.dart
git commit -m "fix: use Anycast target for Android latency"
```

### Task 2: Regression, build, and device verification

**Files:**
- Verify: `lib/core/singbox/android_latency_config.dart`
- Verify: `android/app/src/main/kotlin/com/elephantroute/AndroidConnectionProbeManager.kt`
- Artifact: `build/app/outputs/flutter-apk/app-debug.apk`

- [ ] **Step 1: Run focused latency tests**

```bash
flutter test \
  test/core/singbox/latency_test_policy_test.dart \
  test/core/singbox/android_latency_config_test.dart \
  test/core/singbox/android_connection_probe_test.dart \
  test/core/singbox/android_latency_session_test.dart \
  test/providers/node_provider_latency_test.dart \
  test/utils/node_latency_display_test.dart
```

Expected: all tests pass.

- [ ] **Step 2: Run static analysis and diff checks**

```bash
dart format --output=none --set-exit-if-changed \
  lib/core/singbox/latency_test_policy.dart \
  test/core/singbox/latency_test_policy_test.dart
git diff --check
flutter analyze
```

Expected: formatting is unchanged, diff check is silent, and analysis reports
`No issues found!`.

- [ ] **Step 3: Build and install the final debug APK**

```bash
flutter build apk --debug
adb install -r build/app/outputs/flutter-apk/app-debug.apk
```

Expected: the APK builds and ADB reports `Success`.

- [ ] **Step 4: Verify the original device symptom**

Reconnect the VPN, trigger Android node latency testing, and inspect:

```bash
adb logcat -d -v threadtime | rg \
  'SPEED_TEST_NATIVE|cp.cloudflare.com|resolve\(ipv4_only\)'
```

Acceptance evidence:

- requests target `cp.cloudflare.com` and return HTTP 204;
- successful probes report `connections=1 reused=true`;
- hidden worker routes still report `resolve(ipv4_only)`;
- representative Silicon Valley and Singapore results no longer show the
  gstatic China-CDN hairpin range measured before the change.

- [ ] **Step 5: Confirm repository state and artifact digest**

```bash
git status --short --branch
shasum -a 256 build/app/outputs/flutter-apk/app-debug.apk
```

Expected: no uncommitted implementation files remain, and an exact APK digest
is available for handoff.
