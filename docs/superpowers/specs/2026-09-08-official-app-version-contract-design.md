# Official App Version Contract Design

## Problem

The App download admin form currently defaults every new release to a calendar
version such as `2026.09.08` and a Unix timestamp build number. That convention
does not match the version embedded in official Elephant Network installers,
such as `2.0.6`. Both the update API and the desktop client compare numeric
version components, so `2026.09.08` is treated as newer than `2.0.6` and the
already-current Windows client is prompted to download the same installer
again.

The update API also ignores the build number whenever both version strings are
parseable. As a result, two releases with the same semantic version cannot be
ordered by build number for update eligibility.

## Goals

- Require official App releases to use the real semantic version and numeric
  build embedded in the distributed application.
- Prevent the admin form from silently inserting date-based release metadata
  for official Apps.
- Reject invalid official release metadata at the server boundary.
- Compare build numbers when the latest and installed semantic versions are
  equal.
- Preserve the date/timestamp convenience defaults for third-party download-only
  entries, where the fields are catalog metadata rather than an automatic update
  contract.

## Non-goals

- Parsing EXE, APK, or DMG metadata on the server.
- Changing the client-side update implementation.
- Automatically rewriting existing production rows.
- Deploying the code or editing production data as part of the source change.

## Admin Form

The form will expose `build_number` as a visible numeric field instead of a
hidden field. When `distribution_scope` is `official_update`, both version and
build are cleared unless the operator has already entered values, and the form
explains that they must match the application package. The version field uses a
semantic-version example such as `2.0.6`.

When `distribution_scope` is `download_only`, the existing calendar version and
timestamp build defaults remain available. Switching between scope or platform
must resynchronize identity and version-field guidance without overwriting
operator-entered official metadata.

The published-version editor will show both `version` and `build_number`,
prefilled from the selected row. Both fields are editable so an operator can
repair inconsistent historical metadata without deleting and recreating the
release. Identity, platform, channel, architecture, force-update state, and
publication state remain locked.

## Server Validation

`AppPackageController::saveVersion` and `AppPackageController::updateVersion`
remain the authoritative write boundaries. For an official-update application
they will require:

- `version` in numeric semantic form with exactly three components, accepting an
  optional leading `v` and optional prerelease/build suffix;
- `build_number` as the existing positive integer;
- the existing platform-to-official-app-key match.

Download-only applications retain the current permissive version string rule.
Validation errors will use a Chinese operator-facing message explaining that
the official version must match the package version, for example `2.0.6`.
The update endpoint will accept `build_number` as a required positive integer
while continuing to prohibit changes to app identity and release-state fields.

## Update Selection

The update API will compare releases in this order:

1. Compare normalized semantic versions.
2. If the semantic versions are equal, compare `build_number`.
3. If either version cannot be normalized, fall back to `build_number`.

An update exists only when the latest semantic version is greater, or when the
semantic versions are equal and the latest build is greater. A lower semantic
version must not become an update merely because it has a larger build number.

The existing client-side comparison remains a defense against inconsistent
server responses. Preventing mixed version schemes at the official release
write boundary is what protects already-released clients.

## Existing Production Record

The current Windows row advertising `2026.09.08` must be corrected separately.
After this change, the operator can edit the existing row and replace both its
version and build with the values from the `2.0.6` installer. This stops the
immediate loop without leaving an inaccurate timestamp build in release and
telemetry data.

## Tests

Focused tests will cover:

- official releases reject a date-style version and accept `2.0.6`;
- download-only releases retain the existing flexible version behavior;
- the admin form does not apply calendar/timestamp defaults to official Apps;
- the edit form prefills and submits both version and build number;
- the edit endpoint accepts a positive build number but continues to reject app
  identity and release-state changes;
- `2.0.6` does not consider an equal-version/equal-build release an update;
- equal semantic versions use build number as the tiebreaker;
- a lower semantic version is not promoted by a larger build number;
- the previously failing `2026.09.08` versus `2.0.6` comparison remains
  demonstrably invalid at the official publish boundary.

## Acceptance Criteria

- A new official Windows release cannot be published with the form's generated
  date version or timestamp build without deliberate matching package metadata.
- A client reporting the same version and build receives `has_update: false`.
- A client reporting the same version and a lower build receives
  `has_update: true`.
- An administrator can correct both version and build on an existing release,
  and reopening the editor shows the saved values.
- Existing download-only App publishing behavior is preserved.
