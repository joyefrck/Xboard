# Bandwagon Auto Refresh Implementation Plan

> **For agentic workers:** Execute inline in the current session with verification checkpoints. No delegation required.

**Goal:** Add the approved browser-persisted page auto-refresh switch while preserving polling and backend synchronization intervals.

**Architecture:** Keep filter-triggered and manual loads independent from the switch-controlled interval in the existing React page. Persist only a boolean preference with guarded browser storage access, and reuse existing admin controls with a small switch track.

**Tech Stack:** React admin module, CSS, localStorage, existing Node/PHP checks, Playwright CLI and synthetic admin fixture.

1. [x] Update `public/assets/admin/assets/bandwagon-vps.js`: initialize the stored preference (default true, catch storage errors), persist changes, separate filter loads from the interval effect, clear the interval when paused/unmounted, render an accessible switch and dynamic status, refresh immediately when resumed.
2. [x] Update `public/assets/admin/assets/bandwagon-vps.css`: style switch track and checked state with theme colors; allow toolbar wrapping and keep the control visible on narrow screens.
3. [x] Update `docs/bandwagon-vps.md` with the page-only pause behavior. Run `php scripts/version-admin-assets.php` to refresh the shared content version and `node --test tests/bandwagon-vps.test.js tests/admin-asset-cache.test.js tests/node-automation.test.js tests/node-automation-status.test.js` (expect no failures).
4. [x] Start `node tests/node-automation-preview.cjs` on a disposable local port. Use Playwright CLI to verify actual GET requests stop for over 10 seconds while paused, resume immediately and periodically, remain paused after reload, allow manual sync/filter requests and keyboard toggling, and fit desktop/mobile screens. Check guarded storage failure and page navigation cleanup.
5. [x] Inspect `git diff --check` and the final diff; record verification results and report local implementation status.
