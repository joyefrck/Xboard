class MacosDnsPolicy {
  static const String _legacyDefaultRemoteDns = '8.8.8.8';
  static const String _proxiedRemoteDoh = 'https://1.1.1.1/dns-query';
  static const String _proxySelector = '节点选择';

  static Map<String, dynamic> apply(Map<String, dynamic> config) {
    final dns = config['dns'];
    if (dns is! Map<String, dynamic>) return config;

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

    return config;
  }
}
