# Windows ClashMi Network Compatibility Design

## Problem

On Windows 10 and Windows 11, the same node can open ChatGPT through ClashMi
but not through Elephant Network. Runtime evidence shows that Elephant Network
does route ChatGPT through the selected node and can complete an IPv4 TLS
connection to Cloudflare. The failure is therefore in client-side network
behavior, not the node, DNS reachability, or routing rule selection.

## Reference behavior

ClashMi uses Mihomo. Its current Windows defaults differ from Elephant Network
in two important ways:

- IPv6 is disabled in the global configuration and DNS configuration.
- TUN is disabled on Windows by default, so browser traffic sent through the
  system proxy does not carry browser QUIC/UDP flows through the node.

Elephant Network intentionally uses TUN for complete application coverage. It
will retain TUN, but its Windows runtime configuration will reproduce the two
relevant ClashMi behaviors: IPv4-only destination resolution and TCP fallback
for QUIC-capable HTTPS traffic.

## Considered approaches

### Replace sing-box with Mihomo on Windows

This provides the closest implementation match, but requires replacing the
service lifecycle, configuration schema, rule-set format, control API
assumptions, installer assets, and upgrade path. It is disproportionate to the
isolated browser compatibility failure.

### Add a Windows system-proxy mode

This matches ClashMi's default more literally, but would cover only
proxy-aware applications and requires crash-safe restoration of the user's
existing WinINet proxy settings. It conflicts with Elephant Network's current
full-device VPN product behavior.

### Preserve TUN and reproduce the effective browser behavior

This is the selected approach. It keeps the current service architecture while
making Windows DNS IPv4-only, removing the injected IPv6 TUN route, restoring
the subscription template's sniff override/NAT settings, and rejecting inbound
UDP/443 so Chromium falls back promptly to TCP through the selected node.

## Runtime configuration

`WindowsVpnService` will continue to remove untrusted subscription TUN and
mixed inbounds, then create one controlled TUN inbound. The inbound will use:

- the dynamically allocated IPv4 address only;
- `domain_strategy: ipv4_only`;
- `endpoint_independent_nat: true`;
- `mtu: 1500`;
- `sniff: true`;
- `sniff_override_destination: true`;
- the existing OS-specific `strict_route` value;
- the existing explicit physical-interface binding.

The DNS configuration will use `strategy: ipv4_only` on Windows. This prevents
new AAAA destinations from entering the browser path even when the physical
interface has native IPv6.

Before the existing routing rules, the sanitizer will add one idempotent rule:

```json
{
  "network": "udp",
  "port": 443,
  "outbound": "block"
}
```

This affects only inbound application QUIC traffic. It does not block
sing-box's own UDP transport sockets for Hysteria2 or TUIC outbounds.

## Compatibility and safety

- Existing selector, CN direct rules, local rule sets, control API, and
  strict-route behavior remain unchanged.
- The rule is inserted only once even if a sanitized configuration is
  sanitized again.
- The implementation does not expose or persist subscription credentials.
- Server-side SingBox and ClashMeta generators are not changed because the
  evidence identifies the Windows runtime boundary, not a subscription-format
  failure.

## Verification

Automated tests will verify IPv4-only DNS/TUN behavior, restored inbound
fields, absence of a TUN IPv6 address, QUIC fallback rule ordering, rule
idempotence, and preservation of Win10/Win11 strict-route behavior. The
generated fixture will also be checked by the bundled sing-box 1.12.25 binary
where the host platform permits schema validation.
