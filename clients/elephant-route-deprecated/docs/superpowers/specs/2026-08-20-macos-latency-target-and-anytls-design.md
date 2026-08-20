# macOS Latency Target and AnyTLS Repair Design

## Problem

The repaired `1.6.5+10605` macOS client now streams live-core latency results,
but two correctness problems remain:

- a node can return HTTP 503 for the single Cloudflare delay target while the
  same node reaches GStatic and normal internet traffic successfully;
- AnyTLS results measured by the bundled sing-box `1.12.25` include cold
  session establishment and are commonly much higher than clients that warm
  the AnyTLS session before URLTest.

Runtime reproduction proved that the failures are target-specific rather than
node outages. `硅谷-高峰专线05` failed three Cloudflare delay requests and then
succeeded on GStatic and Google with stable results. The current runner retries
the same target, so it cannot distinguish an unavailable probe endpoint from
an unavailable node.

## Goals

- Mark a macOS node failed only after both built-in probe targets fail.
- Keep the existing four-node concurrency and five-second per-node budget.
- Preserve an explicitly configured custom probe URL without silently adding a
  different provider.
- Warm AnyTLS before URLTest without permanently keeping one idle connection
  open for every AnyTLS outbound.
- Keep the application version at `1.6.5+10605`.
- Keep the embedded core reproducible and auditable.

## Non-goals

- Making HTTPS URLTest numerically identical to ICMP, TCP-connect, Karing, or
  Shadowrocket metrics.
- Changing Windows or Android latency behavior.
- Enabling permanent `min_idle_session: 1` on subscription nodes.
- Shipping an unreviewed sing-box beta or alpha release.

## Options Considered

### 1. Multi-target fallback plus the merged upstream AnyTLS patch

Use Cloudflare first and GStatic second for the built-in macOS profile. Split
the existing five-second budget across the two targets. Build the arm64 core
from the official sing-box `v1.13.15` tag plus upstream merged commit
`f043ec560f9ecd61f34e1af7a583e81e480f10c1` (PR #4376).

Advantages:

- directly fixes the observed Cloudflare-only false failures;
- uses the exact upstream AnyTLS URLTest repair rather than a local behavioral
  invention;
- does not leave idle sessions open across all subscription nodes;
- avoids unrelated 1.14 beta changes.

Trade-off: the resulting core is a traceable patched stable build because the
merged fix has not yet appeared in a stable binary release.

This is the selected approach.

### 2. Upgrade to official sing-box 1.13.15 only

This provides general core updates but does not contain PR #4376. It therefore
does not meet the AnyTLS latency goal.

### 3. Set `min_idle_session: 1` on every AnyTLS outbound

This reduces cold URLTest time with the old core but retains an idle TLS
session for every AnyTLS node. The subscription contains many AnyTLS nodes, so
the battery, memory, server-connection, and reconnect costs are not justified.

## Architecture

### Probe target policy

`LatencyTestPolicy` will expose a macOS live-core target resolver. A built-in
Cloudflare target becomes the ordered list:

1. `https://cp.cloudflare.com/generate_204`
2. `https://www.gstatic.com/generate_204`

An explicit custom URL remains a one-item list.

`MacosVpnService` passes this ordered target list into
`MacosLatencyFallbackRunner`. Each worker tests one node at a time and advances
to the next target only after failure. The total caller timeout is divided
across the targets with a minimum one-second target budget. The first success
is published immediately. A failed result is published only after the target
list is exhausted. Cancellation suppresses stale callbacks between targets.

### Core provenance

The embedded arm64 binary will be built with the same official default feature
tags from sing-box `v1.13.15` and PR #4376 applied. Its reported version will be
`1.13.15-xboard.1`. The repository will retain:

- the executable;
- a version marker;
- a SHA-256 marker;
- a provenance text file containing the base tag, upstream patch commit, build
  tags, Go version, and build command.

`MacosSingBoxRuntime.targetVersion` will use the same version marker so an
installed `1.12.25` runtime is replaced on the next connection.

## Error Handling

- A Cloudflare 502/503/504, service error, timeout, or transport error advances
  to GStatic when using built-in targets.
- A GStatic failure becomes the final typed failure and is published once.
- A custom target retains a single-target result and is never replaced by a
  provider the user did not configure.
- A disconnect or newer run stops further target attempts and callbacks.
- Core replacement retains the existing temporary-file installer behavior and
  occurs before configuration validation.

## Testing and Acceptance

Focused tests will prove:

- Cloudflare 503 followed by GStatic success returns the GStatic latency;
- both targets failing publishes exactly one final failure;
- successful Cloudflare tests do not call GStatic;
- custom URLs do not gain a built-in fallback;
- the five-second total budget is divided between two built-in targets;
- core version and provenance markers match the embedded binary.

The patched core must report arm64, the intended version and feature tags,
validate the installed sanitized configuration without printing its contents,
and pass an isolated live-core delay comparison. Flutter formatting, focused
tests, static analysis, the full test suite, and a macOS `1.6.5+10605` release
build are required before delivery.
