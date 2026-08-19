# macOS Connection Stability Repair Design

## Problem

macOS 1.6.4 can interrupt long-lived traffic after connecting even when the
remote proxy node is usable in another client. Runtime evidence from
2026-08-20 shows three related failure modes:

- every transition to `connected` schedules a full node latency run two seconds
  later;
- automatic selection can treat one failed latency run as proof that the
  current node is unavailable;
- selecting an outbound rewrites the config, stops the active sing-box/TUN
  runtime, and starts it again.

The stop/start sequence necessarily aborts active ChatGPT streams. It also
produces another `connected` transition, which schedules another latency run.
The same incident contained many TLS probe failures, Clash delay `503`
responses, and a burst of DNS `EOF` errors for ChatGPT, Apple, Google, and other
domains. This means node health, DNS health, and client lifecycle behavior can
amplify one another.

## Goals

- Switch macOS selector outbounds without stopping the TUN runtime.
- Preserve the current working outbound when a hot switch fails.
- Prevent one noisy bulk-latency result from immediately moving automatic mode
  away from its current node.
- Serialize automatic and manual node selection so stale asynchronous switches
  cannot overwrite a newer choice.
- Harden the default macOS remote DNS transport without sending foreign DNS
  queries outside the selected proxy.
- Keep existing Android and Windows behavior unchanged.

## Non-Goals

- Replacing the helper-based macOS TUN runtime with Network Extension.
- Adding periodic background node switching.
- Preserving an already-broken TCP stream after the remote node itself fails.
- Bypassing the proxy with foreign DNS as a fallback.
- Changing subscription formats or server-side node definitions.

## Considered Approaches

### 1. Hot switch with guarded automatic selection

Use the existing local Clash API to select an outbound with
`PUT /proxies/{group}` and `{"name":"<outbound>"}`. Confirm an apparently
failed current auto-selected node with a focused Clash delay probe before
moving to the best bulk-test result. Serialize selection calls and retain the
old selection on any error.

This is the selected approach. Windows already uses the same Clash API shape,
and macOS already exposes the controller at `127.0.0.1:9090`.

### 2. Keep restarting TUN with cooldowns

Add a switch cooldown and require repeated failures, but retain the existing
stop/restart implementation. This is smaller but still guarantees disruption
whenever a switch is eventually made.

### 3. Move macOS to Network Extension

Replace the helper and manual route lifecycle with a system extension. This may
improve long-term platform integration, but it is a separate architecture and
release project rather than a contained repair.

## Architecture

### Clash controller boundary

Add a small macOS Clash controller responsible for URL tests and outbound
selection. It receives a `Dio` client and exposes typed asynchronous methods.
The controller validates the HTTP response and throws a specific selection
exception for transport errors or non-success responses.

`MacosVpnService.selectOutbound` will:

1. validate that the requested selector group and outbound exist in the active
   sanitized config;
2. call the Clash controller while leaving the runtime and TUN untouched;
3. update the selector default in memory and persist `config.json` only after
   the controller accepts the switch;
4. keep `VpnStatus.connected` throughout a successful switch;
5. log and rethrow a typed error on failure without stopping the core or
   changing the active config.

The config persistence keeps a later runtime restart aligned with the last
successful hot selection. Persistence failure is reported, but it must not
stop the already-working runtime.

### Serialized node selection

`NodeProvider` will own one selection generation and one in-flight switch. A
new explicit user selection supersedes older pending automatic work. Automatic
evaluation cannot issue a second switch while another switch is in flight.
The provider updates `_autoSelectedRealNode` only after the VPN manager reports
success.

Manual selection remains immediate from the UI perspective, but its returned
future is observed so failures become provider errors rather than unhandled
asynchronous exceptions.

### Automatic-selection confirmation

Bulk latency remains useful for ranking candidates, but it is not sufficient
to evict the current node. If the current auto-selected node has a non-positive
bulk result while another node has a positive result, the provider performs a
focused `urlTest` for the current node:

- positive confirmation: retain the current node and replace its displayed
  latency with the confirmed value;
- failed confirmation: hot-switch to the best positive candidate;
- stale generation or disconnected VPN: discard the result and do nothing.

Initial automatic selection and a user explicitly choosing automatic mode do
not require confirmation because there is no established current automatic
outbound to protect.

Because hot switching does not change VPN status away from `connected`, it no
longer produces a second connected transition or an automatic latency loop.

### DNS hardening

The existing default remote resolver is bare `8.8.8.8` through the selected
outbound. On macOS only, the sanitizer will replace that unchanged default with
Cloudflare's IP-addressed DoH endpoint
`https://1.1.1.1/dns-query`, still detoured through `节点选择`, with IPv4-only
resolution behavior. User-configured remote DNS values remain untouched.

Domestic DNS remains direct and unchanged. No foreign DNS query falls back to
direct routing, because that would change privacy behavior and could hide an
unhealthy proxy rather than recover it.

## Error Handling

- Clash API timeout, connection refusal, or non-2xx response: retain the old
  outbound and connected tunnel; expose a node-selection error.
- Requested group or outbound missing from the active config: reject the switch
  before issuing an HTTP request.
- Config persistence failure after a successful hot switch: keep the live
  switch, log the persistence error, and surface it for a later reconnect; do
  not restart TUN.
- Focused confirmation failure: it counts only as confirmation that the current
  node is unavailable when the VPN is still connected and the selection
  generation is current.
- Disconnect or disposal: invalidate pending confirmation and switch results.

## Testing

### Unit tests

- macOS Clash controller emits the Windows-compatible `PUT /proxies/{group}`
  request body and accepts a 2xx response.
- transport and non-2xx failures throw typed errors.
- macOS VPN lifecycle contract rejects any `stopCore(nodeSwitch)` call from
  `selectOutbound`.
- automatic selection retains the current node when focused confirmation
  succeeds after a failed bulk result.
- automatic selection switches only after focused confirmation also fails.
- overlapping selection requests cannot apply a stale result.
- a hot switch does not produce a synthetic disconnected/connected transition
  and therefore schedules no second automatic latency run.
- macOS default remote DNS becomes proxied DoH; custom remote and domestic DNS
  remain unchanged.

### Verification

- Run focused Flutter tests for the Clash controller, macOS VPN lifecycle,
  NodeProvider latency behavior, and DNS policy.
- Run `dart format --output=none --set-exit-if-changed` on changed Dart files.
- Run `flutter analyze`.
- Run the complete Flutter test suite with `flutter test --no-pub`.
- Run JavaScript contract tests with `node --test tests/*.test.js`.
- Build `flutter build macos --release`.
- Perform a local runtime acceptance using a fixed node and automatic mode:
  confirm that node selection produces a Clash API switch without `stopTun`,
  the TUN interface remains stable, and a ChatGPT long connection is not
  interrupted by the client's selection lifecycle.

## Release Boundary

Passing local tests and a release build proves source and packaging readiness,
not production behavior on every network. A release candidate must retain
timestamped `dart.log`, `native.log`, and `tun-helper.log` evidence for at least
one real automatic selection and one failed hot-switch simulation before the
macOS build is published.
