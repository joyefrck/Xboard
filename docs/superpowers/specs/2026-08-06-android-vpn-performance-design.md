# Android VPN Performance Stabilization Design

## Problem

The connected Pixel 6 Pro running `com.elephantroute` 1.6.1 showed 24-35% CPU while the activity was backgrounded, roughly 584 MB RSS after a short session, per-second notification posts, and historical ANRs at 1.5-3.2 GB RSS. Runtime logs and the active client source identify four coupled hot paths:

1. sing-box is forced to `trace` and all core logs are bridged into Logcat.
2. one-second status callbacks rebuild the foreground notification and broadcast into Flutter.
3. Android also opens the Clash traffic stream, so the same traffic state reaches `VpnProvider` twice.
4. `VpnState.copyWith()` retains a previous latency map, so later status-only events repeatedly reprocess stale latency data.

Node selection also calls `SingBoxEngine.stop()` synchronously from `onStartCommand()`, matching the historical service-execution ANR risk.

## Chosen Approach

Apply a focused hot-path correction without moving the VPN service into a separate Android process in this iteration. A process split changes lifecycle and IPC behavior and is only justified if a one-hour soak test still shows unbounded memory after the confirmed event and logging storms are removed.

The release runtime will:

- use sing-box `warn` logging and not subscribe to `CommandLog`;
- keep one-second native status for responsive foreground speed display, but update the system notification at most once every ten seconds;
- use native Android traffic as the single source and not open a second Clash traffic stream;
- treat latency maps as one-shot events and clear them on the next status-only update;
- avoid verbose per-node/group logs and use the HTTP connection-latency session as Android's authoritative latency source;
- disconnect every command client explicitly; and
- perform outbound restart work off the Android main thread.

Debug builds may retain the group subscription for diagnostics, but production must not subscribe to core logs. User-visible behavior, VPN configuration, node selection semantics, and non-Android traffic monitoring remain unchanged.

## Data Flow

`CommandStatus` emits at one-second intervals. `SingboxVpnService` stores counters, conditionally updates the notification, and sends one explicit in-app broadcast. `MainActivity` forwards the event to `RealVpnService`, which produces a `VpnState` with either a one-shot latency map or a cleared latency map. `VpnProvider` uses that native stream directly on Android; macOS and Windows retain their existing traffic behavior.

Android latency testing continues through the four loopback workers and `AndroidLatencySession`. Persistent production `CommandGroup` traffic is unnecessary because those HTTP results are already authoritative.

## Failure Handling

- Notification update failures are logged as warnings without interrupting VPN status delivery.
- Command client disconnect failures remain isolated with `runCatching`.
- Concurrent outbound selections are serialized by the existing restart flag and do not block `onStartCommand()`.
- The existing Clash traffic reconnect behavior remains active on platforms that still use it.

## Verification

Automated checks must cover explicit latency-map clearing, Android native-only traffic mode, notification throttling, and release command selection. Run focused Flutter and Kotlin tests, full Flutter tests, `flutter analyze`, Gradle unit tests, and a release APK build.

On the connected phone, install the release APK and compare against the recorded baseline:

- no `TRACE` or per-packet `SingBoxCore` stream in release Logcat;
- no per-second `NotificationManagerService:post`;
- background CPU materially below the 24-35% baseline when traffic is idle;
- RSS remains bounded during the observation window; and
- VPN connection, node selection, latency testing, and foreground counters still work.

