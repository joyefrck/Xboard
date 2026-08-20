# macOS Live-Core Latency and Selection Restore Design

## Problem

The installed `1.6.5+10605` client can show an empty node grid with the global
action stuck on `测速中` for nearly one minute after connecting.

Runtime evidence from the affected machine shows the following sequence:

- the VPN reports connected and starts an automatic 36-node latency run two
  seconds later;
- the isolated four-worker sing-box session spends about 45 seconds returning
  only five-second timeouts;
- primary failures are not published to the provider, so every node remains
  visually empty during that phase;
- the existing Clash fallback then returns usable results between roughly 45
  and 59 seconds after the run began;
- an independently recreated four-worker config later returned four successful
  HTTP 204 responses in `0.84–1.15s`, so the isolated session is timing-sensitive
  rather than permanently invalid.

The same runtime also showed a selection mismatch: the UI highlighted
`东京-高峰专线04`, while the live `节点选择` selector still reported
`自动选择`. A concrete node saved while disconnected is treated as applied even
though `MacosVpnService.selectOutbound` has no active config at that time, and
the saved choice is not replayed after the next connection succeeds.

## Goals

- Publish macOS node latency results promptly after a connection.
- Keep all latency work on the already-running sing-box core so it cannot
  create a second routed data path through the active TUN.
- Never restart or replace the active TUN during latency or node restoration.
- Reapply a saved concrete node once the macOS runtime is connected.
- Prevent stale restoration from overriding a newer user choice.
- Keep the release version at `1.6.5+10605`, as requested.

## Non-goals

- Changing Windows, Android, web, or mock latency behavior.
- Rewriting the subscription DNS schema.
- Removing the isolated latency-session code and tests in this repair. It may
  remain as unused compatibility code until a separate cleanup is approved.
- Adding Apple Developer ID signing or notarization.

## Options Considered

### 1. Use the live Clash API as the macOS primary latency path

Call `/proxies/<node>/delay` on the current `127.0.0.1:9090` core with bounded
concurrency and existing retry rules. Stream every completed success or failure
to `NodeProvider` immediately.

Advantages:

- no temporary core, private ports, duplicated DNS state, or nested path
  through the active TUN;
- the affected run already proved this path returned usable results;
- measuring a concrete proxy tag does not switch the active selector;
- the existing fallback runner already provides typed results, retries, logging,
  cancellation, and bounded concurrency.

Trade-off: this uses sing-box's delay API instead of the two-request curl
measurement from the isolated session.

This is the selected approach because availability and prompt UI feedback are
more important than retaining a timing-sensitive measurement path.

### 2. Give the isolated session a short global budget, then fall back

Preserve the current primary measurement but cancel it after about eight
seconds.

This retains some curl measurements, but still starts a second core and can
leave the first screen blank for the entire budget. It also introduces a second
deadline beside the existing 60/65-second deadlines.

### 3. Race the isolated probe and Clash delay for every node

Return the first valid result from both mechanisms.

This provides the fastest of both paths but doubles traffic, child processes,
logging, and cancellation complexity. It is unnecessary for a stability repair.

## Architecture

### Live-core latency

`MacosVpnService.testConnectionLatencies` retains its public contract and
whole-run timeout. Its macOS production path will:

1. cancel any older latency generation;
2. verify that the VPN is connected and the live Clash API is available;
3. call the existing `MacosLatencyFallbackRunner` with an empty primary result
   map so every requested concrete node is tested through the live core;
4. publish every final node result through `onResult` as soon as that node
   finishes;
5. return a complete immutable result map, filling any unreported node with a
   typed timeout if the whole-run deadline expires.

The runner will use four workers for parity with the provider's requested
macOS concurrency. Existing 502/503/504 and fast transport-error retry behavior
remains unchanged.

The temporary `MacosLatencySession` will no longer be entered by the connected
production path. Its implementation and isolated unit tests remain untouched
in this change.

### Connected selection restoration

`NodeProvider` will replace the current fire-and-forget connected timer setup
with one generation-aware connected transition:

1. capture the current selection generation;
2. when manual mode is active and the selected node is concrete, enqueue one
   `selectOutbound('节点选择', selectedNode.name)` operation;
3. abandon the restoration result if a newer explicit selection or disconnect
   changed the generation;
4. schedule the automatic latency run only after restoration completes or
   fails;
5. in auto mode, skip restoration and schedule latency immediately.

A restoration failure keeps the VPN connected, exposes the existing node
switch error, and still allows latency measurement. No stop/start call is
permitted.

### UI behavior

`NodeProvider` continues clearing stale latency values at the start of a run.
Because live-core results are streamed immediately, cards begin showing either
a positive delay or the existing failure presentation while the global action
may still say `测速中`. The action returns to its idle state in the existing
`finally` path.

## Error Handling and Cancellation

- A local Clash API refusal or whole-run timeout produces typed failed results
  and releases `isTestingLatency`.
- Disconnect increments the latency and selection generations, cancels the
  active run, and prevents late callbacks from mutating the node list.
- A newer manual selection supersedes any queued connection restoration.
- Latency requests never call `selectOutbound`, `stopCore`, or `startTunMode`.
- Selection restoration only calls the hot-switch controller added in the
  previous repair.

## Testing

Add focused tests proving:

- macOS service latency starts with live Clash probes and streams results
  without constructing an isolated session;
- four-node live-core latency runs concurrently and preserves retry behavior;
- a failed live probe still publishes a typed result instead of leaving the
  node unset;
- a saved concrete node is replayed after the connected transition before the
  delayed latency run begins;
- auto mode does not replay a concrete node;
- a newer explicit selection supersedes stale restoration;
- node switching still contains no TUN stop/start calls.

Run formatting, focused Flutter tests, `flutter analyze`, the full Flutter test
suite, the repository Node suite with its known unrelated macOS packaging
contract failure reported separately, and a macOS release build.

## Release and Acceptance

Build the repaired package as `1.6.5+10605` and validate the mounted arm64 DMG:

- `Info.plist` version and build number;
- all Mach-O files are arm64;
- deep ad-hoc signature and helper identifier;
- DMG checksum and SHA-256;
- same-version replacement must be installed manually because the updater will
  not treat it as a newer semantic version.

Live acceptance must confirm that saved manual selection and the live Clash
selector match after connection, latency values begin arriving promptly, the
global `测速中` state clears, and the TUN PID remains unchanged.
