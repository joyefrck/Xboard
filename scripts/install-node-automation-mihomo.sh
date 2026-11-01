#!/bin/sh
set -eu

version=1.19.33
case "$(uname -s)/$(uname -m)" in
  Linux/x86_64) platform=linux-amd64-compatible; digest=386508bfb1c72c43b6a0eda73708ecdce82546581b0ebcb16366c53757c622b6 ;;
  Linux/aarch64) platform=linux-arm64; digest=ba408b317080ee265c46cb923a50018c1b82cbd708551cdd46a1e3e307396187 ;;
  Darwin/arm64) platform=darwin-arm64; digest=060b64b0f2c9a310831db0fe4adb2878de67bfa606c311813c1f13966aa504b6 ;;
  *) echo 'Unsupported REALITY probe platform' >&2; exit 1 ;;
esac
destination=${1:-/usr/local/bin/mihomo}
task_dir=$(mktemp -d)
trap 'rm -rf "$task_dir"' EXIT HUP INT TERM
archive=mihomo-${platform}-v${version}.gz
curl -fLsS --connect-timeout 10 --max-time 180 --retry 2 "https://github.com/MetaCubeX/mihomo/releases/download/v${version}/${archive}" -o "$task_dir/$archive"
if command -v sha256sum >/dev/null 2>&1; then
  actual=$(sha256sum "$task_dir/$archive" | cut -d ' ' -f 1)
else
  actual=$(shasum -a 256 "$task_dir/$archive" | cut -d ' ' -f 1)
fi
[ "$actual" = "$digest" ] || { echo 'REALITY probe checksum mismatch' >&2; exit 1; }
gzip -dc "$task_dir/$archive" > "$task_dir/mihomo"
mkdir -p "$(dirname "$destination")"
install -m 755 "$task_dir/mihomo" "$destination"
"$destination" -v
