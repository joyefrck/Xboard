# Windows In-Process Core Service Design

## Context

The Windows 1.6.3 client currently installs a permanent
`ElephantNetworkService.exe` broker. When the user enables TUN mode, the broker
writes `config.json`, launches `sing-box-windows-amd64.exe` as a suspended child
process, assigns it to a kill-on-close Job Object, resumes it, and waits eight
seconds for `127.0.0.1:9090/version`.

On the affected Windows 10 system, the service-launched child remains alive for
the entire readiness window but never listens on port 9090. The same bundled
binary and generated configuration, launched manually from an elevated
interactive PowerShell, expose the control API in one second. Configuration
validation succeeds and the log contains only compatibility warnings. This
places the failure at the service-to-child-process boundary rather than in the
configuration, cache, control API client, or TUN configuration itself.

ClashMi uses a materially different Windows architecture. Its release contains
`clashmiService.exe` but no separate Mihomo executable. The service binary's Go
build metadata and package strings include Mihomo, libclash, Wintun, and the
Windows service APIs. Its Flutter layer prepares a service configuration and
starts the service directly, then stops and uninstalls the service on Windows.
The relevant architectural property is that the privileged service is the
proxy core; it does not create a second proxy-core process.

## Goals

- Run the sing-box TUN core inside the privileged Windows service process.
- Remove the `CreateProcessW`, suspended-thread, inherited-standard-handle, and
  Job Object boundary from production TUN startup.
- Preserve the existing Flutter-to-service named-pipe protocol and stable
  user-facing error codes where their meanings remain valid.
- Keep standard-user one-click connection after installation, with no UAC
  prompt on every toggle.
- Shut down sing-box through its normal cancellation and close APIs instead of
  force-terminating a child process.
- Preserve Windows 10 and Windows 11 x64 support, configuration sanitization,
  control API access, traffic statistics, node switching, and speed-test
  behavior.
- Produce an installable, upgrade-safe Windows x64 package with native,
  Flutter, and installer verification.

## Non-Goals

- Do not switch the Windows client from sing-box to Mihomo.
- Do not copy ClashMi's private `libclash-vpn-service` implementation.
- Do not launch the core in the interactive desktop as LocalSystem or require
  the desktop application to run as administrator.
- Do not fix the problem by extending the existing eight-second child-process
  timeout.
- Do not change subscription parsing, node selection, routing policy, TUN
  addressing, or server-side APIs.
- Do not remove the standalone sing-box binary until all non-TUN consumers,
  diagnostics, and latency paths have been audited and migrated.

## Architecture

Replace the C++ broker executable with a Go Windows service that statically
links sing-box 1.12.25 and hosts the core in-process.

```text
ElephantNetwork.exe
        |
        | JSON messages over \\.\pipe\ElephantNetworkService.v1
        v
ElephantNetworkService.exe (LocalSystem, Windows SCM service)
        |
        | box.New -> Start -> Close in the same process
        v
sing-box TUN + Clash API on 127.0.0.1:9090
```

The service will be built from a pinned Go module under the Windows client
tree. It will use the same sing-box version and feature tags as the bundled
Windows core. The service will implement the Windows SCM lifecycle with
`golang.org/x/sys/windows/svc` and the named pipe with a Windows-native Go pipe
library.

The installer will continue to register `ElephantNetworkService` as an
automatic LocalSystem service. In-place upgrades will stop the old C++ service
before replacing it with the Go service. The service name, display name, pipe
name, installation directory, and ProgramData runtime directory remain stable.

## IPC Compatibility

The service will preserve protocol version 1 and the existing request methods:

- `getStatus`
- `getNetworkProfile`
- `start`
- `prepareSpeedTest`
- `stop`
- Clash API GET and PUT forwarding used by the Flutter bridge

Responses retain the existing JSON status shape, including `status`, `mode`,
traffic counters, `core_version`, `error_code`, `error_message`, and optional
`core_exit_code`. Since the core no longer has a child PID, `core_pid` will be
the service PID while connected and zero while disconnected. Flutter code must
not use that value to terminate a process.

The named pipe will retain a restrictive local ACL, bounded message size, JSON
validation, fixed method allowlist, and fixed Clash API destination. No request
may specify an executable path or arbitrary command.

## Core Lifecycle

### Start

1. Validate the request size and required TUN configuration.
2. Detect the active physical IPv4 default interface and a non-conflicting TUN
   subnet using the existing selection rules.
3. Reject a pre-existing listener on control port 9090 with
   `control_port_in_use`.
4. Write the sanitized configuration to the ProgramData runtime directory for
   local diagnostics.
5. Apply the three sing-box 1.12 compatibility environment switches before
   decoding the configuration.
6. Decode the configuration into `option.Options`.
7. Create a cancellable service context and construct `box.Box`.
8. Call `Start` in-process and wait for completion or a bounded 60-second
   startup deadline.
9. Confirm that the local Clash API responds before reporting `connected`.

Only one core instance may exist. Concurrent or repeated start requests are
serialized. A start request received while connected returns the existing
connected state when the effective configuration is unchanged; otherwise it
performs an orderly stop before starting the replacement instance.

### Stop

1. Mark the runtime `disconnecting`.
2. Cancel the core context.
3. Call `box.Close`.
4. Wait for orderly shutdown with a bounded deadline.
5. Release the instance and report `disconnected`.

SCM stop and shutdown controls use the same path. No Job Object,
`TerminateProcess`, or `TerminateJobObject` participates in normal lifecycle
management.

### Unexpected failure

The service owns the in-process core goroutine and records a safe startup or
runtime failure category. Explicit configuration decode failures remain
`core_config_invalid`; TUN creation failures remain `tun_start_failed`; port
conflicts and default-interface failures retain their existing codes. A
startup deadline is reported as `core_start_timeout`, not as the old
child-process `control_api_timeout`.

Raw configuration and credentials are never included in IPC errors.

## Logging

Core logs continue to be written to
`C:\ProgramData\ElephantNetwork\runtime\sing-box.log`. The service adds a small
separate lifecycle log containing timestamps, state transitions, startup
duration, and safe error categories. It must not record configuration JSON,
node addresses, UUIDs, passwords, or subscription URLs.

Log reads used for error classification remain bounded. Compatibility warnings
do not independently make startup fail.

## Network Profile Detection

Port the existing Windows route and adapter selection algorithm to a small Go
package backed by `golang.org/x/sys/windows`. Preserve these behaviors:

- select an active, non-loopback physical IPv4 default route;
- ignore ElephantNetwork and other TUN adapters;
- bind direct traffic to the selected default interface;
- choose a TUN IPv4 subnet that does not overlap existing routes;
- keep `strict_route` disabled on Windows 10 and enabled on Windows 11.

The algorithm will expose a platform-independent selection function so route
fixtures can be unit tested without a live Windows routing table.

## Packaging and Upgrade

- Pin the Go toolchain and sing-box module version used by CI.
- Build the service with the required sing-box feature tags for Windows x64.
- Continue packaging the standalone sing-box executable during the first
  migration release for diagnostics and any remaining isolated latency path.
- Stop the installed service before overwriting its executable.
- Configure the existing service record to the new binary and restart it.
- Preserve ProgramData on data-retaining upgrades.
- Uninstall stops and deletes the service and removes only application-owned
  runtime data selected by the user.

The migration must not introduce a second persistent Windows service.

## Testing

### Go tests

- protocol request parsing and response serialization;
- start/stop state-machine serialization;
- repeated start and stop idempotency;
- configuration size and TUN requirement validation;
- warning-only log classification;
- startup timeout cancellation;
- route and TUN-subnet selection fixtures;
- secret-redaction checks for logs and IPC errors.

The core constructor and Windows APIs will be injected behind narrow
interfaces so lifecycle tests do not require a live TUN adapter.

### Flutter and contract tests

- existing Windows service protocol tests remain green;
- `core_pid` is treated as informational only;
- stable errors map to the existing Chinese messages;
- the distribution contract requires the new service binary and forbids
  production TUN startup through `CreateProcessW`.

### Windows CI

- build the Go service and Flutter Windows application;
- run Go, native, Flutter, analyzer, and distribution-contract tests;
- install the generated package silently;
- verify the SCM service path, account, start type, and running state;
- exercise pipe status/start/stop using a safe CI fixture;
- verify service stop and uninstall leave no application-owned service or core
  process;
- run `git diff --check` and record the installer SHA-256.

The final handoff will distinguish CI verification from the user's Win10
runtime acceptance. No additional diagnostic scripts will be requested from
the user.

## Rollback

Retain the last child-process-based installer and its checksum until the new
package passes Win10 acceptance. If the in-process service cannot start, the
upgrade can reinstall the previous package without changing subscription or
user configuration formats.

