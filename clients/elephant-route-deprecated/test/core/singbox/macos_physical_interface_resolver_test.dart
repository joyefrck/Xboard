import 'dart:io';

import 'package:elephant_network/core/singbox/macos_physical_interface_resolver.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('parses physical interface from the default route', () async {
    final calls = <(String, List<String>)>[];
    final resolver = MacosPhysicalInterfaceResolver(
      commandRunner: (executable, arguments) async {
        calls.add((executable, arguments));
        return ProcessResult(
          1,
          0,
          'route to: default\n  interface: en0\n',
          '',
        );
      },
    );

    expect(await resolver.resolve(), 'en0');
    expect(calls, hasLength(1));
    expect(calls.single.$1, '/sbin/route');
    expect(calls.single.$2, ['-n', 'get', '-inet', 'default']);
  });

  test('accepts safe ethernet and bridge interface names', () {
    expect(
      MacosPhysicalInterfaceResolver.parseInterface(
        ' interface: bridge0.100 \n',
      ),
      'bridge0.100',
    );
    expect(
      MacosPhysicalInterfaceResolver.parseInterface('interface: en4-test\n'),
      'en4-test',
    );
  });

  test('rejects missing, tunnel, and unsafe interface names', () {
    expect(MacosPhysicalInterfaceResolver.parseInterface(''), isNull);
    expect(
      MacosPhysicalInterfaceResolver.parseInterface('gateway: 10.0.0.1\n'),
      isNull,
    );
    expect(
      MacosPhysicalInterfaceResolver.parseInterface('interface: utun7\n'),
      isNull,
    );
    expect(
      MacosPhysicalInterfaceResolver.parseInterface(
        'interface: en0;touch /tmp/unsafe\n',
      ),
      isNull,
    );
  });

  test('returns null when the route command fails', () async {
    final nonZero = MacosPhysicalInterfaceResolver(
      commandRunner: (_, __) async => ProcessResult(1, 1, '', 'failed'),
    );
    final exception = MacosPhysicalInterfaceResolver(
      commandRunner: (_, __) =>
          throw const ProcessException('/sbin/route', <String>[]),
    );

    expect(await nonZero.resolve(), isNull);
    expect(await exception.resolve(), isNull);
  });
}
