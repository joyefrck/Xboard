# macOS Latency Lifecycle Repair Design

## Problem

macOS 1.6.4 can leave the node-selection action showing `测速中` after the VPN
connects, even though normal proxied browsing continues to work. Live evidence
shows that the main runtime is healthy while no latency worker core or curl
process exists. The latency request can therefore remain pending before the
owned `MacosLatencySession` is created, and disconnecting only closes an
already-created session.

`NodeProvider` clears `_isTestingLatency` only when the latency future reaches
its `finally` block. An unbounded preflight future prevents that block from
running. The UI also aliases node refresh and latency testing through the same
`isLoading` getter, so a refresh can be mislabeled as `测速中`.

## Considered Approaches

1. Reset only the button state after a fixed timer. This makes the UI recover,
   but the old task can continue running and later overwrite results.
2. Reset state only on VPN disconnect. This fixes one exit path, but a connected
   test can still remain stuck indefinitely.
3. Use an end-to-end deadline, generation-based cancellation, and separate UI
   state. This bounds every phase, prevents stale callbacks, and represents the
   real operation in the UI. This is the selected approach.

## Design

### Service lifecycle

`MacosVpnService.testConnectionLatencies` will treat one invocation as a single
owned run. The run generation is captured after cancelling any older run. A
deadline wraps the complete operation, including cache preflight, source
selection, the primary session, cleanup, and fallback. Timeout or cancellation
returns typed failure results for unfinished nodes instead of leaving the
future pending.

Cancellation remains generation based. `stopConnectionLatencyTest` increments
the generation and closes the current session. Every result callback checks the
captured generation, so a cancelled run cannot update a later connection. Any
unbounded best-effort cache read receives a short timeout and falls back to the
already-active runtime config.

### Provider lifecycle

`NodeProvider` will expose node refresh and latency testing separately. It will
listen for a transition away from `VpnStatus.connected`, invalidate the active
latency run, and clear the testing flag immediately. The asynchronous call still
finishes through its own `finally`, but run identity prevents an older call from
clearing or updating a newer run.

The provider-level deadline is a final containment boundary. It guarantees the
UI future completes even if a future service implementation introduces another
unbounded await.

### UI state

The node-selection floating action button will bind only to
`isTestingLatency`. Node refresh retains `isLoading` and is displayed by the
list's loading treatment. The button therefore says `测速中` only for an actual
latency run.

### Error and result behavior

- Cache preflight timeout: continue with the active sanitized config.
- Whole-run timeout: mark unfinished nodes as timeout failures and release UI.
- VPN disconnect or superseding run: mark unfinished work cancelled internally,
  suppress stale callbacks, and release UI immediately.
- Existing successful node results remain valid; no latency math changes.

## Testing

Regression tests will cover:

- a connection-latency manager whose future never completes;
- disconnect while that future is pending;
- a late callback from a cancelled run;
- separate node-loading and latency-testing getters;
- macOS service cache preflight timeout falling back to active config;
- existing macOS session and fallback tests to ensure latency behavior remains
  unchanged.

Focused Flutter tests, static analysis of changed files, and a macOS release
build are required before completion.
