# FlClash and Hiddify REALITY implementation plan

**Goal:** Restore subscriptions for official FlClash 0.9.3+ and Hiddify 5.5.5+ using TCP REALITY/Vision with X25519MLKEM768.

**Architecture:** Recognize native product identities before compatibility tokens. Keep app version thresholds separate from ElephantNetwork. Admit only ordinary TCP REALITY for the new clients; preserve enhanced capability filtering. Emit Hiddify's native hybrid key share field only for Hiddify hybrid REALITY nodes.

**Tech stack:** PHP 8.2+, Laravel subscription controller, ClashMeta YAML, SingBox JSON, isolated official release cores.

## Tasks

- [x] Add `tests/flclash-hiddify-reality-subscription.php` using the actual subscription pipeline. Cover official composite UAs, native format selection, traffic headers, all-modern subscriptions, malformed/old versions, compatibility tokens, explicit format flags, legacy nodes, enhanced nodes, and secret exclusion. Run it before changes and confirm the modern import fails.
- [x] Update `app/Http/Controllers/V1/Client/ClientController.php` to canonicalize HiddifyNext and parse both products' own versions without borrowing compatibility-token versions or trusting versioned format overrides.
- [x] Update `app/Services/VlessConfigService.php` with independent FlClash 0.9.3 and Hiddify 5.5.5 minimums for plain TCP hybrid REALITY. Keep ElephantNetwork's existing minimum and enhanced support.
- [x] Update `app/Protocols/SingBox.php` to emit `tls.reality.key_share: hybrid` for Hiddify hybrid nodes. Preserve ordinary sing-box output.
- [x] Add the regression command to `.github/workflows/security-checks.yml`. Run the new suite and existing VLESS, Elephant, iOS, v2rayNG, and Passwall suites; lint changed PHP and check the diff.
- [x] Verify the emitted configurations with verified FlClash 0.9.3 and Hiddify 5.5.5 official cores in an isolated REALITY fixture, including wrong-key/short-ID controls. Record exact evidence and limits in `output/flclash-hiddify-reality-20261110/`.

## Scope

This change does not authorize production deployment. Existing unrelated dashboard changes stay outside this work. VLESS Encryption and XHTTP require separate capability validation.
