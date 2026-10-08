#!/bin/sh
set -eu

# Official fixed release digests, independently verified before extraction.
version=1.14.2
case "$(uname -s)/$(uname -m)" in
  Linux/x86_64) platform=linux-amd64-musl; digest=8f6cb4bcf94d2b33c65d52e0d5b142db29a938336f1ff7267f397ac3758fc297 ;;
  Linux/aarch64) platform=linux-arm64-musl; digest=675297394f9430cebb72b3c48ba8bce0d6f7c750a9d68a8f7f88c515c8255cd1 ;;
  Darwin/arm64) platform=darwin-arm64; digest=925c5382eca8492b0150f868a6db20b18290a38700e621724b3703fd453e032d ;;
  *) echo 'Unsupported probe platform' >&2; exit 1 ;;
esac
destination=${1:-/usr/local/bin/sing-box}
task_dir=$(mktemp -d)
trap 'rm -rf "$task_dir"' EXIT HUP INT TERM
archive=sing-box-${version}-${platform}.tar.gz
curl -fLsS --connect-timeout 10 --max-time 180 --retry 2 "https://github.com/SagerNet/sing-box/releases/download/v${version}/${archive}" -o "$task_dir/$archive"
if command -v sha256sum >/dev/null 2>&1; then
  actual=$(sha256sum "$task_dir/$archive" | cut -d ' ' -f 1)
else
  actual=$(shasum -a 256 "$task_dir/$archive" | cut -d ' ' -f 1)
fi
[ "$actual" = "$digest" ] || { echo 'Probe checksum mismatch' >&2; exit 1; }
tar -xzf "$task_dir/$archive" -C "$task_dir"
mkdir -p "$(dirname "$destination")"
install -m 755 "$task_dir/sing-box-${version}-${platform}/sing-box" "$destination"
"$destination" version
