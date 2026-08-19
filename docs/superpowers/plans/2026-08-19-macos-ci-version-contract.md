# macOS CI Version Contract Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore the macOS ARM GitHub Actions package job by supplying its required independent version metadata and consuming the resulting versioned DMG consistently.

**Architecture:** Keep validation and package naming in `build_macos_beta.sh`. Make `.github/workflows/macos-client.yml` the single CI source for the independent macOS version, while a focused Dart source-contract test prevents the workflow and script from drifting again.

**Tech Stack:** GitHub Actions YAML, Bash, Flutter/Dart tests

---

### Task 1: Add a failing workflow contract test

**Files:**
- Modify: `clients/elephant-route-deprecated/test/macos_distribution_contract_test.dart`

- [ ] **Step 1: Assert the CI version contract**

Read `../../.github/workflows/macos-client.yml` and assert it contains workflow-level `MACOS_BUILD_NAME: '1.6.4'`, `MACOS_BUILD_NUMBER: '10604'`, the versioned verification path `ElephantRoute-macos-arm64-v${MACOS_BUILD_NAME}.dmg`, and the matching versioned upload path.

- [ ] **Step 2: Confirm the test fails before implementation**

Run: `flutter test --no-pub test/macos_distribution_contract_test.dart`

Expected: FAIL because the workflow currently supplies neither version variable and still references the unversioned DMG.

### Task 2: Repair and verify the macOS workflow

**Files:**
- Modify: `.github/workflows/macos-client.yml`
- Test: `clients/elephant-route-deprecated/test/macos_distribution_contract_test.dart`

- [ ] **Step 1: Define the independent macOS version once**

Add this workflow-level block after `permissions`:

```yaml
env:
  MACOS_BUILD_NAME: '1.6.4'
  MACOS_BUILD_NUMBER: '10604'
```

- [ ] **Step 2: Consume the versioned DMG path**

Change the shell verification variable to:

```text
build/macos-beta/ElephantRoute-macos-arm64-v${MACOS_BUILD_NAME}.dmg
```

Change the `actions/upload-artifact` path to the GitHub expression form:

```text
clients/elephant-route-deprecated/build/macos-beta/ElephantRoute-macos-arm64-v${{ env.MACOS_BUILD_NAME }}.dmg
```

- [ ] **Step 3: Run focused verification**

Run from `clients/elephant-route-deprecated`:

```bash
flutter test --no-pub test/macos_distribution_contract_test.dart
bash -n build_macos_beta.sh
```

Parse `.github/workflows/macos-client.yml` with the available Ruby or Python YAML parser. Invoke `build_macos_beta.sh` with both version variables unset and confirm it exits nonzero with `MACOS_BUILD_NAME is required` before performing a build.

Expected: the focused tests and syntax checks pass, while the deliberate missing-version invocation fails at the intended guard.

- [ ] **Step 4: Commit and push**

Stage only the spec, plan, workflow, and focused test. Commit with `fix: pass macOS version to CI package build`, push `master`, then monitor the newly triggered macOS workflow through completion.
