import 'dart:convert';
import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:path_provider/path_provider.dart';

import '../storage/secure_storage.dart';

/// Persists the last configuration that was successfully returned by the
/// subscription endpoint. Desktop clients can start from this known-good
/// snapshot while a refresh happens outside the user-facing connect path.
class SubscriptionConfigCache {
  SubscriptionConfigCache({SecureStorage storage = const SecureStorage()})
      : _storage = storage;

  static const storageKey = 'last_known_subscription_config';

  final SecureStorage _storage;

  Future<String?> read() async {
    try {
      final cached = await _storage.read(key: storageKey);
      if (_isValid(cached)) return cached;
      if (cached != null) {
        await _storage.delete(key: storageKey);
      }

      // 1.6.1 already left a validated runtime config on disk. Reusing it once
      // lets upgraded macOS clients avoid a blocking subscription fetch on the
      // first launch of 1.6.2.
      if (defaultTargetPlatform == TargetPlatform.macOS) {
        final supportDirectory = await getApplicationSupportDirectory();
        final runtimeConfig =
            File('${supportDirectory.path}/sing-box/config.json');
        if (await runtimeConfig.exists()) {
          final migrated = await runtimeConfig.readAsString();
          if (_isValid(migrated)) {
            await _storage.write(key: storageKey, value: migrated);
            return migrated;
          }
        }
      }
    } catch (_) {
      // Cache availability must never block or fail a VPN connection.
    }
    return null;
  }

  Future<void> write(String config) async {
    if (!_isValid(config)) return;
    try {
      await _storage.write(key: storageKey, value: config);
    } catch (_) {
      // A Keychain/cache failure must not turn a valid API response into a
      // failed subscription request.
    }
  }

  Future<void> clear() async {
    try {
      await _storage.delete(key: storageKey);
    } catch (_) {
      // Best-effort cache cleanup.
    }
  }

  bool _isValid(String? config) {
    if (config == null || config.isEmpty) return false;
    try {
      final decoded = jsonDecode(config);
      return decoded is Map &&
          decoded['outbounds'] is List &&
          (decoded['outbounds'] as List).isNotEmpty;
    } catch (_) {
      return false;
    }
  }
}
