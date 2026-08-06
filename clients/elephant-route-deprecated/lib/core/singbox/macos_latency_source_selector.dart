import 'macos_latency_config.dart';

enum MacosLatencyConfigSource { active, refreshed }

class MacosLatencySourceSelection {
  const MacosLatencySourceSelection({
    required this.source,
    required this.sourceConfig,
    required this.eligibleNodeTags,
    required this.missingNodeTags,
  });

  final MacosLatencyConfigSource source;
  final String sourceConfig;
  final List<String> eligibleNodeTags;
  final List<String> missingNodeTags;
}

class MacosLatencySourceSelector {
  const MacosLatencySourceSelector._();

  static MacosLatencySourceSelection select({
    required String activeConfig,
    required String? refreshedConfig,
    required List<String> requestedNodeTags,
  }) {
    final activeTags =
        MacosLatencyConfigBuilder.concreteProxyTags(activeConfig);
    Set<String>? refreshedTags;
    if (refreshedConfig != null) {
      try {
        refreshedTags =
            MacosLatencyConfigBuilder.concreteProxyTags(refreshedConfig);
      } catch (_) {
        refreshedTags = null;
      }
    }

    int coverage(Set<String> tags) =>
        requestedNodeTags.where(tags.contains).length;
    final useRefreshed = refreshedConfig != null &&
        refreshedTags != null &&
        coverage(refreshedTags) > coverage(activeTags);
    final Set<String> sourceTags = useRefreshed ? refreshedTags : activeTags;
    final eligible =
        requestedNodeTags.where(sourceTags.contains).toList(growable: false);
    final missing = requestedNodeTags
        .where((tag) => !sourceTags.contains(tag))
        .toList(growable: false);

    return MacosLatencySourceSelection(
      source: useRefreshed
          ? MacosLatencyConfigSource.refreshed
          : MacosLatencyConfigSource.active,
      sourceConfig: useRefreshed ? refreshedConfig : activeConfig,
      eligibleNodeTags: eligible,
      missingNodeTags: missing,
    );
  }
}
