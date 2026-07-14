#!/usr/bin/env bash
set -euo pipefail

SING_BOX_VERSION="1.12.25"
SING_BOX_COMMIT="73bfb99ebce7923c485435e4faf8571b412065a9"
PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUTPUT_DIR="$PROJECT_ROOT/android/app/libs"
SDK_DIR="${ANDROID_HOME:-${ANDROID_SDK_ROOT:-$HOME/Library/Android/sdk}}"
NDK_VERSION="${ANDROID_NDK_VERSION:-28.2.13676358}"
JAVA_17_HOME="${JAVA_HOME:-/opt/homebrew/opt/openjdk@17/libexec/openjdk.jdk/Contents/Home}"
BUILD_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/elephant-libbox.XXXXXX")"

cleanup() {
  rm -rf "$BUILD_ROOT"
}
trap cleanup EXIT

if [[ ! -x "$JAVA_17_HOME/bin/java" ]]; then
  echo "OpenJDK 17 not found at $JAVA_17_HOME" >&2
  exit 1
fi
if [[ ! -d "$SDK_DIR/ndk/$NDK_VERSION" ]]; then
  echo "Android NDK $NDK_VERSION not found under $SDK_DIR/ndk" >&2
  exit 1
fi

git clone --depth 1 --branch "v$SING_BOX_VERSION" \
  https://github.com/SagerNet/sing-box.git "$BUILD_ROOT/sing-box"

actual_commit="$(git -C "$BUILD_ROOT/sing-box" rev-parse HEAD)"
if [[ "$actual_commit" != "$SING_BOX_COMMIT" ]]; then
  echo "Unexpected sing-box commit: $actual_commit" >&2
  exit 1
fi

export JAVA_HOME="$JAVA_17_HOME"
export ANDROID_HOME="$SDK_DIR"
export ANDROID_NDK_HOME="$SDK_DIR/ndk/$NDK_VERSION"
export PATH="$JAVA_HOME/bin:$(go env GOPATH)/bin:$PATH"

make -C "$BUILD_ROOT/sing-box" lib_install
make -C "$BUILD_ROOT/sing-box" lib_android

mkdir -p "$OUTPUT_DIR"
cp "$BUILD_ROOT/sing-box/libbox.aar" "$OUTPUT_DIR/libbox.aar"
printf '%s\n' "$SING_BOX_VERSION" > "$OUTPUT_DIR/libbox.aar.version"
(
  cd "$OUTPUT_DIR"
  shasum -a 256 libbox.aar > libbox.aar.sha256
)

"$PROJECT_ROOT/scripts/verify_android_release_contract.sh"

echo "Built sing-box libbox $SING_BOX_VERSION ($SING_BOX_COMMIT)"
echo "Output: $OUTPUT_DIR/libbox.aar"
