# macOS Latency Physical Egress Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prevent the macOS isolated latency core from re-entering the active TUN so node delay matches the single-hop Android and Shadowrocket-style path.

**Architecture:** A focused resolver reads the physical interface from the system default route. `MacosLatencySession` resolves it before creating any temporary core process, and `MacosLatencyConfigBuilder` pins the temporary core to that interface with automatic interface detection disabled; failures reuse the existing live-core fallback path.

**Tech Stack:** Flutter/Dart, sing-box JSON configuration, macOS `/sbin/route`, Flutter test, shell release validation

---

### Task 1: Resolve the physical default interface

**Files:**
- Create: `clients/elephant-route-deprecated/lib/core/singbox/macos_physical_interface_resolver.dart`
- Create: `clients/elephant-route-deprecated/test/core/singbox/macos_physical_interface_resolver_test.dart`

- [ ] **Step 1: Write failing parser and command-result tests**

Cover `interface: en0`, whitespace, missing interface, `utun7`, illegal interface characters, non-zero exit, and command exceptions. Inject the command runner so tests never inspect the developer machine.

- [ ] **Step 2: Run the focused test and confirm it fails**

Run: `flutter test --no-pub test/core/singbox/macos_physical_interface_resolver_test.dart`

Expected: FAIL because `MacosPhysicalInterfaceResolver` does not exist.

- [ ] **Step 3: Implement the resolver**

Expose a callable resolver returning `Future<String?>`. Run `/sbin/route -n get -inet default`, accept only exit code 0, parse the `interface:` line, and reject empty, `utun*`, or values outside `[A-Za-z0-9._-]+`.

- [ ] **Step 4: Run the focused test and confirm it passes**

Run the command from Step 2.

Expected: all resolver tests PASS.

- [ ] **Step 5: Commit**

```bash
git add clients/elephant-route-deprecated/lib/core/singbox/macos_physical_interface_resolver.dart clients/elephant-route-deprecated/test/core/singbox/macos_physical_interface_resolver_test.dart
git commit -m "feat: resolve macOS latency physical interface"
```

### Task 2: Pin the isolated sing-box configuration

**Files:**
- Modify: `clients/elephant-route-deprecated/lib/core/singbox/macos_latency_config.dart`
- Modify: `clients/elephant-route-deprecated/test/core/singbox/macos_latency_config_test.dart`

- [ ] **Step 1: Add failing configuration assertions**

Pass `defaultInterface: 'en0'` to every builder call and assert:

```dart
expect(output['route']['auto_detect_interface'], isFalse);
expect(output['route']['default_interface'], 'en0');
```

Also assert empty and `utun` values are rejected.

- [ ] **Step 2: Run the configuration test and confirm it fails**

Run: `flutter test --no-pub test/core/singbox/macos_latency_config_test.dart`

Expected: FAIL because the argument and route overrides are absent.

- [ ] **Step 3: Implement the configuration contract**

Add required `defaultInterface`, validate it, set `route['auto_detect_interface'] = false`, and set `route['default_interface'] = defaultInterface` without changing worker rules or final routing.

- [ ] **Step 4: Run the configuration test and confirm it passes**

Run the command from Step 2.

Expected: all configuration tests PASS.

- [ ] **Step 5: Commit**

```bash
git add clients/elephant-route-deprecated/lib/core/singbox/macos_latency_config.dart clients/elephant-route-deprecated/test/core/singbox/macos_latency_config_test.dart
git commit -m "fix: pin macOS latency core to physical egress"
```

### Task 3: Integrate resolution into the session lifecycle

**Files:**
- Modify: `clients/elephant-route-deprecated/lib/core/singbox/macos_latency_session.dart`
- Modify: `clients/elephant-route-deprecated/test/core/singbox/macos_latency_session_test.dart`

- [ ] **Step 1: Add failing lifecycle tests**

Inject a resolver returning `en0`, capture the generated config in the validator, and assert the route is pinned before process start. Add a resolver-null case asserting `MacosLatencyException` and zero process starts.

- [ ] **Step 2: Run the session test and confirm it fails**

Run: `flutter test --no-pub test/core/singbox/macos_latency_session_test.dart`

Expected: FAIL because session resolution is not wired.

- [ ] **Step 3: Implement session integration**

Add the resolver dependency with the production default. Resolve before writing the temporary configuration; when unavailable, throw `MacosLatencyException('无法识别测速物理网络接口')`. Pass the confirmed value to the builder. Do not start sing-box on failure.

- [ ] **Step 4: Run session and service latency tests**

Run:

```bash
flutter test --no-pub test/core/singbox/macos_latency_session_test.dart test/core/singbox/macos_vpn_service_latency_test.dart
```

Expected: all tests PASS and existing fallback behavior remains intact.

- [ ] **Step 5: Commit**

```bash
git add clients/elephant-route-deprecated/lib/core/singbox/macos_latency_session.dart clients/elephant-route-deprecated/test/core/singbox/macos_latency_session_test.dart
git commit -m "fix: bypass active TUN during macOS latency tests"
```

### Task 4: Verify and release macOS 1.6.5

**Files:**
- Verify: `clients/elephant-route-deprecated`
- Build: `clients/elephant-route-deprecated/build/macos-beta/ElephantRoute-macos-arm64-v1.6.5.dmg`

- [ ] **Step 1: Run formatting and static analysis**

```bash
dart format --set-exit-if-changed lib/core/singbox/macos_physical_interface_resolver.dart lib/core/singbox/macos_latency_config.dart lib/core/singbox/macos_latency_session.dart test/core/singbox/macos_physical_interface_resolver_test.dart test/core/singbox/macos_latency_config_test.dart test/core/singbox/macos_latency_session_test.dart
flutter analyze --no-pub
```

Expected: no formatting changes remain and no analysis issues.

- [ ] **Step 2: Run the complete test suite**

Run: `flutter test --no-pub`

Expected: all non-skipped tests PASS.

- [ ] **Step 3: Build the version-pinned DMG**

Run:

```bash
MACOS_BUILD_NAME=1.6.5 MACOS_BUILD_NUMBER=10605 ./build_macos_beta.sh
```

Expected: arm64 DMG is produced at the stable v1.6.5 path.

- [ ] **Step 4: Validate the artifact**

Verify `CFBundleShortVersionString=1.6.5`, `CFBundleVersion=10605`, arm64 Mach-O slices, code signatures, `hdiutil verify`, mount contents, and SHA-256.

- [ ] **Step 5: Push and verify CI**

Push current `master`, confirm the remote SHA, and require the macOS, Windows, and Docker workflows for the release SHA to finish successfully before reporting completion.

