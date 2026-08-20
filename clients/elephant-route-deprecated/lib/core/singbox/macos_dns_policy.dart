class MacosDnsPolicy {
  static const String _legacyDefaultRemoteDns = '8.8.8.8';
  static const String _proxiedRemoteDoh = 'https://1.1.1.1/dns-query';
  static const String _proxySelector = '节点选择';

  static Map<String, dynamic> apply(Map<String, dynamic> config) {
    final rawDns = config['dns'];
    if (rawDns is! Map) return config;
    final dns = Map<String, dynamic>.from(rawDns);
    config['dns'] = dns;

    final servers = dns['servers'];
    if (servers is! List) return config;

    for (final server in servers) {
      if (server is! Map<String, dynamic> ||
          server['tag'] != 'remote' ||
          server['address'] != _legacyDefaultRemoteDns) {
        continue;
      }

      server['address'] = _proxiedRemoteDoh;
      server['detour'] ??= _proxySelector;
      server['strategy'] = 'ipv4_only';
    }

    _migrateOutboundRules(config, dns);

    return config;
  }

  static void _migrateOutboundRules(
    Map<String, dynamic> config,
    Map<String, dynamic> dns,
  ) {
    final rawRules = dns['rules'];
    if (rawRules is! List) return;

    final retainedRules = <dynamic>[];
    for (final rawRule in rawRules) {
      if (rawRule is! Map || !_canMigrateOutboundRule(rawRule)) {
        retainedRules.add(rawRule);
        continue;
      }

      final outboundTags = _outboundTags(rawRule['outbound']);
      final server = rawRule['server'];
      if (outboundTags.isEmpty || server is! String || server.isEmpty) {
        retainedRules.add(rawRule);
        continue;
      }

      final resolver = _resolverValue(rawRule, server);
      var migrated = false;
      if (outboundTags.contains('any')) {
        final rawRoute = config['route'];
        final route = rawRoute is Map
            ? Map<String, dynamic>.from(rawRoute)
            : <String, dynamic>{};
        route['default_domain_resolver'] = resolver;
        config['route'] = route;
        migrated = true;
      } else {
        migrated = _applyResolverToOutbounds(
          config,
          outboundTags,
          resolver,
        );
      }

      if (!migrated) retainedRules.add(rawRule);
    }
    dns['rules'] = retainedRules;
  }

  static bool _canMigrateOutboundRule(Map<dynamic, dynamic> rule) {
    const supportedFields = {
      'outbound',
      'server',
      'rewrite_ttl',
      'client_subnet',
    };
    return rule.keys.every(supportedFields.contains);
  }

  static Set<String> _outboundTags(dynamic rawOutbound) {
    if (rawOutbound is String) return {rawOutbound};
    if (rawOutbound is List) {
      return rawOutbound.whereType<String>().toSet();
    }
    return const {};
  }

  static dynamic _resolverValue(Map<dynamic, dynamic> rule, String server) {
    if (!rule.containsKey('rewrite_ttl') &&
        !rule.containsKey('client_subnet')) {
      return server;
    }
    return <String, dynamic>{
      'server': server,
      if (rule.containsKey('rewrite_ttl')) 'rewrite_ttl': rule['rewrite_ttl'],
      if (rule.containsKey('client_subnet'))
        'client_subnet': rule['client_subnet'],
    };
  }

  static bool _applyResolverToOutbounds(
    Map<String, dynamic> config,
    Set<String> tags,
    dynamic resolver,
  ) {
    final rawOutbounds = config['outbounds'];
    if (rawOutbounds is! List) return false;
    var matched = 0;
    final outbounds = <dynamic>[];
    for (final rawOutbound in rawOutbounds) {
      if (rawOutbound is Map && tags.contains(rawOutbound['tag'])) {
        outbounds.add(Map<String, dynamic>.from(rawOutbound)
          ..['domain_resolver'] = resolver);
        matched++;
      } else {
        outbounds.add(rawOutbound);
      }
    }
    if (matched != tags.length) return false;
    config['outbounds'] = outbounds;
    return true;
  }
}
