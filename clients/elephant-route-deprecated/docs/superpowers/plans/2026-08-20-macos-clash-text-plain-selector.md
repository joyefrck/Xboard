# macOS Clash Selector Text Response Compatibility Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make macOS node hot-switching accept valid Clash API JSON bodies served with a `text/plain` content type without changing the tunnel lifecycle.

**Architecture:** Normalize Clash API bodies at the `MacosClashController` boundary. Tests reproduce the bundled core's real response headers, then the controller decodes JSON strings before the existing selector, connection, and delay field validation runs.

**Tech Stack:** Dart, Flutter Test, Dio, sing-box Clash API, macOS arm64 release script

---

### Task 1: Reproduce the live response contract

**Files:**
- Modify: `clients/elephant-route-deprecated/test/core/singbox/macos_clash_controller_test.dart`

- [ ] **Step 1: Add a test adapter content-type option**

Extend `_RecordingAdapter` with a `contentType` parameter defaulting to
`Headers.jsonContentType`, and return that value from `fetch`:

```dart
_RecordingAdapter({
  required this.statusCode,
  this.responseData,
  this.contentType = Headers.jsonContentType,
});

final String contentType;

headers: {
  Headers.contentTypeHeader: [contentType],
},
```

- [ ] **Step 2: Add the failing selector test**

```dart
test('reads a selector JSON object served as text/plain', () async {
  final dio = Dio(BaseOptions(baseUrl: 'http://127.0.0.1:9090'))
    ..httpClientAdapter = _RecordingAdapter(
      statusCode: 200,
      contentType: Headers.textPlainContentType,
      responseData: const {
        'name': '节点选择',
        'type': 'Selector',
        'now': '香港',
      },
    );

  final selected = await MacosClashController(dio)
      .selectedOutbound('节点选择');

  expect(selected, '香港');
});
```

- [ ] **Step 3: Run the focused test and confirm the reproduction fails**

Run:

```bash
cd clients/elephant-route-deprecated
flutter test --no-pub test/core/singbox/macos_clash_controller_test.dart \
  --plain-name 'reads a selector JSON object served as text/plain'
```

Expected: FAIL with
`Clash API selector response has no active outbound`.

### Task 2: Normalize JSON string bodies at the Clash boundary

**Files:**
- Modify: `clients/elephant-route-deprecated/lib/core/singbox/macos_clash_controller.dart`
- Modify: `clients/elephant-route-deprecated/test/core/singbox/macos_clash_controller_test.dart`

- [ ] **Step 1: Import JSON support and add the normalizer**

```dart
import 'dart:convert';

dynamic _decodedClashBody(dynamic data) {
  if (data is! String) return data;
  return jsonDecode(data);
}
```

- [ ] **Step 2: Normalize selector, connection, and delay responses**

Replace each direct `response.data` field read in `selectedOutbound`,
`activeConnections`, and `urlTest` with:

```dart
final data = _decodedClashBody(response.data);
```

Do not change existing missing-field, HTTP, or transport error behavior.

- [ ] **Step 3: Add text/plain coverage for connections and delay**

Add tests using `Headers.textPlainContentType` that assert:

```dart
expect((await controller.activeConnections()).single.id, 'old-1');
expect(await controller.urlTest('香港'), 46);
```

The response bodies must remain JSON objects encoded by `_RecordingAdapter` so
Dio exposes them as strings under the text content type.

- [ ] **Step 4: Run the controller and switch coordinator suites**

Run:

```bash
cd clients/elephant-route-deprecated
flutter test --no-pub \
  test/core/singbox/macos_clash_controller_test.dart \
  test/core/singbox/macos_outbound_switch_coordinator_test.dart
```

Expected: all tests pass.

- [ ] **Step 5: Commit the focused repair**

```bash
git add \
  clients/elephant-route-deprecated/lib/core/singbox/macos_clash_controller.dart \
  clients/elephant-route-deprecated/test/core/singbox/macos_clash_controller_test.dart
git commit -m "fix: parse macOS Clash text responses"
```

### Task 3: Run regression verification and package macOS 1.6.5

**Files:**
- Verify: `clients/elephant-route-deprecated/lib/core/singbox/macos_clash_controller.dart`
- Verify: `clients/elephant-route-deprecated/test/core/singbox/macos_clash_controller_test.dart`
- Produce: `clients/elephant-route-deprecated/build/macos-beta/ElephantRoute-macos-arm64-v1.6.5.dmg`

- [ ] **Step 1: Run formatting and static analysis**

```bash
cd clients/elephant-route-deprecated
dart format --output=none --set-exit-if-changed \
  lib/core/singbox/macos_clash_controller.dart \
  test/core/singbox/macos_clash_controller_test.dart
flutter analyze --no-pub
```

Expected: formatting exits 0 and analyzer reports no issues.

- [ ] **Step 2: Run the full Flutter suite**

```bash
cd clients/elephant-route-deprecated
flutter test --no-pub
```

Expected: all platform-applicable tests pass; platform-specific skips are
reported separately.

- [ ] **Step 3: Build the requested macOS release**

```bash
cd clients/elephant-route-deprecated
MACOS_BUILD_NAME=1.6.5 MACOS_BUILD_NUMBER=10605 ./build_macos_beta.sh
```

Expected: `build/macos-beta/ElephantRoute-macos-arm64-v1.6.5.dmg` exists.

- [ ] **Step 4: Validate the DMG and record its digest**

Mount the DMG read-only, verify `大象网络.app`, both arm64 executables, the
bundled arm64 sing-box, helper resources, and the code signature. Launch the
mounted application long enough to confirm the process remains alive, detach
the image, then run:

```bash
shasum -a 256 \
  build/macos-beta/ElephantRoute-macos-arm64-v1.6.5.dmg
```

Expected: every release-contract check succeeds and a SHA-256 digest is
printed.

- [ ] **Step 5: Verify repository integrity and publish the commits**

```bash
git diff --check
git status --short
git push origin master
```

Expected: no unstaged source changes remain and `origin/master` reaches the
final repair commit. Because this change is client-only, do not restart the
production Xboard web or Horizon services.

