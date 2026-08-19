# Dedicated Ticket Telegram Bot Design

## Goal

Move every ticket-related Telegram capability from the existing general-purpose bot to a dedicated ticket bot. The new bot must send ticket notifications and let authorized administrators or staff view ticket history, reply, close tickets, inspect the ticket owner's approved user details, and browse that user's orders. The existing bot keeps account binding, traffic and subscription commands, registration notifications, payment notifications, and chat-join handling.

The cutover must not duplicate notifications, leak bot tokens, or allow Telegram callbacks to inspect an arbitrary user. Existing application tables and public API contracts remain unchanged.

## Selected architecture

Use two explicit Telegram bot profiles over a shared bounded HTTP client:

- `general`: the existing bot and existing `send_telegram` queue.
- `ticket`: the new bot and a new `send_ticket_telegram` queue.

The profile chooses the configuration key, Webhook secret, command set, incoming handler, and queue. A raw bot token must never be serialized into a queued job. The ticket job carries only the `ticket` profile identifier and resolves the current Laravel-encrypted setting through a dedicated credential service when the worker executes it.

The existing `TelegramService` currently always prefers the stored general token and `SendTelegramJob` always constructs the default service. That behavior cannot safely deliver for two bots. Refactor the transport boundary so callers select a profile explicitly, while retaining the current retry, timeout, and error-handling behavior.

Ticket behavior is moved out of the general message-command handler into a focused ticket handler. The existing Telegram plugin continues wiring the `ticket.create.after` and `ticket.reply.user.after` hooks, but delegates ticket formatting and dispatch to the dedicated notifier. Incoming ticket updates go directly through the dedicated ticket Webhook controller and handler rather than through the general bot's global command filters.

## Configuration and administration UI

Add a visually separated `工单机器人设置` section under the existing Telegram settings page with:

- `启用工单机器人` switch.
- `工单机器人令牌` password input.
- `验证机器人 / 一键设置 Webhook` action.
- `发送测试消息` action for the authenticated administrator.
- Read-only configured Bot username and Webhook health state.

Use these logical settings:

- `telegram_ticket_bot_enable`
- `telegram_ticket_bot_token`
- `telegram_ticket_webhook_secret`

The settings table is already key/value based, so no schema migration is required. The feature switch in this page is the single source of truth for the delivery channel: false preserves general-bot delivery and true routes exclusively to the ticket bot. The existing Telegram plugin's `enable_ticket_notify` value remains the master on/off control for ticket notifications, but its control is moved into this same section and relabeled `开启工单通知`. It does not select a Bot. Keeping the two orthogonal controls together avoids contradictory states while preserving the existing ability to disable all ticket notifications.

The token is write-only through the administration API:

- Configuration fetch returns `telegram_ticket_bot_configured: true|false`, Bot username, and health state, never the token.
- A blank token submission preserves the stored token.
- Token replacement is a dedicated action, validates the new token with `getMe`, and encrypts it with Laravel `Crypt` before saving it.
- API errors, application logs, audit context, failed-job payloads, and UI notifications never include a token.
- The UI uses a password field and does not repopulate the saved value.

The implementation also changes the existing general-bot token input to a password field, preventing another screenshot from exposing the current token. This visual hardening must not silently overwrite or change the existing stored general token.

The repository contains the administrator application as compiled assets rather than maintainable TypeScript/React source. Unless the matching admin source is supplied before implementation, make the smallest ticket-settings patch at the existing compiled component and locale boundaries and protect it with focused source-contract tests, following the repository's existing admin customization pattern.

## Webhook separation and authentication

Keep the general endpoint:

```text
POST /api/v1/guest/telegram/webhook
```

Add the ticket endpoint:

```text
POST /api/v1/guest/telegram/ticket/webhook
```

The ticket Webhook is registered with Telegram's `secret_token` setting. Incoming ticket requests must compare the `X-Telegram-Bot-Api-Secret-Token` header to the server-side ticket Webhook secret using a timing-safe comparison. The bot token and Webhook secret are different values. The secret is generated server-side and is not shown in the UI.

Register only `message` and `callback_query` as allowed updates for the ticket bot. It does not process account binding, subscription commands, or chat join requests. The general bot no longer executes any `ticket:*` callback, `/ticket` command, or ticket reply flow after cutover.

Webhook setup must:

1. Validate the ticket token with `getMe`.
2. Generate or reuse the server-side Webhook secret.
3. Register the dedicated HTTPS endpoint with allowed updates and the secret.
4. Read back Webhook information and return a sanitized health result.
5. Register only the ticket bot's command list.

## Outbound queues and failure isolation

Create `SendTicketTelegramJob` on `send_ticket_telegram`. Add a fixed one-process Horizon supervisor named `XboardTicketTelegram` with the same safety boundaries as the existing Telegram worker:

- `tries = 1`
- job timeout `50` seconds
- `maxJobs = 25`
- `maxTime = 900`

The general and ticket queues must not share a supervisor. A blocked or rate-limited ticket Bot must not delay registration or payment notifications from the general Bot.

When the ticket bot is enabled, ticket hooks dispatch only to the ticket queue. A ticket-delivery failure is logged and remains observable through the failed job and Webhook status; it must not fall back to the general bot because fallback can duplicate or disclose ticket messages through the wrong channel.

When the ticket bot is disabled, the pre-cutover general bot continues delivering tickets for backward compatibility, subject to the master `enable_ticket_notify` setting. Enabling the ticket bot is the atomic routing boundary: after enablement, no new ticket notification goes through the general bot.

## Recipients and authorization

Continue delivering ticket notifications to users whose Xboard account has a Telegram ID and is either an administrator or staff. Telegram user IDs are stable across bots, but Telegram will not let the new bot initiate a private conversation until each recipient has opened or started it. The ticket bot `/start` response therefore acts as onboarding and readiness validation:

- Authorized administrator/staff: confirm readiness and show the available ticket command.
- Bound but unauthorized user: deny access.
- Unknown Telegram user: deny access without revealing account information.

Every ticket action independently resolves the Telegram update's `from_id` to an Xboard user and requires `is_admin` or `is_staff`. Authorization is checked again for pagination and return buttons; receipt of a previously generated callback is not treated as authorization.

Record structured audit context for ticket-bot operations:

- bot profile
- operator Xboard user ID
- Telegram sender ID
- ticket ID
- action
- success or failure

Ticket replies continue using `TicketService::replyByAdmin()` so the stored message records the real operator. Move the close transition into a shared `TicketService::closeByAdmin()` method used by both Telegram and the admin controller so both paths have identical validation and audit behavior.

## Ticket notification and actions

Ticket creation and user replies generate the existing ticket summary through the dedicated bot. The inline keyboard becomes:

```text
[查看记录] [回复] [关闭]
[用户信息] [订单记录]
```

Keep the existing behavior for:

- Five ticket messages per history page.
- ForceReply for administrator/staff replies.
- Close confirmation before changing status.
- Compact callback payloads within Telegram's callback-data limit.

Add a return-to-ticket keyboard on user and order views. A user or order callback carries the ticket ID, never a user ID supplied by Telegram:

```text
ticket:user:{ticketId}
ticket:orders:{ticketId}:{page}
```

The server loads the ticket, resolves `ticket.user_id`, and then queries the user or orders. This prevents a caller from changing a callback to inspect an unrelated user directly.

Old ticket notifications already sent by the general bot remain in Telegram after cutover. The general bot should recognize their `/ticket`, `ticket:*`, and reply patterns only to return a short migration message directing the operator to the configured ticket Bot; it must not execute the old operation.

## User information view

The user view is read-only and uses an explicit presentation whitelist. It may show:

- Xboard user ID and email.
- Registration and last-login times.
- Active or banned state.
- Current plan and expiration.
- Total, used, and remaining traffic.
- Balance and commission balance.
- Speed and device limits.
- Inviter email or identifier when available.

It must not show or derive:

- Password, password algorithm, or password salt.
- User token or UUID where it functions as a credential.
- Subscription URL.
- API keys, session information, or authentication material.

Do not reuse `User::toArray()` or the admin user transformer as the Telegram payload without filtering; construct the Telegram view from a dedicated whitelist formatter.

## Order history view

Resolve orders only through the ticket owner and display five newest orders per page. Each order summary may contain:

- Creation and payment times.
- Trade number.
- Plan or traffic-package name.
- New purchase, renewal, upgrade, traffic reset, or traffic package type.
- Total amount.
- Pending, processing, cancelled, completed, or discounted status.
- Payment method where available.

Do not expose payment callback identifiers, commission internals, serialized surplus-order metadata, or gateway credentials. Order pages include previous/next navigation and a return-to-ticket button. Empty order history produces a clear read-only message rather than an error.

## Cutover and rollback

Deploy the implementation with `telegram_ticket_bot_enable` disabled. Then:

1. Rotate the general bot token exposed by the supplied screenshot and update its Webhook.
2. Create and save the ticket Bot token.
3. Set the ticket Webhook and verify its sanitized health result.
4. Have every intended administrator/staff recipient start the ticket Bot.
5. Send a test message to the current administrator and then to the intended recipient set.
6. Exercise ticket history, reply, close confirmation, user information, and order pagination on a test ticket.
7. Enable the ticket bot and submit a fresh test ticket.
8. Confirm exactly one notification arrived through the ticket bot and none arrived through the general bot.

Rollback is configuration-first: disable the ticket bot to restore the pre-cutover general-bot ticket route while retaining the new code and settings. Do not delete the new token or Webhook during an emergency rollback. Once the general path is verified, the ticket Webhook can be disabled separately if necessary.

## Error handling

- Invalid or missing ticket Webhook secret returns an authorization failure without processing the update.
- Missing/deleted ticket returns a generic message and answers callback queries so Telegram does not leave the loading state visible.
- Closed tickets reject replies and make repeated close operations idempotent.
- Unauthorized operators receive a generic denial without user or order data.
- Invalid callback payloads are acknowledged and logged without throwing a worker-level exception.
- Telegram 429 and transport retry behavior remains bounded by the existing client policy.
- User or order formatting truncates content to Telegram message limits and escapes Markdown safely.
- A blocked recipient is logged by Xboard user ID and sanitized Telegram error; delivery to other authorized recipients continues.

## Verification

Automated coverage must include:

- Configuration validation, write-only token behavior, and sanitized fetch responses.
- General and ticket Webhook secret isolation.
- Ticket allowed-updates and command registration.
- General bot refusal of ticket actions after cutover.
- Ticket jobs selecting only the ticket profile and queue.
- Horizon supervisor isolation and recycling boundaries.
- Exactly-once route selection when the feature switch changes.
- Administrator/staff authorization and ordinary-user denial for every action.
- Ticket history pagination, reply persistence, and close confirmation/idempotence.
- User callback resolving through the ticket and enforcing the output whitelist.
- Order callback resolving through the ticket owner, newest-first pagination, empty state, and output whitelist.
- Old general-bot buttons returning the migration message without mutating a ticket.
- Token and Webhook secret absence from logs, responses, and serialized jobs.

Runtime acceptance must verify both bots with `getMe`, both Webhook states, the two Horizon queues, a fresh ticket create/reply cycle, a Telegram-originated admin reply, a confirmed close, user details, order pagination, and no duplicate notification in the general bot.
