# Android Latency Anycast Target Design

## Problem

Android connection latency workers currently force `www.gstatic.com` to an
IPv4 address before routing the request through the selected node. This avoids
the deterministic remote IPv6 failures, but local DNS can return a China-local
Google CDN address. Foreign nodes then travel back to that address, inflating
the measured round trip by about two times for affected regions.

Device comparison on the same hidden worker confirmed the routing effect. A
Silicon Valley node measured about 384-407 ms against gstatic and 189-200 ms
against Cloudflare HTTPS Anycast. The two native requests were not summed and
the proxy connection was reused.

## Scope

- Add an Android-only connection latency profile.
- Use `https://cp.cloudflare.com/generate_204` when the configured value is
  empty or one of the app's built-in default latency URLs.
- Preserve a non-default user-configured latency URL.
- Keep Windows and macOS connection latency behavior unchanged.
- Keep Android `ipv4_only` routing, four-worker concurrency, five-second total
  timeout, two sequential requests, connection reuse, lowest-success result,
  and typed failures unchanged.

## Design

`LatencyTestPolicy` will expose an `androidConnection` profile alongside the
existing `standard` and `v2boxConnection` profiles. `profileForPlatform()` will
select it only for Android. `probeUrls()` will return one HTTPS Cloudflare
Anycast URL for Android when the configured URL is empty or a built-in default;
otherwise it will return the user's custom URL.

The existing `v2boxConnection` profile remains the Windows/macOS profile and
continues to select gstatic for the existing default configuration. Timeout
selection treats both connection profiles as five-second probes.

## Validation

- Policy unit tests prove Android default URL selection, Android custom URL
  preservation, and unchanged Windows/macOS profile behavior.
- Existing Android connection/session/config tests remain green.
- `flutter analyze` and an Android debug APK build must succeed.
- Install the APK on the connected Pixel 6 Pro and compare repeated gstatic-era
  evidence with the new Android Cloudflare probe, checking HTTP 204 results,
  connection reuse, and representative node latency.

## Acceptance Criteria

- Android default latency tests use HTTPS Cloudflare Anycast.
- The prior remote IPv6 false-failure repair remains active.
- Affected foreign-node latency no longer includes the China-local gstatic CDN
  hairpin seen in the reproduction.
- No behavior changes occur for custom test URLs or non-Android platforms.
