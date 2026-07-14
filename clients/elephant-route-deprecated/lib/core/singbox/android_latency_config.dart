import 'dart:convert';

const String androidLatencyInboundPrefix = '__elephant_latency_in_';
const String androidLatencySelectorPrefix = '__elephant_latency_worker_';

class AndroidLatencyConfig {
  const AndroidLatencyConfig({
    required this.configJson,
    required this.nodeTags,
  });

  final String configJson;
  final List<String> nodeTags;
}

/// Adds loopback-only proxy workers without changing the active TUN selector.
class AndroidLatencyConfigBuilder {
  const AndroidLatencyConfigBuilder._();

  static AndroidLatencyConfig build({
    required String sourceConfig,
    required List<int> workerPorts,
  }) {
    if (workerPorts.isEmpty ||
        workerPorts.any((port) => port <= 0 || port > 65535) ||
        workerPorts.toSet().length != workerPorts.length) {
      throw const FormatException('Invalid Android latency worker ports');
    }

    final decoded = jsonDecode(sourceConfig);
    if (decoded is! Map) {
      throw const FormatException('sing-box config must be a JSON object');
    }
    final config = Map<String, dynamic>.from(decoded);
    final sourceOutbounds = _mapList(config['outbounds']);
    final outbounds = sourceOutbounds.where((outbound) {
      return !_tag(outbound).startsWith(androidLatencySelectorPrefix);
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
      return !_tag(inbound).startsWith(androidLatencyInboundPrefix);
    }).toList(growable: true);

    for (var index = 0; index < workerPorts.length; index++) {
      outbounds.add(<String, dynamic>{
        'type': 'selector',
        'tag': '$androidLatencySelectorPrefix$index',
        'outbounds': List<String>.from(nodeTags),
        'default': nodeTags.first,
      });
      inbounds.add(<String, dynamic>{
        'type': 'mixed',
        'tag': '$androidLatencyInboundPrefix$index',
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
        return !inbound.startsWith(androidLatencyInboundPrefix);
      }
      if (inbound is List) {
        return !inbound.any((value) =>
            value.toString().startsWith(androidLatencyInboundPrefix));
      }
      return true;
    }).toList(growable: false);
    route['rules'] = <Map<String, dynamic>>[
      for (var index = 0; index < workerPorts.length; index++)
        <String, dynamic>{
          'inbound': <String>['$androidLatencyInboundPrefix$index'],
          'outbound': '$androidLatencySelectorPrefix$index',
        },
      ...originalRules,
    ];

    config['inbounds'] = inbounds;
    config['outbounds'] = outbounds;
    config['route'] = route;
    return AndroidLatencyConfig(
      configJson: jsonEncode(config),
      nodeTags: List<String>.unmodifiable(nodeTags),
    );
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

  static String _tag(Map<String, dynamic> outbound) =>
      outbound['tag']?.toString() ?? '';

  static bool _isConcreteProxy(Map<String, dynamic> outbound) {
    const excluded = <String>{
      'selector',
      'urltest',
      'direct',
      'block',
      'dns',
    };
    return _tag(outbound).isNotEmpty &&
        !excluded.contains(outbound['type']?.toString().toLowerCase());
  }
}
