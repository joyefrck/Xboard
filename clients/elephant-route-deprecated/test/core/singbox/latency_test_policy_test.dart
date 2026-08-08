import 'dart:async';

import 'package:elephant_network/core/singbox/latency_test_policy.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  group('LatencyTestPolicy', () {
    test('tests up to four nodes concurrently', () {
      expect(LatencyTestPolicy.concurrency, 4);
      expect(
        LatencyTestPolicy.windowsConnectionFallbackProbeUrl,
        'https://www.gstatic.com/generate_204',
      );
    });

    test('uses built-in 204 probe URLs before user configured URL', () {
      final urls = LatencyTestPolicy.probeUrls(
        configuredTestUrl: 'https://example.com/custom_204',
        profile: LatencyTestProfile.standard,
      );

      expect(urls, [
        'https://www.gstatic.com/generate_204',
        'http://cp.cloudflare.com/generate_204',
        'https://example.com/custom_204',
      ]);
    });

    test('deduplicates configured URL when it matches a built-in URL', () {
      final urls = LatencyTestPolicy.probeUrls(
        configuredTestUrl: ' http://cp.cloudflare.com/generate_204 ',
        profile: LatencyTestProfile.standard,
      );

      expect(urls, [
        'https://www.gstatic.com/generate_204',
        'http://cp.cloudflare.com/generate_204',
      ]);
    });

    test('Windows connection profile uses HTTPS Anycast for defaults', () {
      for (final configured in <String>[
        '',
        'http://cp.cloudflare.com/generate_204',
        'https://www.gstatic.com/generate_204',
      ]) {
        expect(
          LatencyTestPolicy.probeUrls(
            configuredTestUrl: configured,
            profile: LatencyTestProfile.v2boxConnection,
          ),
          ['https://cp.cloudflare.com/generate_204'],
        );
      }
      expect(
        LatencyTestPolicy.timeoutMsFor(
          LatencyTestProfile.v2boxConnection,
        ),
        5000,
      );
      expect(LatencyTestPolicy.concurrency, 4);
    });

    test('Windows connection profile preserves an explicit custom probe URL',
        () {
      expect(
        LatencyTestPolicy.probeUrls(
          configuredTestUrl: ' https://example.com/custom_204 ',
          profile: LatencyTestProfile.v2boxConnection,
        ),
        ['https://example.com/custom_204'],
      );
    });

    test('Android connection profile uses HTTPS Anycast for defaults', () {
      for (final configured in <String>[
        '',
        'http://cp.cloudflare.com/generate_204',
        'https://www.gstatic.com/generate_204',
      ]) {
        expect(
          LatencyTestPolicy.probeUrls(
            configuredTestUrl: configured,
            profile: LatencyTestProfile.androidConnection,
          ),
          ['https://cp.cloudflare.com/generate_204'],
        );
      }
      expect(
        LatencyTestPolicy.timeoutMsFor(
          LatencyTestProfile.androidConnection,
        ),
        5000,
      );
    });

    test('Android connection profile preserves a custom probe URL', () {
      expect(
        LatencyTestPolicy.probeUrls(
          configuredTestUrl: ' https://example.com/custom_204 ',
          profile: LatencyTestProfile.androidConnection,
        ),
        ['https://example.com/custom_204'],
      );
    });

    test('macOS connection profile uses HTTPS Anycast for defaults', () {
      for (final configured in <String>[
        '',
        'http://cp.cloudflare.com/generate_204',
        'https://www.gstatic.com/generate_204',
      ]) {
        expect(
          LatencyTestPolicy.probeUrls(
            configuredTestUrl: configured,
            profile: LatencyTestProfile.macosConnection,
          ),
          ['https://cp.cloudflare.com/generate_204'],
        );
      }
      expect(
        LatencyTestPolicy.timeoutMsFor(
          LatencyTestProfile.macosConnection,
        ),
        5000,
      );
    });

    test('macOS connection profile preserves an explicit custom probe URL', () {
      expect(
        LatencyTestPolicy.probeUrls(
          configuredTestUrl: ' https://example.com/custom_204 ',
          profile: LatencyTestProfile.macosConnection,
        ),
        ['https://example.com/custom_204'],
      );
    });

    test('selects minimum valid latency from multiple probes', () async {
      final tester = LatencyTester(
        probeUrls: const ['first', 'second', 'third'],
        timeoutMs: 3500,
        probe: (url, timeoutMs) async {
          return switch (url) {
            'first' => -1,
            'second' => 180,
            'third' => 92,
            _ => -1,
          };
        },
      );

      expect(await tester.test(), 92);
    });

    test('starts all probes for a node concurrently', () async {
      var started = 0;
      final allStarted = Completer<void>();

      final tester = LatencyTester(
        probeUrls: const ['first', 'second', 'third'],
        timeoutMs: 3500,
        probe: (url, timeoutMs) async {
          started++;
          if (started == 3) {
            allStarted.complete();
          }

          await allStarted.future.timeout(const Duration(milliseconds: 100));
          return switch (url) {
            'first' => 140,
            'second' => 95,
            'third' => 180,
            _ => -1,
          };
        },
      );

      expect(await tester.test(), 95);
    });

    test('returns timeout when all probes fail', () async {
      final tester = LatencyTester(
        probeUrls: const ['first', 'second'],
        timeoutMs: 3500,
        probe: (url, timeoutMs) async => -1,
      );

      expect(await tester.test(), -1);
    });

    test('requires connected VPN for real Android Windows and macOS tests', () {
      expect(
        LatencyTestPolicy.requiresConnectedVpn(
          isWeb: false,
          isAndroid: true,
          isWindows: false,
          isMacOS: false,
          isMockVpn: false,
        ),
        isTrue,
      );
      expect(
        LatencyTestPolicy.requiresConnectedVpn(
          isWeb: false,
          isAndroid: true,
          isWindows: false,
          isMacOS: false,
          isMockVpn: true,
        ),
        isFalse,
      );
      expect(
        LatencyTestPolicy.requiresConnectedVpn(
          isWeb: false,
          isAndroid: false,
          isWindows: true,
          isMacOS: false,
          isMockVpn: false,
        ),
        isTrue,
      );
      expect(
        LatencyTestPolicy.requiresConnectedVpn(
          isWeb: false,
          isAndroid: false,
          isWindows: false,
          isMacOS: true,
          isMockVpn: false,
        ),
        isTrue,
      );
      expect(
        LatencyTestPolicy.requiresConnectedVpn(
          isWeb: false,
          isAndroid: false,
          isWindows: false,
          isMacOS: false,
          isMockVpn: false,
        ),
        isFalse,
      );
    });

    test('Android uses the connection session instead of Clash delay API', () {
      expect(
        LatencyTestPolicy.usesConnectionSession(
          isWeb: false,
          isAndroid: true,
          isWindows: false,
          isMacOS: false,
          supportsConnectionManager: true,
        ),
        isTrue,
      );
      expect(
        LatencyTestPolicy.profileForPlatform(
          isWeb: false,
          isAndroid: true,
          isWindows: false,
          isMacOS: false,
        ),
        LatencyTestProfile.androidConnection,
      );
      expect(
        LatencyTestPolicy.profileForPlatform(
          isWeb: false,
          isAndroid: false,
          isWindows: true,
          isMacOS: false,
        ),
        LatencyTestProfile.v2boxConnection,
      );
      expect(
        LatencyTestPolicy.profileForPlatform(
          isWeb: false,
          isAndroid: false,
          isWindows: false,
          isMacOS: true,
        ),
        LatencyTestProfile.macosConnection,
      );
      expect(
        LatencyTestPolicy.usesConnectionSession(
          isWeb: false,
          isAndroid: true,
          isWindows: false,
          isMacOS: false,
          supportsConnectionManager: false,
        ),
        isFalse,
      );
    });

    test('keeps HTTP connection results authoritative until VPN disconnects',
        () {
      final now = DateTime(2026, 7, 13, 12);

      expect(
        LatencyTestPolicy.acceptsNativeLatencyUpdate(
          hasAuthoritativeConnectionResults: true,
          ignoreUntil: now.subtract(const Duration(seconds: 1)),
          now: now,
        ),
        isFalse,
      );
      expect(
        LatencyTestPolicy.acceptsNativeLatencyUpdate(
          hasAuthoritativeConnectionResults: false,
          ignoreUntil: now.add(const Duration(seconds: 1)),
          now: now,
        ),
        isFalse,
      );
      expect(
        LatencyTestPolicy.acceptsNativeLatencyUpdate(
          hasAuthoritativeConnectionResults: false,
          ignoreUntil: null,
          now: now,
        ),
        isTrue,
      );
    });
  });
}
