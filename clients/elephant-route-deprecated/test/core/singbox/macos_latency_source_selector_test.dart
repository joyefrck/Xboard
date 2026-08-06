import 'dart:convert';

import 'package:elephant_network/core/singbox/macos_latency_source_selector.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  String config(List<String> tags) => jsonEncode({
        'outbounds': [
          for (final tag in tags)
            {
              'type': 'anytls',
              'tag': tag,
              'server': '$tag.invalid',
              'server_port': 443,
              'password': 'redacted',
            },
          {
            'type': 'selector',
            'tag': '节点选择',
            'outbounds': tags,
          },
          {'type': 'direct', 'tag': 'direct'},
        ],
      });

  test('prefers refreshed config when it covers more requested nodes', () {
    final active = config(['node-a', 'node-b']);
    final refreshed = config(['node-a', 'node-b', 'node-c']);

    final selection = MacosLatencySourceSelector.select(
      activeConfig: active,
      refreshedConfig: refreshed,
      requestedNodeTags: const ['node-a', 'node-b', 'node-c', 'missing'],
    );

    expect(selection.source, MacosLatencyConfigSource.refreshed);
    expect(selection.sourceConfig, refreshed);
    expect(selection.eligibleNodeTags, ['node-a', 'node-b', 'node-c']);
    expect(selection.missingNodeTags, ['missing']);
  });

  test('keeps active config on equal coverage and preserves request order', () {
    final active = config(['node-b', 'node-a']);
    final refreshed = config(['node-a', 'node-b']);

    final selection = MacosLatencySourceSelector.select(
      activeConfig: active,
      refreshedConfig: refreshed,
      requestedNodeTags: const ['node-a', 'missing', 'node-b'],
    );

    expect(selection.source, MacosLatencyConfigSource.active);
    expect(selection.sourceConfig, active);
    expect(selection.eligibleNodeTags, ['node-a', 'node-b']);
    expect(selection.missingNodeTags, ['missing']);
  });

  test('ignores an invalid refreshed config', () {
    final active = config(['node-a']);

    final selection = MacosLatencySourceSelector.select(
      activeConfig: active,
      refreshedConfig: '{invalid',
      requestedNodeTags: const ['node-a'],
    );

    expect(selection.source, MacosLatencyConfigSource.active);
    expect(selection.eligibleNodeTags, ['node-a']);
    expect(selection.missingNodeTags, isEmpty);
  });
}
