# v2rayN AnyTLS Subscription Compatibility Design

## Goal

Ensure Xboard subscriptions selected through either `?flag=v2rayn` or a
`v2rayN/<version>` User-Agent include eligible AnyTLS nodes as standard
`anytls://` share links.

## Current Failure

Both v2rayN request shapes select `App\Protocols\General`. That renderer's
allowed-protocol whitelist excludes `Server::TYPE_ANYTLS`, so
`AbstractProtocol` removes AnyTLS nodes before rendering. `General::handle()`
also has no AnyTLS dispatch branch. An AnyTLS-only subscription is therefore
returned as an empty Base64 payload.

## Chosen Approach

Port only the upstream General-renderer support instead of merging the full
upstream branch:

- Add `Server::TYPE_ANYTLS` to `General::$allowedProtocols`.
- Dispatch AnyTLS entries from `General::handle()`.
- Add `General::buildAnyTLS()` using the existing Xboard share-link shape:
  `anytls://password@host:port?sni=...&insecure=...#name`.
- Wrap IPv6 hosts with `Helper::wrapIPv6()`, matching other General builders
  and the current upstream implementation.

This keeps the change isolated from unrelated upstream work and preserves the
existing Base64-wrapped, `text/plain` subscription response.

## Compatibility Boundary

The server will always include valid AnyTLS links in the v2rayN renderer. It
will not suppress them based on the reported client version. AnyTLS import
requires v2rayN 7.13.8 or newer; older clients must be upgraded.

## Testing

A focused Node test will execute the PHP renderer directly and assert:

- `General` allows AnyTLS and exposes `buildAnyTLS()`.
- A synthetic IPv6 AnyTLS node renders the expected URI with encoded name,
  SNI, and `insecure` fields.
- A complete AnyTLS-only General response decodes to that URI rather than an
  empty string for a v2rayN client.
- Static routing flags continue to cover `v2rayn`, which represents both the
  explicit flag and User-Agent matching path in `ClientController`.

Verification also includes PHP syntax checking and a mounted-container runtime
probe against the locally running Octane stack.
