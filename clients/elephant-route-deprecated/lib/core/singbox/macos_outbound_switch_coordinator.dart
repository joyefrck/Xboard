import 'macos_clash_controller.dart';

class MacosOutboundSwitchResult {
  const MacosOutboundSwitchResult({
    required this.previousOutbound,
    required this.targetOutbound,
    required this.closedConnectionCount,
  });

  final String previousOutbound;
  final String targetOutbound;
  final int closedConnectionCount;
}

class MacosOutboundSwitchException implements Exception {
  const MacosOutboundSwitchException(
    this.message, {
    this.cause,
    this.closedConnectionCount = 0,
    this.remainingConnectionCount = 0,
  });

  final String message;
  final Object? cause;
  final int closedConnectionCount;
  final int remainingConnectionCount;

  @override
  String toString() {
    final progress = closedConnectionCount == 0 && remainingConnectionCount == 0
        ? ''
        : ' (closed=$closedConnectionCount, remaining=$remainingConnectionCount)';
    return '$message$progress';
  }
}

class MacosOutboundSwitchCoordinator {
  const MacosOutboundSwitchCoordinator(this._control);

  final MacosClashControl _control;

  Future<MacosOutboundSwitchResult> switchOutbound({
    required String groupTag,
    required String targetOutbound,
  }) async {
    final previousOutbound = await _control.selectedOutbound(groupTag);
    if (previousOutbound == targetOutbound) {
      return MacosOutboundSwitchResult(
        previousOutbound: previousOutbound,
        targetOutbound: targetOutbound,
        closedConnectionCount: 0,
      );
    }

    final connections = await _control.activeConnections();
    final oldConnectionIds = connections
        .where(
          (connection) =>
              connection.chains.contains(groupTag) &&
              connection.chains.contains(previousOutbound),
        )
        .map((connection) => connection.id)
        .toList(growable: false);

    await _control.selectOutbound(groupTag, targetOutbound);
    final confirmedOutbound = await _control.selectedOutbound(groupTag);
    if (confirmedOutbound != targetOutbound) {
      throw MacosOutboundSwitchException(
        'Clash API selector confirmation mismatch: '
        'expected $targetOutbound, got $confirmedOutbound',
      );
    }

    var closedConnectionCount = 0;
    for (final connectionId in oldConnectionIds) {
      try {
        await _control.closeConnection(connectionId);
        closedConnectionCount++;
      } catch (error) {
        try {
          await _control.selectOutbound(groupTag, previousOutbound);
        } catch (_) {
          // Preserve the original cleanup error and progress information.
        }
        throw MacosOutboundSwitchException(
          'Failed to close connections through the previous outbound; '
          'selector rollback was requested',
          cause: error,
          closedConnectionCount: closedConnectionCount,
          remainingConnectionCount:
              oldConnectionIds.length - closedConnectionCount,
        );
      }
    }

    return MacosOutboundSwitchResult(
      previousOutbound: previousOutbound,
      targetOutbound: targetOutbound,
      closedConnectionCount: closedConnectionCount,
    );
  }
}
