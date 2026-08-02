# Stash and Quantumult X AnyTLS Subscription Design

## Goal

Make Xboard subscriptions expose AnyTLS nodes to current Stash and Quantumult X releases using each client's native configuration syntax.

## Scope

- Enable AnyTLS in the Stash renderer and record Stash 3.3.0 as the compatibility floor.
- Emit Stash-compatible YAML fields from Xboard's canonical `protocol_settings.tls` data.
- Enable AnyTLS in the Quantumult X renderer and emit its native comma-separated `anytls=` line format.
- Preserve the existing renderer selection, subscription wrapping, traffic headers, templates, and all unrelated protocols.

## Data flow

`ClientController` continues to select `Stash` or `QuantumultX` from the request flag/User-Agent. `AbstractProtocol` applies the renderer's protocol whitelist before `handle()` serializes nodes. Each renderer must therefore both allow `Server::TYPE_ANYTLS` and dispatch it to an AnyTLS builder.

## Output contracts

Stash receives a YAML proxy object containing `name`, `type: anytls`, `server`, `port`, `password`, optional `sni`, `skip-cert-verify`, and `udp: true`.

Quantumult X receives a line beginning with `anytls=host:port`, followed by `password`, `over-tls=true`, optional `tls-host`, `tls-verification`, `udp-relay=true`, and `tag`. IPv6 addresses are bracketed and Unicode tags follow the existing Quantumult X renderer convention.

## Compatibility and errors

Stash 3.3.0 is the first official iOS release with AnyTLS support and is recorded in renderer compatibility metadata. Missing optional TLS fields are omitted or use secure defaults. The implementation must not turn an empty AnyTLS-only subscription into malformed YAML or an invalid Base64 payload.

## Verification

Regression tests cover renderer routing, whitelist inclusion, correct Stash TLS field mapping, Quantumult X standard TLS syntax, IPv6, certificate verification, Unicode tags, and Base64 output. PHP syntax checks and the repository's related subscription tests must pass.
