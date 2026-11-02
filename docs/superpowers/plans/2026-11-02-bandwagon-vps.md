# Bandwagon VPS Management Implementation Plan

> **For agentic workers:** Execute inline in the current session, with verification checkpoints. No delegation required.

**Goal:** Add the approved VPS inventory, provider usage/status, management controls, root password reset and automation IP usage display.

**Architecture:** Laravel encrypted inventory and a fixed-endpoint KiwiVM adapter; an isolated Redis queue synchronizes cached provider fields. Readable React modules integrate into the existing compiled admin shell. Automation consumes only cached, unambiguous provider IP matches.

**Tech Stack:** PHP 8.2+, Laravel 12, Redis/Horizon, React admin modules, Node test runner, SQLite isolated integration, Playwright CLI.

1. Add migration/model, strict bounded CSV parser and fixed HTTPS client. Verify CSV quoted cells, empty IPs, duplicate/terminated/invalid rows, numeric strings, secret redaction and HTTP failures with `php tests/bandwagon-vps.php`.
2. Add inventory synchronization and password-reset service, admin routes/controller, deduplicated jobs, five-minute scheduler and dedicated fixed Horizon workers. Verify credential replacement invalidation, last-good retention, partial live failure, request confirmation/cooldown, unknown mutation outcomes and no secret persistence.
3. Add readable management and reusable traffic modules, CSS, navigation/locales and import cache versioning. Integrate cached IP traffic into automation fetch and current-IP cell; never call provider from automation fetch.
4. Extend disposable preview with synthetic provider responses. Check desktop/mobile layouts and management flows through Playwright. Run Node utility tests, isolated PHP integration, existing automation/API and Horizon/asset regressions; update asset version after final edits.
5. Record commands, results and untested live-provider boundaries in docs/bandwagon-vps.md; review `git diff --check` and final scope before reporting completion.
