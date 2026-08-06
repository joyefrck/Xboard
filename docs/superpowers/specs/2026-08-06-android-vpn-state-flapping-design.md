# Android VPN State Flapping Repair Design

## Problem

On the connected Pixel 6 Pro, `com.elephantroute` 1.6.7 displays the main VPN switch alternating between on and off after automatic node selection. Android reports one continuously connected `tun0` VPN, while the app process emits two approximately one-second `VPN_STATE` streams. Flutter therefore receives alternating `connected` and stale `disconnected` states, and `NodeProvider` schedules another delayed full latency test after every false reconnect.

The lifecycle gap is in `SingboxVpnService.connectCommandClient()`: it starts an untracked thread, sleeps for 1.5 seconds, and then creates status subscriptions. `stopVpn()` can disconnect the currently registered clients but cannot cancel a pending connection thread. A destroyed service instance can consequently create a command subscription after cleanup and remain retained as its callback handler.

## Considered Approaches

1. **Generation-guard native command clients and deduplicate Dart latency scheduling.** This is the selected approach. It closes the resource leak at its source and prevents one malformed state sequence from multiplying background work.
2. **Native generation guard only.** This is smaller, but leaves `NodeProvider` vulnerable to duplicate connection events from future platform or lifecycle faults.
3. **Replace broadcasts and service-owned command clients with a bound state service.** This gives the strongest ownership model but changes IPC and lifecycle behavior beyond the scope of this regression.

## Chosen Design

`SingboxVpnService` will own a monotonically increasing command-client generation. Every connect request captures its generation before waiting. Stop, restart, and destruction invalidate the generation and disconnect existing clients. After the wait and after client creation, the task must verify that the service is not destroyed, the VPN is still connected, and its generation is still current. A stale task closes any clients it created instead of publishing them.

The lifecycle rules will live in a small pure Kotlin policy so the stale-generation behavior can be tested without constructing an Android service. The service will use that policy to decide whether a delayed connection attempt may proceed.

`NodeProvider` will retain one cancelable connection-latency timer. A new real `connected` transition replaces the previous timer; any non-connected transition and `dispose()` cancel it. When the timer fires, it checks that the VPN remains connected before starting the test. This does not hide native status faults; it only prevents duplicate queued work.

`MainActivity` will preserve the last native status when a payload omits `status` rather than synthesizing `disconnected`. Status-carrying service broadcasts remain the authority, while latency-only payloads cannot turn the switch off.

## Failure Handling

- Stale command-client attempts terminate silently after closing their locally created clients.
- A failed command-client connection still disconnects the active clients only when the failing attempt owns the current generation.
- VPN stop and service destruction invalidate pending attempts before asynchronous cleanup begins.
- Latency scheduling cancellation does not change manual latency testing or node-selection semantics.

## Verification

Automated tests will cover stale command-client generations, destruction/stop invalidation, one active delayed latency task, cancellation on disconnect, and status preservation for payloads without `status`. The full gate is focused Flutter tests, full Flutter tests, `flutter analyze`, Android unit tests, and a release arm64 APK build.

The APK will then be installed on the connected Pixel 6 Pro. Acceptance requires one native `VPN_STATE` status cadence, no alternating Flutter `connected/disconnected`, no repeated two-second latency scheduling, a continuously connected Android VPN, and correct manual on/off behavior across repeated automatic-node sessions.
