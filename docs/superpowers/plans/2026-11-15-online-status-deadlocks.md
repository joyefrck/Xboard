# Online Status Deadlock Fix Implementation Plan

> Execute inline in the current authorized session, using the current branch and Xboard production release checks.

**Goal:** Prevent online cleanup/statistics deadlocks and incorrect retention units, then release with evidence.

**Architecture:** Keep statistics atomic, acquire user locks in numeric ID order, retry rolled-back transactions at most three times, and clean expired status one user per transaction with a fresh stale predicate.

**Tech Stack:** Laravel 12, PHP, InnoDB, isolated SQLite/MySQL fixtures, compiled admin JavaScript.

## Tasks

- [ ] Create failing fixtures in `tests/online-status-locking.php` and `tests/online-status-locking-concurrency.php`. Cover lock ordering, full rollback on retry, cleanup rechecking stale status after waiting, deleted users, and no duplicate statistics. Require a localhost temporary test database.
- [ ] Update `app/Jobs/StatUserJob.php` with numeric input ordering and `DB::transaction(..., 3)` while preserving its whole-batch transaction and deletion protection.
- [ ] Update `app/Jobs/UpdateAliveDataJob.php` to sort database updates by user ID and retry the whole database transaction three times.
- [ ] Update `app/Console/Commands/CleanupExpiredOnlineStatus.php` to select IDs, update one user per retried transaction, and reapply both stale conditions at write time.
- [ ] Convert retention minutes to hours in `public/assets/admin/assets/index.js`; run `php scripts/version-admin-assets.php`. Verify rendered 10080-minute output is 168 hours.
- [ ] Add both database checks to `.github/workflows/security-checks.yml`; run focused tests, account deletion regression, admin assets, Horizon provisioning and existing queue gates.
- [ ] Commit and push only scoped files on master; wait for matching CI and amd64/arm64 image success.
- [ ] Prepare a hash-checked release manifest and backup under `/root/xboard-online-status-20261115`, preserve unrelated files and configuration, and deploy via the existing authenticated server session.
- [ ] Run complete production release checks and a fresh connection recheck. Record acceptance and outstanding business/device verification under `output/online-status-release-20261115/`.
