# Windows Reconnect Diagnostics Fix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop sing-box 1.12 deprecation warnings from masking the actual Windows reconnect startup failure, while preserving genuine configuration, TUN, process-exit, and timeout classifications.

**Architecture:** Keep the existing pure C++ startup classifier and service/Flutter contracts unchanged. Lock the warning-only and mixed-warning behavior with native tests, remove only the broad `deprecated` signature, then validate the complete Windows build and installer through GitHub Actions.

**Tech Stack:** C++17, Clang, CMake/CTest, Flutter/Dart, GitHub Actions, Inno Setup.

---

### Task 1: Lock the reconnect warning behavior with failing native tests

**Files:**
- Modify: `windows/tests/windows_core_diagnostics_test.cpp`
- Test: `windows/tests/windows_core_diagnostics_test.cpp`

- [x] **Step 1: Add warning-only and mixed-warning regression cases**

Insert these cases after the existing `invalid_config` assertion:

```cpp
  constexpr auto deprecation_warnings =
      "WARN legacy DNS servers is deprecated in sing-box 1.12.0\n"
      "WARN legacy special outbounds is deprecated in sing-box 1.11.0\n"
      "WARN missing route.default_domain_resolver is deprecated";

  const auto warning_timeout = elephant::ClassifyCoreStartFailure(
      deprecation_warnings, false, 0);
  assert(warning_timeout.code == "control_api_timeout");

  const auto warning_exit = elephant::ClassifyCoreStartFailure(
      deprecation_warnings, true, 9);
  assert(warning_exit.code == "core_exited");
  assert(warning_exit.exit_code.has_value());
  assert(*warning_exit.exit_code == 9);

  const auto warning_then_tun_failure = elephant::ClassifyCoreStartFailure(
      std::string(deprecation_warnings) +
          "\nFATAL create TUN interface: device is already in use",
      true, 1);
  assert(warning_then_tun_failure.code == "tun_start_failed");
```

Add `#include <string>` beside the existing standard library includes.

- [x] **Step 2: Compile and run the focused native test to verify red**

Run:

```bash
test_bin="$(mktemp /tmp/windows-core-diagnostics-test.XXXXXX)"
clang++ -std=c++17 -Wall -Wextra -Werror \
  -I windows/common \
  windows/common/windows_core_diagnostics.cpp \
  windows/tests/windows_core_diagnostics_test.cpp \
  -o "$test_bin"
"$test_bin"
```

Expected: the process aborts on
`warning_timeout.code == "control_api_timeout"` because the current
implementation returns `core_config_invalid`.

### Task 2: Implement the minimal classifier correction

**Files:**
- Modify: `windows/common/windows_core_diagnostics.cpp:54-55`
- Test: `windows/tests/windows_core_diagnostics_test.cpp`

- [x] **Step 1: Remove the broad deprecation signature**

Change the explicit configuration failure list to:

```cpp
  if (ContainsAny(log, {"decode config", "parse config", "invalid config",
                        "unknown field"})) {
```

Do not change the classifier ordering, public error codes, messages, service
deadline, TUN configuration, or compatibility environment variables.

- [x] **Step 2: Compile and run the focused native test to verify green**

Run the same `clang++` command from Task 1.

Expected:

```text
windows_core_diagnostics_test passed
```

- [x] **Step 3: Run the existing focused Flutter protocol test**

Run:

```bash
flutter test --no-pub test/core/singbox/windows_service_protocol_test.dart
```

Expected: all protocol mapping tests pass, confirming no Dart contract change.

- [x] **Step 4: Commit the implementation**

Run:

```bash
git add windows/common/windows_core_diagnostics.cpp \
  windows/tests/windows_core_diagnostics_test.cpp
git commit -m "fix: preserve Windows reconnect failure diagnostics"
```

### Task 3: Run complete local verification

**Files:**
- Verify: `windows/common/windows_core_diagnostics.cpp`
- Verify: `windows/tests/windows_core_diagnostics_test.cpp`

- [x] **Step 1: Run static analysis**

Run:

```bash
flutter analyze --no-pub
```

Expected: `No issues found!`

- [x] **Step 2: Run the full Flutter suite**

Run:

```bash
flutter test --no-pub
```

Expected: all non-skipped tests pass.

- [x] **Step 3: Run native diagnostics and patch checks**

Run:

```bash
test_bin="$(mktemp /tmp/windows-core-diagnostics-test.XXXXXX)"
clang++ -std=c++17 -Wall -Wextra -Werror \
  -I windows/common \
  windows/common/windows_core_diagnostics.cpp \
  windows/tests/windows_core_diagnostics_test.cpp \
  -o "$test_bin"
"$test_bin"
git diff --check HEAD^
git status --short
```

Expected: the native test prints
`windows_core_diagnostics_test passed`, `git diff --check` prints nothing, and
the only pending tracked file is this implementation plan until it is committed.

- [x] **Step 4: Commit the implementation plan**

Run from the repository root:

```bash
git add clients/elephant-route-deprecated/docs/superpowers/plans/2026-07-27-windows-reconnect-diagnostics.md
git commit -m "docs: plan Windows reconnect diagnostics fix"
```

### Task 4: Build and verify the Windows installer

**Files:**
- Workflow: `.github/workflows/windows-client.yml`
- Artifact: `clients/elephant-route-deprecated/build/releases/windows/1.6.3-reconnect-fix/ElephantNetwork-Setup-x64-v1.6.3.exe`

- [x] **Step 1: Push the current master commits**

Run:

```bash
git push origin master
```

Expected: `origin/master` advances to the local implementation-plan commit.

- [x] **Step 2: Dispatch the versioned Windows workflow**

Run:

```bash
gh workflow run windows-client.yml \
  --repo joyefrck/Xboard \
  --ref master \
  -f version=1.6.3 \
  -f build_number=10603
```

Expected: GitHub returns a new workflow URL.

- [x] **Step 3: Wait for all Windows-native gates**

Run:

```bash
run_id="$(
  gh run list \
    --repo joyefrck/Xboard \
    --workflow windows-client.yml \
    --event workflow_dispatch \
    --limit 1 \
    --json databaseId \
    --jq '.[0].databaseId'
)"
gh run watch "$run_id" --repo joyefrck/Xboard --exit-status
```

Expected: analyzer, Flutter tests, Windows release build, two native C++ tests,
Inno Setup packaging, install/service/uninstall smoke testing, and artifact
upload all succeed.

- [x] **Step 4: Download and hash the installer**

Run:

```bash
artifact_dir="clients/elephant-route-deprecated/build/releases/windows/1.6.3-reconnect-fix"
mkdir -p "$artifact_dir"
gh run download "$run_id" \
  --repo joyefrck/Xboard \
  --name ElephantNetwork-Windows-x64-1.6.3 \
  --dir "$artifact_dir"
file "$artifact_dir/ElephantNetwork-Setup-x64-v1.6.3.exe"
shasum -a 256 "$artifact_dir/ElephantNetwork-Setup-x64-v1.6.3.exe"
```

Expected: the artifact is a Windows PE executable and its local SHA-256 matches
the hash printed by `build_windows_release.ps1` in the workflow log.
