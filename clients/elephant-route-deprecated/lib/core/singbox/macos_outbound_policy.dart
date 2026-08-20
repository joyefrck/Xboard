abstract final class MacosOutboundPolicy {
  static void apply(Map<String, dynamic> config) {
    final rawOutbounds = config['outbounds'];
    if (rawOutbounds is! List) return;

    final dnsTags = <String>{
      for (final rawOutbound in rawOutbounds)
        if (rawOutbound is Map &&
            rawOutbound['type'] == 'dns' &&
            rawOutbound['tag'] is String)
          rawOutbound['tag'] as String,
    };
    if (dnsTags.isEmpty) return;

    config['outbounds'] = <dynamic>[
      for (final rawOutbound in rawOutbounds)
        if (!(rawOutbound is Map && rawOutbound['type'] == 'dns'))
          _withoutDnsReferences(rawOutbound, dnsTags),
    ];

    final rawRoute = config['route'];
    if (rawRoute is! Map) return;
    final route = Map<String, dynamic>.from(rawRoute);
    config['route'] = route;
    final rawRules = route['rules'];
    if (rawRules is! List) return;
    route['rules'] = <dynamic>[
      for (final rawRule in rawRules) _migrateRule(rawRule, dnsTags),
    ];
  }

  static dynamic _withoutDnsReferences(dynamic rawOutbound, Set<String> tags) {
    if (rawOutbound is! Map) return rawOutbound;
    final outbound = Map<String, dynamic>.from(rawOutbound);
    final members = outbound['outbounds'];
    if (members is List) {
      outbound['outbounds'] = <dynamic>[
        for (final member in members)
          if (!tags.contains(member)) member,
      ];
    }
    return outbound;
  }

  static dynamic _migrateRule(dynamic rawRule, Set<String> tags) {
    if (rawRule is! Map || !tags.contains(rawRule['outbound'])) {
      return rawRule;
    }
    final rule = Map<String, dynamic>.from(rawRule)
      ..remove('outbound')
      ..['action'] = 'hijack-dns';
    return rule;
  }
}
