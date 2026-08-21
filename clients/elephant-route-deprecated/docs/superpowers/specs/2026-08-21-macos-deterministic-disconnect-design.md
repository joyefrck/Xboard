# macOS Deterministic Disconnect Design

## Problem

After a long macOS TUN session, users can repeatedly click the acceleration
switch without seeing it remain off. Runtime evidence shows that an accepted
stop still completes in about one second, but the client can start again a few
seconds later. The current stop path also waits for latency-session cleanup
before publishing `disconnecting`, so the first click can appear to do nothing.
The privileged helper only sends graceful termination signals and can report a
successful stop even if a long-lived sing-box process remains.

## Goals

- Show the off intent immediately after the first accepted click.
- Prevent clicks from the same disconnect interaction from becoming a new
  connect request.
- Bound auxiliary latency cleanup so it cannot hold the power control forever.
- Stop the TUN core gracefully when possible, then force termination when
  required, and report failure if the process still exists.
- Keep the public version at `1.6.5` / build `10605`.

## Design

### Immediate disconnect state

`MacosVpnService.stop` publishes `VpnStatus.disconnecting` before awaiting any
latency-session cleanup. The dashboard therefore moves the switch to the off
side and disables repeated toggles as soon as the request is accepted.

### Bounded latency cleanup

`MacosLatencySession.close` keeps its idempotent shared future, but stream
subscription cancellation, process exit, and temporary-directory cleanup are
all best-effort and bounded. The stop path logs a cleanup timeout and proceeds
to the authoritative runtime stop. Cleanup owns only its isolated temporary
core and cannot be allowed to block TUN shutdown.

### Disconnect input settling

`VpnProvider` records completion of a user-triggered disconnect and rejects a
connect toggle during a short settling interval. This applies to both the main
window and tray because the protection lives at the provider boundary. It only
guards the immediate post-disconnect window; later intentional connections are
unchanged.

### Verified helper termination

The privileged helper first sends SIGTERM and waits for the existing graceful
deadline. If the managed core is still present, it sends SIGKILL, waits for a
short final deadline, and returns `ok: false` with `CORE_STOP_FAILED` when the
process cannot be removed. `AppDelegate` treats a failed helper stop as an
unsuccessful stop instead of always returning `stopped: true`. System proxy
restoration still runs even when core termination fails.

## Error Handling

- A latency cleanup timeout is logged but does not prevent the authoritative
  TUN stop.
- A remaining core produces a runtime failure state rather than a false
  disconnected state.
- Repeated stop calls continue to share the existing stop coordinator.
- System proxy restoration is attempted on every stop path.

## Verification

- Unit tests cover immediate `disconnecting`, bounded latency cleanup, and the
  post-disconnect settling guard.
- Swift contract tests cover the SIGKILL fallback and truthful stop result.
- Run Flutter analysis and the full test suite.
- Build and inspect the macOS arm64 `1.6.5` DMG, including architecture,
  embedded version, code signatures, and disk-image verification.
- During local runtime acceptance, verify one click emits a stop request,
  sing-box exits, runtime state becomes disconnected, and no automatic restart
  follows.
