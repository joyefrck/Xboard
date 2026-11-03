# Passwall REALITY Implementation Plan

**Goal:** Restore native Passwall TCP REALITY subscription imports without enabling enhanced transports.

**Architecture:** Recognize anchored Passwall identities in ClientController, register passwall2 in General, and narrowly permit standard TCP REALITY in VlessConfigService. Preserve the existing URI renderer.

**Tech Stack:** PHP subscription controller, Lua upstream importer, Python offline harness.

- [x] Add actual subscription regression fixtures in `tests/passwall-reality-subscription.php`; prove failure against original tracked source.
- [x] Update `app/Http/Controllers/V1/Client/ClientController.php`, `app/Protocols/General.php`, and `app/Services/VlessConfigService.php` for the approved scope.
- [x] Run `php tests/passwall-reality-subscription.php` and existing VLESS subscription checks; add the test to `.github/workflows/security-checks.yml`.
- [x] Execute the official Passwall and Passwall2 Lua importer against generated fixtures; record pinned source hashes and validation limits.
- [x] Review `git diff --check` and final diff. Leave production unchanged.
