# Third-Party App Download Isolation Design

## Summary

Xboard will support publishing third-party application installers to the public download catalog without allowing those releases to participate in Elephant's automatic update channel. Administrators retain full control over publishing, disabling, and deleting every version; the system will not automatically hide older releases or select a single visible release.

The existing application, version, artifact, and download-log models remain in use. Isolation is enforced by a new application-level distribution scope and by server-side validation and filtering, rather than relying only on application keys or frontend behavior.

## Data Model

Add `distribution_scope` to `v2_distribution_apps` with these values:

- `download_only`: third-party application; visible in the public catalog but excluded from automatic updates.
- `official_update`: official Elephant application; visible in the public catalog and eligible for automatic updates.

The database default is `download_only`. `app_key` remains globally unique and continues to group versions belonging to the same application.

The migration marks only known official applications as `official_update`:

- `elephant-route-android`
- `elephant-route-desktop`
- `elephant-route-mac`

All other existing and future records remain `download_only`. The migration does not change versions, artifacts, publication state, or download logs. Missing official records are skipped safely.

## Reserved Official Identities

Official identities are reserved and validated together with platform:

| Platform | Official app key |
| --- | --- |
| Android | `elephant-route-android` |
| Windows | `elephant-route-desktop` |
| macOS | `elephant-route-mac` |

A `download_only` application cannot use a reserved key. An `official_update` application must use the reserved key for its platform. The backend enforces these constraints even when requests bypass the admin page.

An application with existing versions cannot have its distribution scope changed through the upload form. This prevents an application's complete release history from being promoted into the official update feed accidentally.

## Admin Publishing Flow

The package form adds an application-type selector with two choices:

- Third-party app (download only), selected by default.
- Official Elephant app (automatic updates enabled).

For third-party packages, filename inspection continues to infer name, platform, and version. An internal `app_key` is generated from the application name and remains editable when creating a new application. Repeated uploads reuse an existing application when its identity matches. Reserved official keys are rejected.

For official packages, the form chooses or reuses the official application associated with the selected platform. The official key is displayed but not freely editable. Changing the platform updates the official application target. The server validates scope, platform, and key before saving the version.

Publishing, disabling, and deleting versions retain their current behavior. Third-party history is not automatically hidden or pruned.

## Public Download and Update Behavior

The public download catalog continues returning all active applications with enabled, published versions and attached artifacts. It does not filter by `distribution_scope`, and its response contract remains unchanged.

The update-check endpoint requires all matched applications to have `distribution_scope=official_update`. A request that supplies the key of a third-party application returns the existing no-update response:

```json
{
  "has_update": false,
  "force": false,
  "latest": null
}
```

Version comparison, architecture matching, channels, signed download URLs, and client request/response formats otherwise remain unchanged. This feature does not alter the client update contract. The existing difference between the macOS distribution record (`elephant-route-mac`) and the desktop client's default key (`elephant-route-desktop`) is documented as a separate compatibility issue rather than being silently changed in this work.

## Validation and Error Handling

- Reject a third-party application that uses a reserved official key with an explicit admin-facing validation error.
- Reject an official application whose key does not match its platform.
- Reject an official version whose platform conflicts with its application's reserved identity.
- Preserve the safe `download_only` default when application type is absent from legacy or malformed requests.
- Return no update, rather than a validation error or third-party metadata, when an update request identifies a download-only application.
- Leave artifact storage and download logging unchanged.

## Testing and Acceptance Criteria

Automated coverage will include:

- Migration defaults all unknown applications to `download_only` and promotes only the known official keys.
- Admin application validation rejects reserved-key misuse and official platform/key mismatches.
- The admin form defaults to third-party, generates a non-reserved key, and locks official identity selection appropriately.
- A third-party Windows release at `2.5.1` cannot supersede the official Windows `1.6.3` update feed.
- Update checks for a download-only application return `has_update=false`, `force=false`, and `latest=null`.
- Official Android, Windows, and macOS releases remain eligible for update checks when queried with their matching reserved key.
- Public download responses include both official and third-party applications without exposing the new field as a required client contract.
- Administrators can independently publish and disable any third-party version, including older versions.
- Existing artifacts, signed downloads, and download counts continue to work.

Acceptance requires the focused controller, migration, and admin-page tests to pass, followed by the repository's complete Node test suite and relevant PHP syntax checks.

## Out of Scope

- Automatic updates for third-party applications.
- Automatic latest-version selection or hiding older packages on the public page.
- Refactoring the download catalog and update feed into separate storage systems.
- Changing existing installer contents, package identifiers, signatures, or client update request contracts.
- Aligning the existing macOS client build key with the macOS distribution record.
