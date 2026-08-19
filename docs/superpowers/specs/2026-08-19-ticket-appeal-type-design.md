# Ticket Appeal Type Design

## Goal

Replace the user-facing ticket priority with a required appeal type while preserving the existing compiled frontend and API contract.

## Type contract

The existing `level` field remains the transport and database field for compatibility, but its business meaning becomes appeal type:

- `0`: 节点问题
- `1`: 退款
- `2`: 使用方法
- `3`: 推广佣金提现
- `NULL`: legacy non-withdrawal ticket with no appeal type

Only `0`, `1`, and `2` are available to users creating an appeal. Type `3` is system-only and is assigned exclusively by the promotion commission withdrawal workflow.

## Data migration

Change `v2_ticket.level` to a nullable integer. Existing promotion commission withdrawal tickets are identified by the system-generated withdrawal subject and assigned type `3`; every other existing ticket is assigned `NULL`. Because the migration intentionally discards the old low/medium/high meaning, a rollback cannot recover the original priorities: rollback maps `NULL` to the old low value `0`, maps the system-only value `3` back to the former withdrawal value `2`, and restores the non-null schema so the old application remains operable.

Fresh installations create `v2_ticket.level` as nullable so the base schema matches upgraded installations.

## Backend behavior

The manual ticket request continues accepting the `level` request key to remain compatible with the compiled client. Validation requires one of `0`, `1`, or `2` and uses appeal-type error wording.

`TicketService::createTicket()` accepts a nullable type. The promotion commission withdrawal controller passes `3`; users cannot create a type-`3` ticket through the manual save endpoint.

The user and admin APIs continue returning `level`, including `NULL`, so existing clients do not require a contract migration.

## User interface

The ElephantRoute user interface displays the column and form label as `申诉类型`, the placeholder as `请选择申诉类型`, and the fixed choices as `节点问题`, `退款`, and `使用方法`. Type `3` displays as `推广佣金提现` when system tickets appear in lists or details. A `NULL` historical value renders as an empty cell rather than being treated as type `0`.

The bundled user frontend is patched at its existing ticket option and renderer boundary, and the theme/public copies stay byte-identical. The existing ElephantRoute text-normalization override is updated so later DOM passes do not restore priority wording.

## Admin interface

The admin ticket table and detail copy display `申诉类型` and the same four labels. `NULL` remains blank. Because the admin application is distributed as a compiled bundle plus locale files, the change is applied at the smallest ticket-specific mapping and translation boundaries and covered by source-contract tests.

## Verification

Regression tests cover the numeric contract, manual validation, withdrawal type assignment, nullable migration behavior, user bundle mappings, synchronized theme assets, admin copy, and blank historical rendering. PHP syntax checks, focused Node tests, and whitespace/diff checks must pass.
