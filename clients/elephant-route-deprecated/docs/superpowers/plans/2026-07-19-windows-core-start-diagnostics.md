# Windows Core Startup Diagnostics Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the generic Windows `sing-box control API did not become ready` failure with safe, actionable diagnostics for port conflicts, configuration rejection, TUN startup failures, early process exits, and readiness timeouts.

**Architecture:** Keep the privileged service as the source of truth. Add a small pure C++ classifier for log-derived startup failures, perform a read-only TCP listener preflight before launching the core, and return stable error codes plus safe messages through the existing IPC map. The Flutter layer maps the new codes to existing failure categories; no configuration contents or node credentials cross the service boundary.

**Tech Stack:** C++17, Win32 IP Helper API, Flutter/Dart tests, CMake/CTest.

---

### Task 1: Lock the diagnostic contract with failing tests

**Files:**
- Create: `windows/common/windows_core_diagnostics.h`
- Create: `windows/common/windows_core_diagnostics.cpp`
- Create: `windows/tests/windows_core_diagnostics_test.cpp`
- Modify: `windows/tests/CMakeLists.txt`
- Modify: `test/core/singbox/windows_service_protocol_test.dart`

- [x] Add C++ cases for control-port bind errors, configuration rejection, TUN failures, empty-log early exits, and live-process timeouts.
- [x] Add Dart cases mapping `control_port_in_use`, `core_config_invalid`, `tun_start_failed`, `core_blocked_or_crashed`, and `control_api_timeout` to stable `VpnFailureReason` values.
- [x] Run the focused Flutter test and verify the new mappings fail before implementation.

### Task 2: Implement native startup diagnostics

**Files:**
- Modify: `windows/service/service_main.cpp`
- Modify: `windows/service/CMakeLists.txt`
- Modify: `windows/common/windows_core_diagnostics.h`
- Modify: `windows/common/windows_core_diagnostics.cpp`

- [x] Query the Windows TCP owner table after stopping the managed core and before launching a new one; return `control_port_in_use` with the owning PID when port 9090 is already listening.
- [x] Replace the boolean readiness result with a result that distinguishes ready, process exited, missing interface, and timeout, preserving the process exit code when available.
- [x] Read only newly appended log bytes with a fixed size cap and classify them without returning raw configuration or log contents.
- [x] Return stable diagnostic codes and safe actionable messages through the existing `error_code` and `error_message` fields.

### Task 3: Bridge, document, and verify

**Files:**
- Modify: `lib/core/singbox/windows_service_protocol.dart`
- Modify: `windows/runner/windows_service_bridge.cpp`
- Modify: `docs/windows-release.md`
- Modify: `test/windows_distribution_contract_test.dart`

- [x] Map the new native codes to existing Flutter failure categories and preserve optional `core_exit_code` in runtime details.
- [x] Document the PowerShell log, port-owner, service-state, and safe `sing-box check` commands; explicitly warn users not to share `config.json`.
- [x] Run `flutter analyze`, focused Windows protocol/distribution tests, and all Flutter tests available on macOS.
- [x] Leave Windows-native CMake/installer/runtime validation as an explicit Windows CI or real Win10 gate.
