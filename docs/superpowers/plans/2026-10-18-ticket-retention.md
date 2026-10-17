# Ticket retention implementation plan

**Goal:** Enforce the approved 14-day inactivity policy and reclaim the approved host resources.

**Architecture:** An Artisan command delegates preview and chunked pruning to a service. Reply and prune operations serialize on the ticket row; attachment metadata remains until file deletion succeeds so interrupted runs can retry. The existing scheduler runs the command daily at 03:30.

**Tech stack:** Laravel, Eloquent, Flysystem, SQLite fixtures, Docker production runtime.

- [x] Add `tests/ticket-retention.php` with isolated SQLite and temporary storage: cutoff boundary, both statuses, recent replies with stale ticket time, stale/recent legacy orphan messages, dry-run, shared file references, missing files, deletion failure/retry, rollback and idempotency. Run `php tests/ticket-retention.php` and confirm the missing service fails first.
- [x] Add `app/Services/TicketRetentionService.php` and `app/Console/Commands/PruneTickets.php`; modify `app/Console/Kernel.php` and the two reply transaction paths in `app/Services/TicketService.php`. Run the runtime test plus existing ticket attachment tests and `php -l` on changed PHP files.
- [x] Capture production queue baseline, disk baseline, source hashes and scoped file backups. Back up all three ticket tables and image files; verify gzip/archive checksums and restore into an isolated temporary database.
- [x] Install only the changed source files without replacing unrelated production changes. Run the command preview, then the approved first prune. Verify retained ticket IDs, message and attachment counts, image file references and a second no-op run; verify daily schedule.
- [x] Remove the exact approved host resources, configure journald limits and measure disk change. Do not prune all Docker volumes or clear failed jobs/Redis queues.
- [x] Run three queue snapshots 30–60 seconds apart, public/local HTTP, Redis, Horizon, container/PID checks and a fresh terminal-session recheck. Report actual reclaimed storage, backup location, source deployment state and limits of natural business samples.
