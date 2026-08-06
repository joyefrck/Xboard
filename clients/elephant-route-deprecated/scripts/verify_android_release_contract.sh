#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
LIBS_DIR="$PROJECT_ROOT/android/app/libs"
AAR="$LIBS_DIR/libbox.aar"
EXPECTED_VERSION="1.12.25"
EXPECTED_COMMIT="73bfb99ebce7923c485435e4faf8571b412065a9"
APK="${1:-}"
EXPECTED_RELEASE_VERSION="${2:-}"
SDK_DIR="${ANDROID_HOME:-${ANDROID_SDK_ROOT:-$HOME/Library/Android/sdk}}"
TEMP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/elephant-android-contract.XXXXXX")"

cleanup() {
  rm -rf "$TEMP_DIR"
}
trap cleanup EXIT

fail() {
  echo "Android release contract failed: $*" >&2
  exit 1
}

[[ -f "$AAR" ]] || fail "libbox.aar is missing"
[[ "$(find "$LIBS_DIR" -maxdepth 1 -type f -name '*.aar' | wc -l | tr -d ' ')" == "1" ]] || \
  fail "exactly one AAR must exist in android/app/libs"
[[ "$(tr -d '[:space:]' < "$AAR.version")" == "$EXPECTED_VERSION" ]] || \
  fail "libbox version is not $EXPECTED_VERSION"
(cd "$LIBS_DIR" && shasum -a 256 -c libbox.aar.sha256)

grep -Fq "SING_BOX_VERSION=\"$EXPECTED_VERSION\"" \
  "$PROJECT_ROOT/scripts/build_android_libbox.sh" || fail "build script version is not pinned"
grep -Fq "SING_BOX_COMMIT=\"$EXPECTED_COMMIT\"" \
  "$PROJECT_ROOT/scripts/build_android_libbox.sh" || fail "build script commit is not pinned"

unzip -q "$AAR" 'jni/arm64-v8a/libbox.so' -d "$TEMP_DIR"
LIBBOX_SO="$TEMP_DIR/jni/arm64-v8a/libbox.so"
[[ -f "$LIBBOX_SO" ]] || fail "AAR does not contain arm64-v8a/libbox.so"
grep -aFqm1 "$EXPECTED_VERSION" "$LIBBOX_SO" || fail "libbox version marker is missing"
grep -aFqm1 'github.com/anytls/sing-anytls' "$LIBBOX_SO" || fail "AnyTLS module is missing"
grep -aFqm1 'with_clash_api' "$LIBBOX_SO" || fail "Clash API build tag is missing"

if [[ -n "$APK" ]]; then
  [[ -f "$APK" ]] || fail "APK does not exist: $APK"
  [[ "$EXPECTED_RELEASE_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || \
    fail "expected release version must be provided with an APK"
  EXPECTED_RELEASE_CODE="$(awk -F. '{ printf "%d%02d%02d", $1, $2, $3 }' \
    <<< "$EXPECTED_RELEASE_VERSION")"
  APK_ENTRIES="$(unzip -Z1 "$APK")"
  grep -Fq 'lib/arm64-v8a/libbox.so' <<< "$APK_ENTRIES" || fail "APK lacks ARM64 libbox"
  if grep -Eq '^lib/(armeabi-v7a|x86|x86_64)/' <<< "$APK_ENTRIES"; then
    fail "APK contains a non-ARM64 native ABI"
  fi
  mkdir -p "$TEMP_DIR/apk"
  unzip -q "$APK" 'lib/arm64-v8a/libbox.so' -d "$TEMP_DIR/apk"
  APK_LIBBOX_SO="$TEMP_DIR/apk/lib/arm64-v8a/libbox.so"
  grep -aFqm1 "$EXPECTED_VERSION" "$APK_LIBBOX_SO" || fail "APK libbox version marker is missing"
  grep -aFqm1 'github.com/anytls/sing-anytls' "$APK_LIBBOX_SO" || fail "APK AnyTLS module is missing"
  grep -aFqm1 'with_clash_api' "$APK_LIBBOX_SO" || fail "APK Clash API build tag is missing"

  STRIP_BIN="$(find "$SDK_DIR/ndk" -path '*/toolchains/llvm/prebuilt/*/bin/llvm-strip' | sort -V | tail -1)"
  [[ -x "$STRIP_BIN" ]] || fail "Android NDK llvm-strip is unavailable"
  cp "$LIBBOX_SO" "$TEMP_DIR/aar-normalized.so"
  cp "$APK_LIBBOX_SO" "$TEMP_DIR/apk-normalized.so"
  "$STRIP_BIN" --strip-all "$TEMP_DIR/aar-normalized.so"
  "$STRIP_BIN" --strip-all "$TEMP_DIR/apk-normalized.so"
  cmp -s "$TEMP_DIR/aar-normalized.so" "$TEMP_DIR/apk-normalized.so" || \
    fail "APK libbox does not match the pinned AAR after packaging normalization"

  BUILD_TOOLS="$(find "$SDK_DIR/build-tools" -mindepth 1 -maxdepth 1 -type d | sort -V | tail -1)"
  [[ -x "$BUILD_TOOLS/aapt" ]] || fail "aapt is unavailable"
  [[ -x "$BUILD_TOOLS/apksigner" ]] || fail "apksigner is unavailable"
  BADGING="$($BUILD_TOOLS/aapt dump badging "$APK")"
  grep -Fq "versionCode='$EXPECTED_RELEASE_CODE'" <<< "$BADGING" || \
    fail "APK versionCode is not $EXPECTED_RELEASE_CODE"
  grep -Fq "versionName='$EXPECTED_RELEASE_VERSION'" <<< "$BADGING" || \
    fail "APK versionName is not $EXPECTED_RELEASE_VERSION"
  "$BUILD_TOOLS/apksigner" verify --verbose "$APK"
  shasum -a 256 "$APK"
fi

echo "Android release contract verified"
