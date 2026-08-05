# macOS TUN Helper CPU Busy-Loop Fix Design

## Problem

`ElephantTunHelper` keeps a `FileHandle.readabilityHandler` installed after the
child `sing-box` process closes its output pipe. At EOF, `availableData` is
empty, but the handler returns without unregistering itself. Foundation then
dispatches the readable EOF repeatedly, consuming a full CPU core per stale
handler. Repeated TUN starts can leave more than one stale callback.

The helper log is also unbounded. The current installation has accumulated a
138 MB `tun-helper.log`, so the same change should bound future log growth.

## Selected Approach

Keep the existing asynchronous `Pipe` reader, but make its ownership explicit:

- Store the active `Pipe` on `TunHelper` instead of only in a local variable.
- Protect the active-pipe reference with a dedicated lock because output,
  process termination, and XPC stop calls run on different threads.
- Use one idempotent cleanup path that unregisters the handler, closes both
  file handles, and only clears the stored property when it still identifies
  the same pipe.
- Invoke cleanup on EOF, child termination, launch failure, and explicit stop.
- Keep `coreProcess` behavior and TUN routing unchanged.

For logging, rotate before an append would continue an already-full active log.
Keep the active file plus two archives, each capped at approximately 10 MB.
Rotation failures must not crash or prevent the TUN process from running.

## Alternatives Considered

1. Only set `handle.readabilityHandler = nil` at EOF. This stops the observed
   loop but leaves lifecycle cleanup incomplete when a process is stopped or
   fails to launch.
2. Replace the callback with a blocking read loop. This can work, but requires
   a larger concurrency rewrite and is unnecessary for the current defect.
3. Explicit pipe ownership and unified cleanup. This is selected because it
   fixes the root cause on every exit path while preserving current behavior.

## Verification

- Add a source contract test that requires stored pipe ownership, EOF cleanup,
  termination cleanup, stop cleanup, and bounded-log constants.
- Confirm the new test fails before the implementation.
- Run the focused Flutter contract test after implementation.
- Compile `main.swift` as an optimized arm64 macOS helper.
- Run a Foundation EOF harness proving the cleanup pattern produces one EOF
  callback rather than hundreds of thousands per second.
- Do not replace or restart the installed root helper during source validation;
  installation is a separate administrator-authorized release step.

