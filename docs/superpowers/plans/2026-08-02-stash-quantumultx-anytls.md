# Stash and Quantumult X AnyTLS Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver native AnyTLS subscription entries to supported Stash and Quantumult X clients.

**Architecture:** Keep the existing per-client renderers and add AnyTLS at the same whitelist, dispatch, and builder boundaries used by other protocols. Validate the emitted YAML and Base64-wrapped line formats with focused Node tests that invoke the PHP renderers.

**Tech Stack:** PHP 8, Laravel, Symfony YAML, Node.js built-in test runner

---

### Task 1: Add failing Stash AnyTLS coverage

**Files:**
- Modify: `tests/stash-subscription-compatibility.test.js`

- [x] Add a PHP-backed test fixture that instantiates the Stash renderer with an AnyTLS server using `protocol_settings.tls`.
- [x] Assert the filtered server remains available on Stash 3.3.0 and the builder emits `type`, `server`, `port`, `password`, `sni`, `skip-cert-verify`, and `udp` correctly.
- [x] Run `node --test tests/stash-subscription-compatibility.test.js` and confirm the new assertion fails because AnyTLS is filtered.

### Task 2: Implement Stash AnyTLS rendering

**Files:**
- Modify: `app/Protocols/Stash.php`

- [x] Add `Server::TYPE_ANYTLS` to the whitelist and dispatch it in `handle()`.
- [x] Change the compatibility version from the old sentinel to Stash 3.3.0.
- [x] Read SNI and certificate verification from `protocol_settings.tls` and omit empty optional fields.
- [x] Re-run `node --test tests/stash-subscription-compatibility.test.js` and confirm all Stash tests pass.

### Task 3: Add failing Quantumult X AnyTLS coverage

**Files:**
- Modify: `tests/quantumultx-subscription-compatibility.test.js`

- [x] Extend renderer inspection to require AnyTLS in the whitelist and require a builder.
- [x] Assert native standard-TLS line output, bracketed IPv6, SNI, certificate verification, UDP relay, and a Unicode tag.
- [x] Run `node --test tests/quantumultx-subscription-compatibility.test.js` and confirm failure because the whitelist and builder are absent.

### Task 4: Implement Quantumult X AnyTLS rendering

**Files:**
- Modify: `app/Protocols/QuantumultX.php`

- [x] Add `Server::TYPE_ANYTLS` to the whitelist and dispatch it in `handle()`.
- [x] Add `buildAnyTLS()` using Quantumult X's native `anytls=` syntax and Xboard's canonical TLS fields.
- [x] Re-run `node --test tests/quantumultx-subscription-compatibility.test.js` and confirm all Quantumult X tests pass.

### Task 5: Verify the combined subscription surface

**Files:**
- Verify: `app/Protocols/Stash.php`
- Verify: `app/Protocols/QuantumultX.php`
- Verify: `tests/stash-subscription-compatibility.test.js`
- Verify: `tests/quantumultx-subscription-compatibility.test.js`

- [x] Run `php -l app/Protocols/Stash.php` and `php -l app/Protocols/QuantumultX.php`.
- [x] Run the two focused AnyTLS compatibility test files.
- [x] Run all `tests/*subscription*.test.js` files and confirm zero failures.
- [x] Inspect `git diff --check`, `git diff --stat`, and the final diff for unrelated changes.
