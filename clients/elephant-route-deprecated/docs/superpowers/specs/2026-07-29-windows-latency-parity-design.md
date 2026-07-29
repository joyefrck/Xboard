# Windows Latency Parity and Win11 Compatibility Design

## Objective

Release Windows `1.6.7+10607` with node latency testing that:

- preserves the connected `ElephantNetworkService` sing-box core and Windows
  `strict_route` protection;
- measures the same observable quantity as macOS: two complete HTTP probes
  through one reusable connection, selecting the lowest valid result;
- runs up to four real probes concurrently without blocking Flutter's Windows
  platform thread;
- reports per-node failures precisely enough to diagnose affected Win11
  machines; and
- cancels cleanly when the user starts another test or disconnects.

## Current Failure Boundary

Windows `1.6.6` replaced the standalone `WindowsLatencySession` with one
MethodChannel `urlTest` call per node. That avoided the Win11 WFP failure caused
by a separate sing-box and `curl.exe`, but it also changed the measurement
contract:

- `WindowsVpnService` starts four Dart futures, while the Windows C++ bridge
  handles each named-pipe request synchronously on the platform thread. Slow
  requests are therefore serialized even though every Dart timeout starts
  immediately.
- The Go service ignores the requested test URL and `5000ms` policy. It always
  calls the Clash API with `https://www.gstatic.com/generate_204` and a
  `3000ms` timeout, bounded by a separate four-second HTTP client timeout.
- Each Windows node gets one cold sing-box URL test. macOS sends the same URL
  twice through one HTTP connection and selects the lower valid transfer time.

On a fast Windows machine the queue can drain before the Dart deadlines. On a
slower machine the first cold probe can consume most of the deadline, later
queued calls expire before native execution, and a whole batch can display
`失败`. A single cold Windows result is also not numerically comparable with
the warm second macOS transfer.

The existing tests mock MethodChannel calls asynchronously. They verify the
four-worker Dart scheduler but do not reproduce the synchronous Windows bridge,
so they encode the faulty behavior instead of detecting it.

## Chosen Architecture

The Windows service will own one asynchronous latency job at a time. Flutter
starts a job, polls lightweight snapshots, and cancels it explicitly. The long
network work never occupies the Windows platform thread or a named-pipe request.

The job will use the already-running sing-box `Box` instance directly:

1. Resolve each requested tag from `Box.Outbound()`.
2. Create one HTTP transport for that concrete outbound.
3. Route the transport's TCP dial through the outbound's `DialContext`.
4. Send the configured probe URL twice sequentially with the same HTTP client.
5. Accept HTTP `200` or `204`, record both transfer times, and select the
   smallest valid value.
6. Run nodes through a service-side worker pool capped at four.

This reproduces the macOS connection-reuse and minimum-selection semantics
without launching another sing-box process, adding a loopback proxy, invoking
`curl.exe`, or weakening `strict_route`.

## Service Job Lifecycle

The Go service will expose three bounded methods:

- `startLatencyTest`: validates the request, cancels an older job, creates a
  random run ID, starts the worker pool, and returns immediately.
- `getLatencyTest`: returns the current run ID, status, progress, and all
  completed node results.
- `cancelLatencyTest`: cancels the matching run and returns immediately.

Starting or stopping the VPN also cancels the active latency job before
replacing or closing the sing-box core. Only a result whose run ID matches the
current Dart generation can update the UI.

The service retains a completed snapshot until a new test begins or the VPN
stops. This lets Flutter recover the final result even if one polling interval
is delayed.

## Protocol Contract

The existing protocol remains version 1 because the installer ships the app,
C++ bridge, and service together and the new methods are additive.

`startLatencyTest` arguments:

```json
{
  "node_tags_json": "[\"Tokyo\",\"Osaka\"]",
  "test_url": "https://www.gstatic.com/generate_204",
  "timeout_ms": 5000,
  "concurrency": 4
}
```

`getLatencyTest` and `cancelLatencyTest` accept:

```json
{
  "run_id": "opaque-random-id"
}
```

Snapshots expose scalar fields plus `latency_results_json`, keeping nested JSON
out of the hand-written C++ parser:

```json
{
  "run_id": "opaque-random-id",
  "latency_test_status": "running",
  "latency_completed": 1,
  "latency_total": 2,
  "latency_results_json": "{\"Tokyo\":{\"latency_ms\":82,\"elapsed_ms\":190,\"attempts\":[168,82],\"failure_kind\":null,\"http_status_codes\":[204,204]}}"
}
```

The C++ bridge only serializes validated strings and integers. Every call
returns quickly; no new C++ background thread or long-lived Flutter
`MethodResult` is required.

## Validation and Safety

The service rejects a latency request unless:

- the VPN core is connected;
- node tags decode to a non-empty JSON string list with no duplicates;
- every tag identifies a concrete active outbound;
- the URL uses `http` or `https`, has a host, has no embedded credentials, and
  is at most 2048 bytes;
- `timeout_ms` is between 1000 and 10000; and
- `concurrency` is between 1 and 4.

The service limits a batch to 256 nodes and relies on cancellation rather than
holding an IPC connection open for the batch duration. Configuration contents,
outbound credentials, and full URLs are never written to logs.

## Result and Error Semantics

Each node produces exactly one final result:

- A valid `200` or `204` transfer contributes its elapsed milliseconds.
- If both transfers succeed, the lower value is authoritative.
- If only one succeeds, that value is authoritative.
- `timeout` means the per-node deadline expired.
- `httpError` means at least one HTTP response was received but neither was
  `200` or `204`.
- `transportError` means dialing, TLS, or request transport failed before a
  usable HTTP response.
- `serviceError` means the tag was unavailable or the active core could not
  provide the requested outbound.
- `cancelled` is used for unfinished nodes when the run is replaced, explicitly
  stopped, or the VPN disconnects.

One failed node never aborts other workers. Flutter publishes completed results
incrementally and preserves the final typed result as the authoritative UI
source until disconnect or a new test.

## Diagnostics

Sanitized service diagnostics are appended under
`C:\ProgramData\ElephantNetwork\runtime` and include:

- run ID prefix, requested node count, timeout, and concurrency;
- per-node attempt durations, HTTP status classes, total elapsed time, and
  failure kind;
- cancellation reason and completed/total counts; and
- the component boundary that failed: request validation, outbound lookup,
  dial, TLS/transport, HTTP status, timeout, or cancellation.

Logs may include the display node tag and probe host, but never the subscription
configuration, UUID, password, token, query string, or full URL.

## Flutter Behavior

`WindowsVpnService.testConnectionLatencies` will:

1. cancel the previous run;
2. start one service job with all concrete node tags;
3. poll every 250 milliseconds;
4. parse newly completed typed results and invoke the existing `onResult`
   callback once per node;
5. stop polling on `completed`, `cancelled`, service error, disconnect, or Dart
   generation change; and
6. request service cancellation when the user stops testing.

The obsolete per-node `WindowsServiceLatencyRunner` path will be removed from
the connected Windows flow. The old standalone Windows latency classes may
remain packaged only where existing offline tests or diagnostics still require
them; they cannot be invoked during a connected node test.

## Verification

Regression coverage will prove:

- two requests use one outbound-backed HTTP transport and the lower valid
  transfer wins;
- a slow first transfer can be followed by a successful second transfer within
  the node deadline;
- the service runs no more than four real node workers;
- job start and snapshot calls return without waiting for network completion;
- cancellation stops queued work and stale run IDs cannot update Flutter;
- custom URL and `5000ms` settings reach the service unchanged;
- every node receives a typed success or failure result;
- Windows no longer sends one synchronous `urlTest` MethodChannel call per
  node; and
- `strict_route` remains enabled for Win11 profiles.

Local verification will include focused Flutter tests, full
`flutter test --no-pub`, `flutter analyze`, Go service tests, native protocol
tests where available, formatting, and `git diff --check`.

Release verification must run on Windows CI and include:

- Flutter, Go, and C++ tests;
- Windows Release compilation;
- Inno Setup packaging for `1.6.7+10607`;
- silent installer execution;
- `ElephantNetworkService` LocalSystem registration and startup;
- application launch and service protocol smoke checks; and
- uninstall plus service/process residue cleanup.

The deliverable is
`ElephantNetwork-Setup-x64-v1.6.7.exe` with its Windows CI run, file size, and
SHA-256 digest. The unsigned installer may still trigger SmartScreen until an
Authenticode certificate is configured.

## Out of Scope

- Disabling Win11 `strict_route` or weakening TUN traffic protection.
- Restoring a standalone connected-test sing-box or `curl.exe` helper.
- Changing macOS or Android latency behavior.
- Authenticode signing.
- Unrelated TUN, DNS, subscription, or UI redesign work.
