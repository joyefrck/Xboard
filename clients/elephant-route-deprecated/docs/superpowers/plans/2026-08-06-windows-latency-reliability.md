# Windows Latency Reliability Repair Implementation Plan

**Goal:** Stop healthy Windows nodes from being falsely failed by the legacy
default probe target and guarantee that a Windows latency job terminates or is
cancelled within a bounded time.

**Architecture:** Keep the existing service-owned sing-box probe. Align the
Windows connection profile with the HTTPS Anycast target already used by
Android/macOS, then add per-call and whole-run deadlines to the Dart service-job
poller. The provider's existing 65-second deadline remains the final UI safety
boundary.

**Tech Stack:** Flutter/Dart, Windows MethodChannel bridge, Go service tests.

---

### Task 1: Lock the Windows endpoint contract

**Files:**
- Modify: `test/core/singbox/latency_test_policy_test.dart`
- Modify: `lib/core/singbox/latency_test_policy.dart`

- [ ] Add failing tests for default/built-in Windows URL normalization and
  custom URL preservation.
- [ ] Run the policy test and confirm the new expectation fails.
- [ ] Map the Windows connection profile to the Cloudflare HTTPS Anycast URL.
- [ ] Run the policy test and confirm it passes.

### Task 2: Lock the Windows job lifecycle

**Files:**
- Modify: `test/core/singbox/windows_latency_job_runner_test.dart`
- Modify: `lib/core/singbox/windows_latency_job_runner.dart`

- [ ] Add a failing test where `getLatencyTest` never returns.
- [ ] Assert the runner sends `cancelLatencyTest`, preserves completed results,
  and returns timeout results for unfinished nodes.
- [ ] Add validated per-call and whole-job timeout configuration.
- [ ] Bound start/get/cancel IPC calls and cancel the native job on timeout.
- [ ] Run the runner tests and confirm they pass.

### Task 3: Focused verification

**Files:**
- Verify all changed source and tests.

- [ ] Format all changed Dart files and run `git diff --check`.
- [ ] Run latency policy, Windows runner, Windows VPN service, provider
  lifecycle, and Windows protocol Flutter tests.
- [ ] Run the complete Go service test suite with `go test ./...`.
- [ ] Run Flutter static analysis on changed production files.
- [ ] Review the final diff and confirm version metadata and connection routing
  are unchanged.
