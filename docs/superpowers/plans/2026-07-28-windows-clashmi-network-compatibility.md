# Windows ClashMi Network Compatibility Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Elephant Network's Windows TUN reproduce ClashMi's effective IPv4 and browser TCP behavior so ChatGPT works on Windows 10 and Windows 11.

**Architecture:** Keep the existing sing-box Windows service and controlled TUN inbound. Normalize Windows DNS and TUN to IPv4-only, restore required sniff/NAT fields, and insert an idempotent UDP/443 rejection rule that causes Chromium to fall back from QUIC to TCP.

**Tech Stack:** Flutter/Dart, sing-box 1.12.25 JSON configuration, Flutter unit tests

---

### Task 1: Lock the Windows compatibility contract with tests

**Files:**
- Modify: `clients/elephant-route-deprecated/test/core/singbox/windows_vpn_service_test.dart`

- [ ] **Step 1: Extend the forced-TUN test**

Assert that the generated TUN has only the dynamic IPv4 address and contains:

```dart
expect(tun['address'], ['172.31.255.1/30']);
expect(tun['domain_strategy'], 'ipv4_only');
expect(tun['endpoint_independent_nat'], isTrue);
expect(tun['mtu'], 1500);
expect(tun['sniff_override_destination'], isTrue);
expect((config['dns'] as Map)['strategy'], 'ipv4_only');
```

- [ ] **Step 2: Assert QUIC fallback rule ordering**

Use an input route with an existing DNS rule, then assert:

```dart
final rules = (config['route'] as Map)['rules'] as List;
expect(rules.first, {
  'network': 'udp',
  'port': 443,
  'outbound': 'block',
});
```

- [ ] **Step 3: Add an idempotence regression**

Start from a subscription config that already contains the same UDP/443 block
rule and assert the sanitized result contains exactly one matching rule.

- [ ] **Step 4: Run the focused test and verify it fails**

Run:

```bash
cd clients/elephant-route-deprecated
flutter test test/core/singbox/windows_vpn_service_test.dart
```

Expected: the new IPv4-only and QUIC fallback assertions fail against the old
sanitizer.

### Task 2: Normalize the Windows runtime configuration

**Files:**
- Modify: `clients/elephant-route-deprecated/lib/core/singbox/windows_vpn_service.dart`

- [ ] **Step 1: Normalize DNS to IPv4-only**

Create the DNS map when absent and set:

```dart
dns['strategy'] = 'ipv4_only';
```

- [ ] **Step 2: Build an IPv4-only controlled TUN**

Replace the injected dual-stack address and restore the compatibility fields:

```dart
'address': [tunIpv4Address],
'domain_strategy': 'ipv4_only',
'endpoint_independent_nat': true,
'mtu': 1500,
'sniff_override_destination': true,
```

- [ ] **Step 3: Insert the QUIC fallback rule idempotently**

Before existing route rules, insert:

```dart
const quicFallbackRule = {
  'network': 'udp',
  'port': 443,
  'outbound': 'block',
};
```

Detect an existing rule by matching all four values before insertion.

- [ ] **Step 4: Format the modified Dart files**

Run:

```bash
dart format \
  lib/core/singbox/windows_vpn_service.dart \
  test/core/singbox/windows_vpn_service_test.dart
```

- [ ] **Step 5: Run the focused test**

Run:

```bash
flutter test test/core/singbox/windows_vpn_service_test.dart
```

Expected: all Windows VPN service tests pass.

### Task 3: Verify the client regression surface

**Files:**
- Verify: `clients/elephant-route-deprecated/lib/core/singbox/windows_vpn_service.dart`
- Verify: `clients/elephant-route-deprecated/test/core/singbox/windows_vpn_service_test.dart`

- [ ] **Step 1: Run sing-box core tests**

Run:

```bash
flutter test test/core/singbox
```

Expected: all tests pass.

- [ ] **Step 2: Run static analysis on changed Dart files**

Run:

```bash
flutter analyze \
  lib/core/singbox/windows_vpn_service.dart \
  test/core/singbox/windows_vpn_service_test.dart
```

Expected: no issues found.

- [ ] **Step 3: Review the final diff**

Run:

```bash
git diff --check
git diff -- \
  clients/elephant-route-deprecated/lib/core/singbox/windows_vpn_service.dart \
  clients/elephant-route-deprecated/test/core/singbox/windows_vpn_service_test.dart \
  docs/superpowers/specs/2026-07-28-windows-clashmi-network-compatibility-design.md \
  docs/superpowers/plans/2026-07-28-windows-clashmi-network-compatibility.md
```

Expected: no whitespace errors and only the scoped compatibility changes.
