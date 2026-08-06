# Windows Latency Reliability Repair Design

## Problem

Windows uses the service-owned sing-box core for connection latency jobs. The
browser can therefore remain healthy while node cards are reported as failed or
the Dart poller waits indefinitely for a service response.

The current platform policy still maps Windows' built-in latency URLs to
`https://www.gstatic.com/generate_204`. Android and macOS already normalize the
same built-in choices to `https://cp.cloudflare.com/generate_204`. A Windows
node that cannot reach the Google endpoint is consequently classified as a
transport or timeout failure even when the node can carry normal traffic.

The Windows job poller also has no deadline of its own. The provider now has a
65-second UI containment deadline, but a stuck method-channel call can leave the
native job running until that outer deadline cancels it.

## Considered Approaches

1. Retry failed results in the UI. This hides some failures but repeats the same
   unsuitable target and does not bound a stuck native call.
2. Change only the probe URL. This fixes the main false-failure source but
   leaves Windows dependent on the provider for lifecycle containment.
3. Align the Windows default target with Android/macOS and add a bounded Windows
   poller that cancels its native job on deadline. This addresses both observed
   symptoms while preserving the service-owned core and latency calculation.
   This is the selected approach.

## Design

### Probe target

The Windows connection profile will treat every built-in/default choice as the
Cloudflare HTTPS Anycast endpoint. An explicitly configured custom URL remains
unchanged. The timeout stays at 5 seconds, concurrency stays at four, and the
Go service still performs two HTTP transfers on one reused transport and keeps
the lowest successful value.

### Poller lifecycle

`WindowsLatencyJobRunner` will enforce two bounded waits:

- a short deadline for each service IPC call, so a hung `start`, `get`, or
  `cancel` response cannot retain the Dart future;
- a whole-job deadline below the provider's 65-second safety boundary.

When either deadline expires after a run has started, the runner sends a
best-effort bounded `cancelLatencyTest`, preserves results already returned by
the service, and marks unfinished nodes as timeout failures. Existing explicit
cancellation continues to return cancelled failures.

### Scope

The repair does not change Windows TUN routing, the active sing-box core,
protocol parsing, node concurrency, version metadata, or latency math. It only
aligns the default endpoint and closes the Windows-specific poll lifecycle.

## Testing

Regression coverage will verify that:

- the Windows profile maps empty and all built-in values to Cloudflare HTTPS;
- a custom Windows probe URL is preserved;
- a hung service poll triggers bounded native cancellation;
- completed results survive when a later poll times out;
- unfinished nodes receive typed timeout results;
- existing Go probe connection reuse, partial-success, and job concurrency
  tests remain green.
