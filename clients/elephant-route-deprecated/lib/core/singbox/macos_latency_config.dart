import 'dart:convert';

class MacosLiveLatencyConfig {
  const MacosLiveLatencyConfig({
    required this.configJson,
    required this.nodeTags,
  });

  final String configJson;
  final List<String> nodeTags;
}

class MacosLatencyConfigBuilder {
  const MacosLatencyConfigBuilder._();

  static const String inboundPrefix = '__elephant_latency_in_';
  static const String workerPrefix = '__elephant_latency_worker_';

  static const Set<String> _nonProxyTypes = <String>{
    'selector',
    'urltest',
    'direct',
    'block',
    'dns',
  };

  static Set<String> concreteProxyTags(String sourceConfig) {
    final decoded = jsonDecode(sourceConfig);
    if (decoded is! Map<String, dynamic>) {
      throw const FormatException('sing-box config must be a JSON object');
    }
    final rawOutbounds = decoded['outbounds'];
    if (rawOutbounds is! List<dynamic>) {
      throw const FormatException('sing-box config must contain outbounds');
    }
    return rawOutbounds
        .whereType<Map<String, dynamic>>()
        .where((outbound) {
          final tag = outbound['tag']?.toString() ?? '';
          final type = outbound['type']?.toString().toLowerCase() ?? '';
          return tag.isNotEmpty && !_nonProxyTypes.contains(type);
        })
        .map((outbound) => outbound['tag'].toString())
        .toSet();
  }

  static MacosLiveLatencyConfig addLiveWorkers({
    required String sourceConfig,
    required List<int> workerPorts,
  }) {
    if (workerPorts.isEmpty ||
        workerPorts.any((port) => port <= 0 || port > 65535) ||
        workerPorts.toSet().length != workerPorts.length) {
      throw const FormatException('Invalid macOS latency worker ports');
    }

    final decoded = jsonDecode(sourceConfig);
    if (decoded is! Map) {
      throw const FormatException('sing-box config must be a JSON object');
    }
    final config = Map<String, dynamic>.from(decoded);
    final outbounds = _mapList(config['outbounds']).where((outbound) {
      return !_tag(outbound).startsWith(workerPrefix);
    }).toList(growable: true);
    final nodeTags = outbounds
        .where(_isConcreteProxy)
        .map(_tag)
        .where((tag) => tag.isNotEmpty)
        .toList(growable: false);
    if (nodeTags.isEmpty) {
      throw const FormatException('No concrete proxy outbounds available');
    }

    final inbounds = _mapList(config['inbounds']).where((inbound) {
      return !_tag(inbound).startsWith(inboundPrefix);
    }).toList(growable: true);
    for (var index = 0; index < workerPorts.length; index++) {
      outbounds.add(<String, dynamic>{
        'type': 'selector',
        'tag': '$workerPrefix$index',
        'outbounds': List<String>.from(nodeTags),
        'default': nodeTags.first,
      });
      inbounds.add(<String, dynamic>{
        'type': 'mixed',
        'tag': '$inboundPrefix$index',
        'listen': '127.0.0.1',
        'listen_port': workerPorts[index],
      });
    }

    final route = config['route'] is Map
        ? Map<String, dynamic>.from(config['route'] as Map)
        : <String, dynamic>{};
    final originalRules = _mapList(route['rules']).where((rule) {
      final inbound = rule['inbound'];
      if (inbound is String) {
        return !inbound.startsWith(inboundPrefix);
      }
      if (inbound is List) {
        return !inbound.any(
          (value) => value.toString().startsWith(inboundPrefix),
        );
      }
      return true;
    }).toList(growable: false);
    route['rules'] = <Map<String, dynamic>>[
      for (var index = 0;
          index < workerPorts.length;
          index++) ...<Map<String, dynamic>>[
        <String, dynamic>{
          'inbound': <String>['$inboundPrefix$index'],
          'action': 'resolve',
          'strategy': 'ipv4_only',
        },
        <String, dynamic>{
          'inbound': <String>['$inboundPrefix$index'],
          'action': 'route',
          'outbound': '$workerPrefix$index',
        },
      ],
      ...originalRules,
    ];

    config['inbounds'] = inbounds;
    config['outbounds'] = outbounds;
    config['route'] = route;
    return MacosLiveLatencyConfig(
      configJson: jsonEncode(config),
      nodeTags: List<String>.unmodifiable(nodeTags),
    );
  }

  static Map<String, dynamic> build({
    required String sourceConfig,
    required List<String> nodeTags,
    required List<int> workerPorts,
    required int clashApiPort,
    required String defaultInterface,
  }) {
    if (nodeTags.isEmpty) {
      throw ArgumentError.value(nodeTags, 'nodeTags', 'must not be empty');
    }
    if (workerPorts.isEmpty) {
      throw ArgumentError.value(
        workerPorts,
        'workerPorts',
        'must not be empty',
      );
    }
    if (defaultInterface.isEmpty ||
        defaultInterface.toLowerCase().startsWith('utun') ||
        !RegExp(r'^[A-Za-z0-9._-]+$').hasMatch(defaultInterface)) {
      throw ArgumentError.value(
        defaultInterface,
        'defaultInterface',
        'must be a physical network interface',
      );
    }

    final decoded = jsonDecode(sourceConfig);
    if (decoded is! Map<String, dynamic>) {
      throw const FormatException('sing-box config must be a JSON object');
    }
    final config = decoded;
    config.remove('use_tun_mode');
    config['log'] = <String, dynamic>{
      'level': 'warn',
      'timestamp': true,
    };

    final rawOutbounds = config['outbounds'];
    if (rawOutbounds is! List<dynamic>) {
      throw const FormatException('sing-box config must contain outbounds');
    }
    final outbounds = rawOutbounds
        .whereType<Map<String, dynamic>>()
        .where((outbound) {
          final tag = outbound['tag']?.toString();
          final type = outbound['type']?.toString();
          return !(tag?.startsWith(workerPrefix) ?? false) &&
              type != 'selector' &&
              type != 'urltest';
        })
        .map((outbound) => Map<String, dynamic>.from(outbound))
        .toList();

    final byTag = <String, Map<String, dynamic>>{
      for (final outbound in outbounds)
        if (outbound['tag'] is String) outbound['tag'] as String: outbound,
    };
    final concreteTags = concreteProxyTags(sourceConfig);
    for (final nodeTag in nodeTags) {
      final outbound = byTag[nodeTag];
      if (outbound == null || !concreteTags.contains(nodeTag)) {
        throw ArgumentError.value(
          nodeTag,
          'nodeTags',
          'must reference a concrete outbound',
        );
      }
    }

    final dns = config['dns'];
    if (dns is Map<String, dynamic> && dns['servers'] is List<dynamic>) {
      for (final server in (dns['servers'] as List<dynamic>)
          .whereType<Map<String, dynamic>>()) {
        final detour = server['detour'];
        if (detour is String && !byTag.containsKey(detour)) {
          if (byTag['direct']?['type'] == 'direct') {
            server['detour'] = 'direct';
          } else {
            server.remove('detour');
          }
        }
      }
    }

    for (var index = 0; index < workerPorts.length; index++) {
      outbounds.add(<String, dynamic>{
        'type': 'selector',
        'tag': '$workerPrefix$index',
        'outbounds': List<String>.from(nodeTags),
        'default': nodeTags.first,
      });
    }
    config['outbounds'] = outbounds;

    config['inbounds'] = <Map<String, dynamic>>[
      for (var index = 0; index < workerPorts.length; index++)
        <String, dynamic>{
          'type': 'mixed',
          'tag': '$inboundPrefix$index',
          'listen': '127.0.0.1',
          'listen_port': workerPorts[index],
        },
    ];

    final sourceRoute = config['route'];
    final route = sourceRoute is Map<String, dynamic>
        ? Map<String, dynamic>.from(sourceRoute)
        : <String, dynamic>{};
    route['rules'] = <Map<String, dynamic>>[
      for (var index = 0;
          index < workerPorts.length;
          index++) ...<Map<String, dynamic>>[
        <String, dynamic>{
          'inbound': <String>['$inboundPrefix$index'],
          'action': 'resolve',
          'strategy': 'ipv4_only',
        },
        <String, dynamic>{
          'inbound': <String>['$inboundPrefix$index'],
          'action': 'route',
          'outbound': '$workerPrefix$index',
        },
      ],
    ];
    if (byTag['direct']?['type'] == 'direct') {
      route['final'] = 'direct';
    } else {
      route.remove('final');
    }
    route['auto_detect_interface'] = false;
    route['default_interface'] = defaultInterface;
    config['route'] = route;

    final sourceExperimental = config['experimental'];
    final experimental = sourceExperimental is Map<String, dynamic>
        ? Map<String, dynamic>.from(sourceExperimental)
        : <String, dynamic>{};
    experimental.remove('cache_file');
    experimental['clash_api'] = <String, dynamic>{
      'external_controller': '127.0.0.1:$clashApiPort',
      'default_mode': 'rule',
    };
    config['experimental'] = experimental;

    return config;
  }

  static List<Map<String, dynamic>> _mapList(Object? value) {
    if (value == null) return <Map<String, dynamic>>[];
    if (value is! List) {
      throw const FormatException('Expected a JSON array');
    }
    return value.map((item) {
      if (item is! Map) throw const FormatException('Expected JSON objects');
      return Map<String, dynamic>.from(item);
    }).toList();
  }

  static String _tag(Map<String, dynamic> item) =>
      item['tag']?.toString() ?? '';

  static bool _isConcreteProxy(Map<String, dynamic> outbound) {
    final type = outbound['type']?.toString().toLowerCase() ?? '';
    return _tag(outbound).isNotEmpty && !_nonProxyTypes.contains(type);
  }
}
