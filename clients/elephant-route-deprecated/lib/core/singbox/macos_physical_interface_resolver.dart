import 'dart:async';
import 'dart:io';

typedef MacosRouteCommandRunner = Future<ProcessResult> Function(
  String executable,
  List<String> arguments,
);

class MacosPhysicalInterfaceResolver {
  MacosPhysicalInterfaceResolver({
    MacosRouteCommandRunner? commandRunner,
    this.timeout = const Duration(seconds: 1),
  }) : _commandRunner = commandRunner ?? _runCommand {
    if (timeout <= Duration.zero) {
      throw ArgumentError.value(timeout, 'timeout', 'must be positive');
    }
  }

  final MacosRouteCommandRunner _commandRunner;
  final Duration timeout;

  Future<String?> resolve() async {
    try {
      final result = await _commandRunner(
        '/sbin/route',
        const ['-n', 'get', '-inet', 'default'],
      ).timeout(timeout);
      if (result.exitCode != 0) return null;
      return parseInterface(result.stdout.toString());
    } on Object {
      return null;
    }
  }

  static String? parseInterface(String output) {
    final match = RegExp(
      r'^\s*interface:\s*(\S+)\s*$',
      multiLine: true,
    ).firstMatch(output);
    final interface = match?.group(1)?.trim();
    if (interface == null || interface.isEmpty) return null;
    if (interface.toLowerCase().startsWith('utun')) return null;
    if (!RegExp(r'^[A-Za-z0-9._-]+$').hasMatch(interface)) return null;
    return interface;
  }

  static Future<ProcessResult> _runCommand(
    String executable,
    List<String> arguments,
  ) {
    return Process.run(executable, arguments, runInShell: false);
  }
}
