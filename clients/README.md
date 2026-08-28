# Client Applications

This directory contains client application code that is separate from the main
Xboard Laravel backend.

## Active Client

The active Android, Windows, and macOS client is the public GPLv3
[ElephantNetwork](https://github.com/joyefrck/ElephantNetwork) fork based on
FlClash. It is maintained in its own repository so upstream history and the
corresponding source for every binary release remain available.

`android-singbox/` is an inactive Android experiment and is not a production
release source.

## Archived ElephantRoute Client

`elephant-route-deprecated/` is the frozen legacy Flutter client for Android,
macOS, and Windows.

- Last production version: 1.6.9
- Android package name: `com.elephantroute`
- Status: immutable archive; no feature development or bug fixes
- Archive tag: `elephant-route-legacy-1.6.9-archive`

The directory remains available for upgrade compatibility checks, historical
builds, and migration evidence. Do not copy new client functionality into it.

## Historical Android Experiment

The native Android experiment can still be built for research from its own
directory:

```bash
cd clients/android-singbox
./gradlew assembleDebug
./gradlew assembleRelease
```

## Distribution Backend

`app-distribution/` intentionally remains at the repository root. It is a
standalone Laravel backend for release distribution and telemetry, not client
application source code.
