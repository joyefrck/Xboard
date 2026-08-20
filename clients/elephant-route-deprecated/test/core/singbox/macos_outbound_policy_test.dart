import 'package:elephant_network/core/singbox/macos_outbound_policy.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('migrates the legacy DNS outbound to hijack-dns', () {
    final config = <String, dynamic>{
      'outbounds': [
        {'type': 'direct', 'tag': 'direct'},
        {'type': 'dns', 'tag': 'dns-out'},
        {
          'type': 'selector',
          'tag': 'selector',
          'outbounds': ['direct', 'dns-out'],
        },
      ],
      'route': {
        'rules': [
          {'protocol': 'dns', 'outbound': 'dns-out'},
          {'ip_is_private': true, 'outbound': 'direct'},
        ],
      },
    };

    MacosOutboundPolicy.apply(config);

    expect((config['outbounds'] as List), [
      {'type': 'direct', 'tag': 'direct'},
      {
        'type': 'selector',
        'tag': 'selector',
        'outbounds': ['direct'],
      },
    ]);
    expect((config['route'] as Map)['rules'], [
      {'protocol': 'dns', 'action': 'hijack-dns'},
      {'ip_is_private': true, 'outbound': 'direct'},
    ]);
  });

  test('leaves current outbounds unchanged', () {
    final config = <String, dynamic>{
      'outbounds': [
        {'type': 'direct', 'tag': 'direct'},
      ],
      'route': {
        'rules': [
          {'protocol': 'dns', 'action': 'hijack-dns'},
        ],
      },
    };

    MacosOutboundPolicy.apply(config);

    expect(config, {
      'outbounds': [
        {'type': 'direct', 'tag': 'direct'},
      ],
      'route': {
        'rules': [
          {'protocol': 'dns', 'action': 'hijack-dns'},
        ],
      },
    });
  });
}
