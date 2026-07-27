# Windows 11 Active-Core Latency Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Windows 11 node latency tests use the connected in-process sing-box core instead of a loopback helper process blocked by strict routing.

**Architecture:** Add a focused Dart runner that schedules bounded per-node calls to the existing Windows service `urlTest` method and translates them into connection-latency results. Integrate it into `WindowsVpnService` with generation-based cancellation, preserve the Windows 11 strict-route profile, and update the Windows release metadata to 1.6.5.

**Tech Stack:** Dart/Flutter, Flutter MethodChannel, Go-hosted sing-box Clash API, Flutter tests, GitHub Actions Windows runner, Inno Setup.

---

### Task 1: Specify the active-core latency behavior

**Files:**
- Create: `lib/core/singbox/windows_service_latency_runner.dart`
- Create: `test/core/singbox/windows_service_latency_runner_test.dart`

- [x] **Step 1: Write failing tests**

Test a two-worker queue using an injected `Future<int> Function(String)` probe.
Assert positive delays become successful `clashFallback` results, non-positive
delays become `serviceError`, `TimeoutException` becomes `timeout`, cancellation
prevents stale callbacks, and observed concurrency never exceeds the configured
cap.

- [x] **Step 2: Run the focused test and verify it fails**

Run:

```bash
flutter test test/core/singbox/windows_service_latency_runner_test.dart
```

Expected: failure because `WindowsServiceLatencyRunner` does not exist.

- [x] **Step 3: Implement the minimal runner**

Implement `WindowsServiceLatencyRunner.run` with:

```dart
final workerCount = min(min(4, max(1, concurrency)), nodeTags.length);
```

Each worker claims the next node index, applies `.timeout` using `timeoutMs`,
records a `ConnectionLatencyResult`, and invokes `onResult` only when the
generation cancellation callback remains false. Fill any unfinished nodes with
a cancelled result before returning.

- [x] **Step 4: Run the focused test and verify it passes**

Run:

```bash
flutter test test/core/singbox/windows_service_latency_runner_test.dart
```

Expected: all runner tests pass.

### Task 2: Route Windows latency through the service core

**Files:**
- Modify: `lib/core/singbox/windows_vpn_service.dart`
- Modify: `test/core/singbox/windows_vpn_service_test.dart`

- [x] **Step 1: Add the failing service integration test**

Start `WindowsVpnService` using the mocked method channel, request latency for
two concrete nodes, and assert the channel receives `urlTest` calls containing
those node tags. Assert no standalone-session dependency is needed and each
callback contains the mocked delay.

- [x] **Step 2: Run the focused service test and verify it fails**

Run:

```bash
flutter test test/core/singbox/windows_vpn_service_test.dart
```

Expected: failure because the current implementation tries to start the
standalone Windows latency core.

- [x] **Step 3: Replace helper-session integration**

Remove the `dart:io` and `windows_latency_session.dart` dependencies, replace
`_latencySession` with `_latencyRunGeneration`, and construct
`WindowsServiceLatencyRunner(probe: urlTest)` in
`testConnectionLatencies`. Make `stopConnectionLatencyTest` increment the
generation so queued work is cancelled without stopping the active VPN core.

- [x] **Step 4: Preserve strict-route coverage**

Add a service start test whose mocked network profile returns
`strict_route: true`; decode the submitted configuration and assert the TUN
inbound still contains `strict_route: true`.

- [x] **Step 5: Run focused Windows tests**

Run:

```bash
flutter test \
  test/core/singbox/windows_service_latency_runner_test.dart \
  test/core/singbox/windows_vpn_service_test.dart \
  test/windows_distribution_contract_test.dart
```

Expected: all tests pass.

### Task 3: Update the Windows release contract

**Files:**
- Modify: `pubspec.yaml`
- Modify: `windows/installer/ElephantNetwork.iss`
- Modify: `.github/workflows/windows-client.yml`
- Modify: `docs/windows-release.md`
- Modify: `test/windows_distribution_contract_test.dart`

- [x] **Step 1: Update version defaults**

Set the application version to `1.6.5+10605`, installer defaults to `1.6.5`
and `10605`, and workflow dispatch/environment defaults to the same values.

- [x] **Step 2: Update release documentation and contract assertions**

Document that connected node tests use the in-process core and that the
standalone executable is retained only for offline checks. Update installer
filenames and build commands to 1.6.5, then assert those statements and version
defaults in the distribution contract test.

- [x] **Step 3: Run formatting and static verification**

Run:

```bash
dart format lib/core/singbox/windows_vpn_service.dart \
  lib/core/singbox/windows_service_latency_runner.dart \
  test/core/singbox/windows_vpn_service_test.dart \
  test/core/singbox/windows_service_latency_runner_test.dart
flutter analyze
flutter test --no-pub
(cd windows/service_go && go test ./...)
```

Expected: formatting succeeds and all analyzer/tests pass.

### Task 4: Build and publish the installer

**Files:**
- Generated by CI: `windows/installer/output/ElephantNetwork-Setup-x64-v1.6.5.exe`

- [ ] **Step 1: Commit the scoped fix**

Stage only the approved latency, test, documentation, workflow, and version
files, then commit:

```bash
git commit -m "fix: use active Windows core for node latency"
```

- [ ] **Step 2: Push master and wait for Windows CI**

Push the current `master` branch. Wait for the `Windows client` workflow and
confirm Flutter tests, Go tests, native protocol tests, installer build, smoke
install, service verification, and uninstall verification all pass.

- [ ] **Step 3: Download and verify the installer**

Download the `ElephantNetwork-Windows-x64-1.6.5` artifact, confirm the installer
is named `ElephantNetwork-Setup-x64-v1.6.5.exe`, and record its SHA-256 digest.

- [ ] **Step 4: Report delivery evidence**

Report the commit, workflow run URL/result, installer path, size, and SHA-256.
