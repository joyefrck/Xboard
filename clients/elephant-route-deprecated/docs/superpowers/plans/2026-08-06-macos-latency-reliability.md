# macOS Latency Reliability Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eliminate batch-level false failures and inflated default latency results in the macOS client.

**Architecture:** Select the active or refreshed macOS source config by requested-node coverage, isolate unavailable tags from the valid probe batch, and keep fallback limited to nodes that exist in the selected config. Align the macOS hidden-worker DNS strategy and default endpoint with the proven Android connection probe without changing Windows.

**Tech Stack:** Flutter, Dart, sing-box JSON configuration, curl, `flutter_test`, macOS release build

---

### Task 1: Select a valid macOS latency source without batch failure

**Files:**
- Create: `lib/core/singbox/macos_latency_source_selector.dart`
- Create: `test/core/singbox/macos_latency_source_selector_test.dart`
- Modify: `lib/core/singbox/macos_latency_config.dart`
- Modify: `lib/core/singbox/macos_vpn_service.dart`
- Modify: `test/core/singbox/macos_latency_config_test.dart`

- [x] Add failing tests proving a refreshed config wins when it covers more
  requested nodes and one missing tag is returned separately from eligible
  tags.
- [x] Run the selector and config tests and confirm the new API is missing.
- [x] Implement concrete-proxy discovery, source selection, cache loading, and
  result merging so unavailable tags cannot abort or enter fallback.
- [x] Run the selector, config, session, fallback, and service tests and confirm
  they pass.

### Task 2: Align macOS routing and endpoint policy with Android

**Files:**
- Modify: `lib/core/singbox/macos_latency_config.dart`
- Modify: `lib/core/singbox/latency_test_policy.dart`
- Modify: `test/core/singbox/macos_latency_config_test.dart`
- Modify: `test/core/singbox/latency_test_policy_test.dart`

- [x] Add failing assertions for a resolve-plus-route rule pair per macOS
  worker, a macOS Cloudflare HTTPS default, preserved custom URLs, and unchanged
  Windows gstatic behavior.
- [x] Run both test files and confirm the assertions fail.
- [x] Add `ipv4_only` worker resolution and a macOS-only connection profile.
- [x] Run the focused policy and config tests and confirm they pass.

### Task 3: Verify the complete macOS path

**Files:**
- Verify: `lib/core/singbox/macos_latency_session.dart`
- Verify: `lib/core/singbox/macos_curl_connection_probe.dart`
- Artifact: `build/macos/Build/Products/Release/大象网络.app`

- [x] Run all macOS latency and node-provider tests.
- [x] Run Dart formatting checks, `git diff --check`, and `flutter analyze`.
- [x] Build the macOS release app.
- [x] Run the updated isolation path locally and verify that a 32-node run reaches the
  primary connection probe, does not emit a batch-level `ArgumentError` or stale
  tag 404 fallback, targets Cloudflare HTTPS, and reports the lower successful
  warm-request latency.
