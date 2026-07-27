# Windows 11 Active-Core Latency Design

## Problem

The Windows client can establish its TUN connection on Windows 11, but every
node latency test times out. Windows 10 does not reproduce the failure.

The production connection and the latency test currently use different core
processes:

- `ElephantNetworkService` hosts the active sing-box core.
- `WindowsLatencySession` launches a second standalone sing-box process.
- `curl.exe` connects to that helper through a loopback mixed inbound.

Windows 11 enables sing-box `strict_route`; Windows 10 deliberately leaves it
disabled. The Windows strict-route WFP rules can block the helper process'
localhost path, so the active connection remains healthy while the independent
latency path times out.

## Approved Solution

Use the already-running service core as the authoritative Windows latency
engine:

1. Keep `strict_route` enabled on Windows 11.
2. Call the existing named-pipe `urlTest` service method once per concrete node.
3. Bound parallel work to the requested concurrency, capped at four workers.
4. Convert each service delay into `ConnectionLatencyResult` and deliver the
   existing per-node callback.
5. Cancel queued work when the user stops latency testing or disconnects.
6. Do not launch `WindowsLatencySession`, standalone sing-box, or `curl.exe`
   during an active Windows node latency test.

The service already restricts its probe target to
`https://www.gstatic.com/generate_204` with a three-second sing-box timeout.
This matches the normal Windows latency policy while avoiding a new
LocalSystem arbitrary-URL surface.

## Error Semantics

- A positive service delay is a successful `clashFallback` result.
- A non-positive or malformed delay is a `serviceError`.
- A service call exceeding the caller timeout is a `timeout`.
- A stopped run reports unfinished nodes as `cancelled` and does not emit stale
  per-node callbacks.
- An IPC exception is a `serviceError` for that node and does not abort the
  remaining queue.

## Compatibility

The named-pipe method and Go service protocol do not change. Existing Win10 and
Win11 installers can use the same in-process service implementation. The
standalone sing-box binary remains packaged for offline configuration checks,
but it is no longer part of connected node latency testing.

## Verification

- Unit-test successful, failed, timed-out, and cancelled node results.
- Verify concurrency is bounded and all requested node tags receive a result.
- Verify Win11 continues to receive `strict_route: true`.
- Run focused Flutter tests, the full Flutter test suite, analyzer, Go service
  tests, and the Windows GitHub Actions build.
- Build and publish the unsigned `1.6.5+10605` Windows x64 installer.
