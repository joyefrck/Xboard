#!/bin/bash
set -e

# Configuration
PROD_URL="https://www.elephant111.org/"
VERSION_FILE="android_release_version.txt"
BUILD_DIR="build/app/outputs/flutter-apk"
SOURCE_APK="$BUILD_DIR/app-release.apk"

increment_minor_version() {
    local current_version="$1"
    local major minor patch

    IFS='.' read -r major minor patch <<< "$current_version"

    if ! [[ "$major" =~ ^[0-9]+$ && "$minor" =~ ^[0-9]+$ && "$patch" =~ ^[0-9]+$ ]]; then
        echo "❌ Invalid version in $VERSION_FILE: $current_version" >&2
        exit 1
    fi

    echo "$major.$((minor + 1)).0"
}

validate_release_version() {
    local version="$1"
    if ! [[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
        echo "❌ RELEASE_VERSION must be a semantic version (for example 1.6.1): $version" >&2
        exit 1
    fi
}

REQUESTED_RELEASE_VERSION="${RELEASE_VERSION:-}"
if [ -n "$REQUESTED_RELEASE_VERSION" ]; then
    RELEASE_VERSION="$REQUESTED_RELEASE_VERSION"
elif [ -f "$VERSION_FILE" ]; then
    LAST_VERSION="$(tr -d '[:space:]' < "$VERSION_FILE")"
    RELEASE_VERSION="$(increment_minor_version "$LAST_VERSION")"
else
    RELEASE_VERSION="1.0.0"
fi
validate_release_version "$RELEASE_VERSION"

BUILD_NAME="$RELEASE_VERSION"
BUILD_NUMBER="$(awk -F. '{ printf "%d%02d%02d", $1, $2, $3 }' <<< "$RELEASE_VERSION")"

OUTPUT_NAME="elephant-route-android-release-arm64-v$RELEASE_VERSION.apk"
TARGET_APK="$BUILD_DIR/$OUTPUT_NAME"

echo "🚀 Starting Production Build..."
echo "📍 API Base URL: $PROD_URL"
echo "🏷️ APK Version: V$RELEASE_VERSION"
echo "🤖 Android versionName: $BUILD_NAME"
echo "🔢 Android versionCode: $BUILD_NUMBER"
echo "📦 Output Filename: $OUTPUT_NAME"
echo "🧩 Android ABI: arm64-v8a"

# Clean build
echo "🧹 Cleaning previous build..."
flutter clean

# Build APK
echo "🔨 Building APK..."
ELEPHANT_ANDROID_ABIS="arm64-v8a" flutter build apk --release \
    --target-platform android-arm64 \
    --build-name="$BUILD_NAME" \
    --build-number="$BUILD_NUMBER" \
    --dart-define=BASE_URL="$PROD_URL"

# Rename
if [ -f "$SOURCE_APK" ]; then
    echo "📝 Renaming artifact..."
    mv "$SOURCE_APK" "$TARGET_APK"
    "$PWD/scripts/verify_android_release_contract.sh" "$TARGET_APK"
    echo "$RELEASE_VERSION" > "$VERSION_FILE"
    echo "✅ Build Successful!"
    echo "📂 Output: $(pwd)/$TARGET_APK"
    echo "🧾 Recorded latest APK version: V$RELEASE_VERSION"
else
    echo "❌ Build Failed: Output APK not found."
    exit 1
fi
