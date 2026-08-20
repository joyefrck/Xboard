# macOS Latency Target and AnyTLS Repair Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eliminate target-specific macOS false failures and reduce AnyTLS cold URLTest inflation while keeping the app at `1.6.5+10605`.

**Architecture:** Resolve built-in macOS delay checks to an ordered Cloudflare/GStatic list and consume that list within one per-node timeout budget. Replace the arm64 embedded core with a reproducible sing-box `v1.13.15` build carrying upstream merged PR #4376, while retaining explicit provenance and checksum files.

**Tech Stack:** Flutter/Dart, Dio 5, Flutter test, sing-box/Go, macOS arm64, shell release tooling

---

### Task 1: Add ordered macOS probe targets

**Files:**
- Modify: `lib/core/singbox/latency_test_policy.dart`
- Modify: `test/core/singbox/latency_test_policy_test.dart`

- [ ] **Step 1: Write failing policy tests**

Add assertions that the built-in Cloudflare URL resolves to Cloudflare then
GStatic, while a custom URL resolves only to itself.

- [ ] **Step 2: Run the focused test and verify failure**

Run: `flutter test --no-pub test/core/singbox/latency_test_policy_test.dart`

Expected: FAIL because the macOS ordered-target resolver does not exist.

- [ ] **Step 3: Implement the ordered-target resolver**

Add `macosConnectionFallbackProbeUrl` and
`macosConnectionProbeUrls(String requestedUrl)`. Deduplicate the fallback and
preserve custom URLs.

- [ ] **Step 4: Format and rerun the focused policy test**

Run: `dart format lib/core/singbox/latency_test_policy.dart test/core/singbox/latency_test_policy_test.dart && flutter test --no-pub test/core/singbox/latency_test_policy_test.dart`

Expected: PASS.

### Task 2: Consume multiple targets within one node budget

**Files:**
- Modify: `lib/core/singbox/macos_latency_fallback.dart`
- Modify: `lib/core/singbox/macos_vpn_service.dart`
- Modify: `test/core/singbox/macos_latency_fallback_test.dart`
- Modify: `test/core/singbox/macos_vpn_service_latency_test.dart`

- [ ] **Step 1: Write failing fallback tests**

Cover first-target success, Cloudflare 503 followed by GStatic success, all
targets failing, timeout-budget division, and cancellation between targets.

- [ ] **Step 2: Run the focused tests and verify failure**

Run: `flutter test --no-pub test/core/singbox/macos_latency_fallback_test.dart test/core/singbox/macos_vpn_service_latency_test.dart`

Expected: FAIL because `resolve` accepts only one `testUrl` and retries it.

- [ ] **Step 3: Implement ordered fallback**

Change the runner input to `List<String> testUrls`, calculate a per-target
budget from the existing node timeout, stop on the first success, and publish
only the final node result. Change the service to call the policy resolver.

- [ ] **Step 4: Format and rerun focused latency tests**

Run: `dart format lib/core/singbox/macos_latency_fallback.dart lib/core/singbox/macos_vpn_service.dart test/core/singbox/macos_latency_fallback_test.dart test/core/singbox/macos_vpn_service_latency_test.dart && flutter test --no-pub test/core/singbox/macos_latency_fallback_test.dart test/core/singbox/macos_vpn_service_latency_test.dart test/core/singbox/latency_test_policy_test.dart`

Expected: PASS.

### Task 3: Build and embed the patched stable arm64 core

**Files:**
- Modify: `assets/bin/sing-box-darwin-arm64`
- Modify: `assets/bin/sing-box-darwin-arm64.version`
- Modify: `assets/bin/sing-box-darwin-arm64.sha256`
- Create: `assets/bin/sing-box-darwin-arm64.provenance`
- Modify: `lib/core/singbox/macos_singbox_runtime.dart`
- Modify: `test/core/singbox/macos_singbox_runtime_test.dart`
- Modify: `test/macos_distribution_contract_test.dart`

- [ ] **Step 1: Add failing version and provenance contracts**

Require runtime version `1.13.15-xboard.1`, a matching asset marker, SHA-256,
arm64 Mach-O, required feature tags, base tag `v1.13.15`, and upstream patch
commit `f043ec560f9ecd61f34e1af7a583e81e480f10c1`.

- [ ] **Step 2: Run the focused contracts and verify failure**

Run: `flutter test --no-pub test/core/singbox/macos_singbox_runtime_test.dart test/macos_distribution_contract_test.dart`

Expected: FAIL against the current `1.12.25` asset.

- [ ] **Step 3: Build the core from official source plus the merged patch**

Check out `v1.13.15`, apply upstream commit `f043ec560f9ecd61f34e1af7a583e81e480f10c1`, and build with `CGO_ENABLED=0`, `GOOS=darwin`, `GOARCH=arm64`, official default build tags, `-trimpath`, and version `1.13.15-xboard.1`.

- [ ] **Step 4: Replace the asset and record provenance**

Install the binary at `assets/bin/sing-box-darwin-arm64`, write the version and
SHA-256 marker files, and record the exact source/build metadata in the
provenance file.

- [ ] **Step 5: Validate core and current config compatibility**

Run the embedded binary's `version`, verify Mach-O architecture and checksum,
and execute `check -c` on the current installed sanitized configuration with
the app compatibility environment. Do not print configuration contents.

- [ ] **Step 6: Rerun core and distribution contracts**

Run: `flutter test --no-pub test/core/singbox/macos_singbox_runtime_test.dart test/macos_distribution_contract_test.dart`

Expected: PASS.

### Task 4: Verify the application and build 1.6.5

**Files:**
- Verify all modified files and generated release artifacts.

- [ ] **Step 1: Run formatting and focused tests**

Run all latency, runtime, lifecycle, provider, and macOS distribution tests.

- [ ] **Step 2: Run static analysis and full Flutter tests**

Run: `flutter analyze && flutter test --no-pub`

Expected: exit 0 with no failures.

- [ ] **Step 3: Build the arm64 DMG without changing the app version**

Run: `MACOS_BUILD_NAME=1.6.5 MACOS_BUILD_NUMBER=10605 ./build_macos_beta.sh`

Expected: an arm64 DMG with ad-hoc signing and a successful launch smoke test.

- [ ] **Step 4: Validate the mounted DMG**

Verify `CFBundleShortVersionString=1.6.5`, `CFBundleVersion=10605`, all Mach-O
files are arm64, the embedded core version/checksum is correct, the deep code
signature passes, and record the final DMG SHA-256.

- [ ] **Step 5: Review final diff and repository state**

Run: `git diff --check && git status --short && git diff --stat`

Expected: only intended source, test, documentation, core asset, and checksum
changes are present.
