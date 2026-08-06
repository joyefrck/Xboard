# Android Latency IPv4 and Failure Reporting Design

## Problem

The Android client tests every concrete proxy through one of four hidden mixed
inbounds. Each node receives two sequential HTTP requests to
`https://www.gstatic.com/generate_204` within one five-second budget.

Device evidence from a Pixel 6 Pro running `1.6.7+10607` showed five repeatable
failures in a 32-node subscription. Four failures returned in 60-745 ms because
the remote AnyTLS server resolved `www.gstatic.com` to IPv6 and then reported
`network is unreachable`. One node exhausted the five-second deadline while
connecting to its proxy server. Android currently collapses both cases, every
non-200/204 response, and every transport exception into `latencyMs = -1`.
Flutter then labels every negative result as `timeout`.

## Goals

- Resolve latency-probe destinations to IPv4 before routing them through a
  hidden Android latency selector.
- Preserve the existing gstatic endpoint, two-attempt connection reuse, lowest
  successful latency rule, five-second total budget, and four-worker limit.
- Report `timeout`, `httpError`, `transportError`, `serviceError`, and
  `cancelled` accurately instead of treating every failure as a timeout.
- Include a stable, credential-safe node correlation key in Android probe logs.
- Leave the main TUN inbound, active selector, subscription, and normal VPN
  traffic unchanged.

## Non-goals

- Do not change the global Android DNS strategy.
- Do not replace the probe endpoint or deploy probe infrastructure.
- Do not change Windows or macOS latency behavior.
- Do not retry a failed node outside the existing two attempts or extend the
  total timeout.

## Selected Approach

### Worker-only IPv4 resolution

`AndroidLatencyConfigBuilder` will prepend two rules for each hidden latency
inbound:

1. A `resolve` action with `strategy: ipv4_only` resolves the requested probe
   hostname locally.
2. The existing route action sends the resolved IPv4 destination through the
   matching hidden selector.

The resolve rule applies only to `__elephant_latency_in_*`. The main TUN and
all existing route rules remain untouched. This avoids the remote AnyTLS
server choosing an unreachable AAAA result while retaining TLS SNI and the
existing generate-204 semantics.

### Typed native results

`AndroidConnectionProbeManager` will retain per-attempt latency and HTTP status
information. It will classify a fully failed probe as follows:

- `cancelled` when its owning session was cancelled;
- `timeout` for deadline exhaustion or socket/call timeout exceptions;
- `httpError` when the transport completed but neither attempt returned 200
  or 204;
- `transportError` for other I/O failures;
- no failure when either attempt succeeds.

The result map will expose `failureKind` and `httpStatusCodes`. Dart will parse
these fields into the existing `ConnectionLatencyFailureKind` and
`ConnectionLatencyResult.httpStatusCodes` model. A missing or unknown native
failure kind on a failed response is a malformed platform result and becomes a
service error at the session boundary, rather than being mislabeled timeout.

### Safe correlation logging

The Dart session will pass the node tag to the platform probe only for
correlation. Android will log the first 12 hexadecimal characters of SHA-256
over the tag and never log the raw tag, server address, UUID, password, or
subscription configuration. The log will also retain proxy port, attempt
latencies, HTTP statuses, connection count, reuse state, and failure kind.

## Data Flow

1. `NodeProvider` starts one connection-latency session with the concrete node
   tags.
2. `AndroidLatencySession` selects a node on a hidden selector.
3. The hidden inbound resolves `www.gstatic.com` with `ipv4_only`, then routes
   the resulting IPv4 destination through that selector.
4. `AndroidConnectionProbeManager` performs two requests using one OkHttp
   client and connection pool under the remaining session deadline.
5. Native Android returns latency, elapsed time, attempt list, HTTP status
   list, connection count, and typed failure.
6. Flutter publishes the typed result; the UI shows `超时` only for a real
   timeout and `失败` for the other existing failure kinds.

## Error Handling

- A successful attempt wins even if the other attempt fails.
- Cancellation is not reported as timeout.
- Selector update failures remain `serviceError`, because no node probe was
  performed.
- Malformed native payloads remain `serviceError` through the existing session
  catch boundary.
- Failure details must not contain raw exception messages that can expose
  endpoint or subscription information.

## Tests

- Dart config tests verify every hidden inbound has an IPv4-only resolve rule
  immediately before its selector route rule and that original route rules are
  preserved.
- Kotlin tests cover persistent connection reuse, HTTP failures, transport
  failures, deadline timeouts, and cancellation classification.
- Dart platform-bridge tests cover each native failure string, HTTP status
  propagation, node-tag forwarding, and rejection of malformed failure data.
- Dart session tests verify the node tag reaches the probe and selector failures
  remain service errors.
- Focused Flutter tests and Android unit tests must pass before device testing.
- Device acceptance repeats the full latency test twice and confirms that the
  earlier remote IPv6 failures no longer occur, while a genuine five-second
  node-server timeout remains classified as timeout.

## Acceptance Criteria

- The four nodes previously failing with remote IPv6 `network is unreachable`
  return latency results on the connected test device.
- A real five-second timeout is still shown as `超时`.
- Fast transport, DNS, IPv6, and HTTP failures are shown as `失败`, not
  `超时`.
- Two attempts still reuse one connection when the upstream supports it.
- The four-worker concurrency limit and five-second per-node total budget are
  unchanged.
- No raw subscription credential or node tag is added to logs.
- Normal VPN routing and the active user-selected node are unchanged.
