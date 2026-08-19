# macOS CI Version Contract Design

## Problem

The macOS ARM workflow invokes `build_macos_beta.sh` without the required `MACOS_BUILD_NAME` and `MACOS_BUILD_NUMBER` environment variables. The script therefore exits before compilation. The workflow also verifies and uploads the former unversioned DMG path even though the script now emits a versioned filename.

## Design

Keep macOS release numbering independent from the shared Flutter `pubspec.yaml`, as required by the existing release guide. Define `MACOS_BUILD_NAME=1.6.4` and `MACOS_BUILD_NUMBER=10604` once at workflow scope so the build step inherits them and later steps use the same source of truth.

Update the shell verification path to `ElephantRoute-macos-arm64-v${MACOS_BUILD_NAME}.dmg` and the action upload path to the equivalent GitHub expression `ElephantRoute-macos-arm64-v${{ env.MACOS_BUILD_NAME }}.dmg`. Preserve the script's fail-fast validation so local or future CI callers cannot silently produce an incorrectly versioned package.

## Verification

Extend the macOS distribution contract test to assert the workflow variables, versioned verification path, and versioned upload path. Run the focused Flutter test, parse the workflow as YAML, check shell syntax, and confirm invoking the build script without version variables still fails with the intended message.
