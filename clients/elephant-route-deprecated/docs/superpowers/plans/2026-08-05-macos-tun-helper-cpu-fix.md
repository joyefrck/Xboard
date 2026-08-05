# macOS TUN Helper CPU Busy-Loop Fix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prevent stale macOS TUN helper output callbacks from consuming CPU and bound helper log growth.

**Architecture:** `TunHelper` will own the active output pipe and clear it through one lock-protected, idempotent cleanup function called from every process-exit path. Existing asynchronous output handling remains in place. Logging will rotate the active file at 10 MB and retain two archives.

**Tech Stack:** Swift/Foundation `Process`, `Pipe`, `FileHandle`, `NSLock`; Flutter/Dart source contract tests; `swiftc` macOS build verification.

---

### Task 1: Add the regression contract

**Files:**
- Modify: `test/core/services/mac_runtime_service_contract_test.dart`

- [x] **Step 1: Add a source contract test**

Add a test that reads `macos/ElephantTunHelper/main.swift` and requires
`coreOutputPipe`, `coreOutputPipeLock`, `cleanupCoreOutputPipe`, EOF handler
unregistration, `terminationHandler`, `maxLogFileSize`, and
`retainedLogArchiveCount`.

- [x] **Step 2: Run the focused test and confirm it fails**

Run:

```bash
flutter test test/core/services/mac_runtime_service_contract_test.dart
```

Expected: the new lifecycle test fails because the helper does not yet own or
clean up its output pipe.

### Task 2: Implement output-pipe lifecycle cleanup

**Files:**
- Modify: `macos/ElephantTunHelper/main.swift:19-149`

- [x] **Step 1: Add explicit pipe state**

Add `coreOutputPipe` and `coreOutputPipeLock` properties. Store the new pipe
before launching the child process.

- [x] **Step 2: Add a unified cleanup function**

Implement `cleanupCoreOutputPipe(_ expectedPipe: Pipe? = nil)`. Under the lock,
clear the active property only when `expectedPipe` is absent or identical to
the active pipe. Outside the lock, unregister `readabilityHandler` and close
both file handles with non-throwing cleanup.

- [x] **Step 3: Connect every exit path**

At EOF, unregister the current handler and clean the matching pipe. Install a
`Process.terminationHandler` that cleans the matching pipe. Also clean the pipe
when `process.run()` throws and after explicit stop has terminated the core.

- [x] **Step 4: Run the focused test**

Run:

```bash
flutter test test/core/services/mac_runtime_service_contract_test.dart
```

Expected: all tests in the file pass.

### Task 3: Bound helper log growth

**Files:**
- Modify: `macos/ElephantTunHelper/main.swift:25-28,323-344`

- [x] **Step 1: Add log limits and serialization**

Add a log lock, a 10 MB maximum active-file size, and an archive count of two.

- [x] **Step 2: Rotate before appending**

Before appending, move `.1` to `.2`, move the active file to `.1`, and recreate
the active log whenever its size is at least the configured limit. Ignore
filesystem rotation errors so diagnostics cannot stop the VPN core.

- [x] **Step 3: Re-run the focused test**

Run:

```bash
flutter test test/core/services/mac_runtime_service_contract_test.dart
```

Expected: all tests pass, including the lifecycle/log contract.

### Task 4: Compile and dynamically verify EOF behavior

**Files:**
- Verify: `macos/ElephantTunHelper/main.swift`

- [x] **Step 1: Compile the helper**

Run:

```bash
tmp_dir="$(mktemp -d)"
xcrun swiftc -target arm64-apple-macos13.0 -O macos/ElephantTunHelper/main.swift -o "${tmp_dir}/ElephantTunHelper"
```

Expected: exit code 0 with no Swift compiler errors.

- [x] **Step 2: Run the focused Flutter contracts again with an exit code**

Run:

```bash
flutter test test/core/services/mac_runtime_service_contract_test.dart
```

Expected: exit code 0 and the reported test count passes.

- [x] **Step 3: Run the EOF comparison harness**

Run a one-second Foundation `Pipe` harness for the old return-only callback and
the new unregister-on-EOF callback.

Expected: the old pattern dispatches many empty callbacks; the new pattern
dispatches exactly one.

- [x] **Step 4: Review the final diff**

Run:

```bash
git diff --check
git diff -- macos/ElephantTunHelper/main.swift test/core/services/mac_runtime_service_contract_test.dart
```

Expected: no whitespace errors and only the planned helper/test changes.
