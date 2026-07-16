import 'dart:io';

import 'package:elephant_network/core/api/subscription_config_cache.dart';
import 'package:elephant_network/core/storage/secure_storage.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:path_provider/path_provider.dart';

import '../../test_bootstrap.dart';

void main() {
  configureTestEnvironment();

  late TargetPlatform? originalPlatform;

  setUp(() async {
    originalPlatform = debugDefaultTargetPlatformOverride;
    debugDefaultTargetPlatformOverride = TargetPlatform.macOS;
    await const SecureStorage().delete(key: SubscriptionConfigCache.storageKey);
    final support = await getApplicationSupportDirectory();
    final runtime = File('${support.path}/sing-box/config.json');
    if (await runtime.exists()) await runtime.delete();
  });

  tearDown(() {
    debugDefaultTargetPlatformOverride = originalPlatform;
  });

  test('stores and restores a valid last-known subscription config', () async {
    final cache = SubscriptionConfigCache();
    const config = '{"outbounds":[{"type":"direct","tag":"direct"}]}';

    await cache.write(config);

    expect(await cache.read(), config);
  });

  test('ignores malformed cached JSON', () async {
    final cache = SubscriptionConfigCache();
    await const SecureStorage().write(
      key: SubscriptionConfigCache.storageKey,
      value: 'not-json',
    );

    expect(await cache.read(), isNull);
  });

  test('migrates the last macOS runtime config on first 1.6.2 launch',
      () async {
    final support = await getApplicationSupportDirectory();
    final runtime = File('${support.path}/sing-box/config.json');
    await runtime.parent.create(recursive: true);
    const config = '{"outbounds":[{"type":"direct","tag":"direct"}]}';
    await runtime.writeAsString(config);

    final cache = SubscriptionConfigCache();

    expect(await cache.read(), config);
    expect(
      await const SecureStorage().read(
        key: SubscriptionConfigCache.storageKey,
      ),
      config,
    );
  });
}
