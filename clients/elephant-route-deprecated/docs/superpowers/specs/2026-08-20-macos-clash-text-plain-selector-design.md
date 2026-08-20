# macOS Clash Selector Text Response Compatibility Design

## Problem

The macOS client cannot switch nodes while the VPN is connected. The runtime
error is:

`Clash API selector response has no active outbound`

The bundled `sing-box 1.13.15-xboard.1` returns a valid selector document with
an active `now` value, but serves it as `Content-Type: text/plain; charset=utf-8`.
Dio therefore exposes `response.data` as a JSON string. The current
`MacosClashController.selectedOutbound` implementation accepts only a decoded
`Map`, so it rejects the valid live response before issuing the selector PUT.

## Scope

- Normalize only Clash API response bodies that are expected to be JSON.
- Accept either an already decoded map or a JSON object encoded as a string.
- Preserve the existing failure behavior for empty, malformed, or non-object
  responses.
- Keep the current hot-switch, confirmation, connection cleanup, rollback, and
  config persistence flow unchanged.
- Do not restart the TUN runtime as part of node selection.

## Considered Approaches

1. Decode string bodies inside `MacosClashController` before reading fields.
   This is the recommended approach because it contains compatibility at the
   API boundary and covers selector and connection responses consistently.
2. Force Dio's response type to JSON on each request. This is smaller at call
   sites but depends on Dio successfully overriding a non-JSON content type and
   is easier to apply inconsistently.
3. Patch the bundled sing-box Clash API response headers. This changes the
   native core for a client-side parsing issue and would require a new core
   build, so it is outside the minimal repair scope.

## Design

Add a private response-normalization helper in `macos_clash_controller.dart`.
It returns maps unchanged. For string bodies, it performs `jsonDecode` and
returns the decoded value; malformed input remains invalid and is handled by
the controller's existing exception wrapping.

Use the helper in `selectedOutbound` and `activeConnections`. `urlTest` should
also use it because the same live Clash endpoint is served with `text/plain`,
and this avoids silently reporting `-1` when the response contains a valid
delay.

No changes are required in `MacosOutboundSwitchCoordinator` or
`MacosVpnService`.

## Error Handling

- A decoded selector object with a non-empty string `now` succeeds.
- Missing or empty `now` continues to throw
  `Clash API selector response has no active outbound`.
- Malformed JSON is wrapped as `Clash API selector read failed`.
- Existing HTTP status and transport error handling remains unchanged.

## Verification

- Add a failing controller test for a selector JSON body returned as
  `text/plain`.
- Add coverage for connection and delay JSON bodies returned as `text/plain`.
- Run the focused macOS Clash controller and outbound switch tests.
- Run `flutter analyze --no-pub` and the full `flutter test --no-pub` suite.
- Build the macOS arm64 1.6.5 DMG and validate its release contract.

