abstract final class MacosInboundPolicy {
  static const _legacyFields = <String>{
    'sniff',
    'sniff_override_destination',
    'sniff_timeout',
    'domain_strategy',
    'udp_disable_domain_unmapping',
  };
  static const _tunAddressFields = <String, String>{
    'inet4_address': 'address',
    'inet6_address': 'address',
    'inet4_route_address': 'route_address',
    'inet6_route_address': 'route_address',
    'inet4_route_exclude_address': 'route_exclude_address',
    'inet6_route_exclude_address': 'route_exclude_address',
  };

  static void apply(Map<String, dynamic> config) {
    final rawInbounds = config['inbounds'];
    if (rawInbounds is! List) return;

    final usedTags = <String>{
      for (final rawInbound in rawInbounds)
        if (rawInbound is Map && rawInbound['tag'] is String)
          (rawInbound['tag'] as String).trim(),
    }..remove('');
    final actions = <Map<String, dynamic>>[];

    for (var index = 0; index < rawInbounds.length; index++) {
      final rawInbound = rawInbounds[index];
      if (rawInbound is! Map<String, dynamic>) continue;

      if (rawInbound['type'] == 'tun') {
        _migrateTunAddresses(rawInbound);
      }

      final hasLegacyFields = _legacyFields.any(rawInbound.containsKey);
      if (!hasLegacyFields) continue;

      final tag = _ensureTag(rawInbound, index, usedTags);
      final domainStrategy = rawInbound['domain_strategy'];
      final sniffEnabled = rawInbound['sniff'] == true ||
          rawInbound['sniff_override_destination'] == true;
      final sniffTimeout = rawInbound['sniff_timeout'];
      final disableDomainUnmapping =
          rawInbound['udp_disable_domain_unmapping'] == true;

      for (final field in _legacyFields) {
        rawInbound.remove(field);
      }

      if (domainStrategy is String && domainStrategy.trim().isNotEmpty) {
        actions.add({
          'inbound': tag,
          'action': 'resolve',
          'strategy': domainStrategy.trim(),
        });
      }
      if (sniffEnabled) {
        actions.add({
          'inbound': tag,
          'action': 'sniff',
          if (sniffTimeout is String && sniffTimeout.trim().isNotEmpty)
            'timeout': sniffTimeout.trim(),
        });
      }
      if (disableDomainUnmapping) {
        actions.add({
          'inbound': tag,
          'action': 'route-options',
          'udp_disable_domain_unmapping': true,
        });
      }
    }

    if (actions.isEmpty) return;
    final rawRoute = config['route'];
    final route = rawRoute is Map
        ? Map<String, dynamic>.from(rawRoute)
        : <String, dynamic>{};
    config['route'] = route;
    final existingRules = route['rules'] is List
        ? List<dynamic>.from(route['rules'] as List)
        : <dynamic>[];
    route['rules'] = <dynamic>[...actions, ...existingRules];
  }

  static void _migrateTunAddresses(Map<String, dynamic> inbound) {
    final mergedValues = <String, List<dynamic>>{};
    for (final entry in _tunAddressFields.entries) {
      if (!inbound.containsKey(entry.key)) continue;
      final values = mergedValues.putIfAbsent(
        entry.value,
        () => _listValue(inbound[entry.value]),
      );
      values.addAll(_listValue(inbound.remove(entry.key)));
    }
    for (final entry in mergedValues.entries) {
      inbound[entry.key] = entry.value.toSet().toList(growable: false);
    }
  }

  static List<dynamic> _listValue(dynamic value) {
    if (value == null) return <dynamic>[];
    if (value is List) return List<dynamic>.from(value);
    return <dynamic>[value];
  }

  static String _ensureTag(
    Map<String, dynamic> inbound,
    int index,
    Set<String> usedTags,
  ) {
    final existingTag = inbound['tag'];
    if (existingTag is String && existingTag.trim().isNotEmpty) {
      return existingTag.trim();
    }

    final type = inbound['type'];
    final baseTag = type == 'tun' ? 'tun-in' : '__elephant_inbound_$index';
    var tag = baseTag;
    var suffix = 2;
    while (usedTags.contains(tag)) {
      tag = '$baseTag-$suffix';
      suffix++;
    }
    inbound['tag'] = tag;
    usedTags.add(tag);
    return tag;
  }
}
