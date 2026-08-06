# macOS Latency Reliability Design

## Problem

Recent macOS runtime logs show that the isolated connection-probe session exits
with `ArgumentError` before testing any node. The connected core was started
from a cached subscription, while the node list was refreshed afterward. Five
of the 32 requested node tags were absent from the connected config, and one
missing tag caused the builder to reject the entire batch. All 32 nodes then
fell back to the main Clash delay API, producing deterministic 404/503 failures
and inflated successful results.

macOS also still uses the gstatic endpoint and does not force IPv4 resolution
on its hidden latency inbounds. Android already avoids both sources of noisy
results by using Cloudflare HTTPS Anycast and an `ipv4_only` resolve rule.

## Scope

- Prefer the latest valid subscription snapshot for an isolated macOS latency
  session when it covers more requested nodes than the connected config.
- Restrict a session to concrete proxy outbounds present in the selected
  config; missing tags receive an isolated service error and cannot abort the
  remaining nodes or enter the known-bad Clash fallback path.
- Add `ipv4_only` resolve rules before each hidden worker route.
- Use `https://cp.cloudflare.com/generate_204` for macOS defaults while
  preserving explicit custom URLs.
- Keep Windows behavior unchanged.
- Retain two sequential curl transfers, connection reuse, the lowest successful
  sample, four workers, the five-second deadline, typed failures, and bounded
  fallback for genuine per-node primary failures.

## Design

`MacosLatencySourceSelector` compares the concrete requested tags available in
the active and refreshed configs, chooses the config with greater coverage,
and reports eligible and missing tags separately. `MacosVpnService` loads the
latest subscription cache, sanitizes it using the existing macOS runtime rules,
then creates the isolated session only for eligible tags. Missing tags are
merged into the final result as service errors and are never sent to the main
Clash API.

`MacosLatencyConfigBuilder` exposes concrete proxy tag discovery and emits a
resolve-plus-route pair for every loopback worker. `LatencyTestPolicy` adds a
macOS-only connection profile that shares Android's HTTPS Anycast default but
does not alter Windows endpoint selection.

## Validation

- Unit tests cover refreshed-config selection, partial mismatch isolation,
  IPv4 worker rules, macOS endpoint policy, custom URLs, and unchanged Windows
  policy.
- Focused macOS latency tests, provider tests, formatting, static analysis, and
  a macOS release build must pass.
- A local runtime test must show the primary macOS session running instead of a
  batch-level `ArgumentError`, no fallback 404s for stale tags, Cloudflare 204
  probes, and representative warm-request latency from the second curl sample.
