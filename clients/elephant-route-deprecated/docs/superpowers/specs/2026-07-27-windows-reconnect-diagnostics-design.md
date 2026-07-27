# Windows Reconnect Diagnostics Design

## Context

On Windows 10, the 1.6.3 client can connect successfully once and then report
`sing-box 拒绝了当前配置` on later power-switch attempts. Running the bundled
sing-box `check` command against the generated runtime configuration produces
only compatibility warnings and no fatal validation error.

The native startup classifier currently treats any log line containing
`deprecated` as `core_config_invalid`. sing-box 1.12.25 emits deprecation
warnings for legacy DNS servers, legacy special outbounds, outbound DNS rule
items, and missing `domain_resolver` fields even when the configuration passes
validation. As a result, a later readiness timeout or early process exit is
masked as a configuration rejection.

## Goals

- Never classify warning-only deprecation output as an invalid configuration.
- Preserve the service's observed startup boundary: process exit remains
  `core_exited`, while a live core that does not expose the control API within
  the readiness window remains `control_api_timeout`.
- Keep genuine fatal configuration errors classified as
  `core_config_invalid`.
- Add regression coverage for a deprecation warning combined with a TUN
  failure and for warning-only timeout/exit cases.
- Produce a new Windows x64 installer after focused, full, and Windows-native
  CI verification.

## Non-Goals

- Do not change the eight-second control API readiness deadline without
  evidence that the deadline itself causes the Win10 reconnect failure.
- Do not change TUN addresses, routing, network-interface selection, cached
  subscription configuration, or compatibility environment variables.
- Do not suppress sing-box warnings or modify the bundled sing-box binary.
- Do not claim that correcting the message alone resolves the underlying
  second-start lifecycle failure.

## Design

Keep `ClassifyCoreStartFailure` as the single native classification boundary.
Remove the broad `deprecated` token from the configuration-error signature.
Configuration rejection continues to require explicit failure text such as
`decode config`, `parse config`, `invalid config`, or `unknown field`.

Classification ordering remains specific-first:

1. control-port conflict;
2. missing default interface;
3. explicit configuration rejection;
4. explicit TUN startup failure;
5. empty-log process exit;
6. other process exit;
7. live-process control API timeout.

This ordering ensures a log containing both a compatibility warning and a
specific TUN error is reported as `tun_start_failed`. A log containing only
deprecation warnings falls through to `core_exited` or
`control_api_timeout`, based on the process state already captured by the
service.

## Error Handling

No raw configuration or log text is returned to Flutter. The existing stable
error codes, safe Chinese messages, and optional `core_exit_code` remain the
public contract. Users can continue to inspect
`C:\ProgramData\ElephantNetwork\runtime\sing-box.log` locally when needed.

## Testing

Extend the pure C++ classifier test before changing implementation:

- warning-only live process returns `control_api_timeout`;
- warning-only exited process returns `core_exited`;
- deprecation warning plus TUN failure returns `tun_start_failed`;
- explicit `decode config ... unknown field` remains
  `core_config_invalid`.

Run the focused classifier test in red and green states, then run the full
Flutter suite, analyzer, native C++ tests, `git diff --check`, and the Windows
GitHub Actions workflow. The workflow must build the x64 installer and pass its
install/service/uninstall smoke test before the artifact is delivered.
