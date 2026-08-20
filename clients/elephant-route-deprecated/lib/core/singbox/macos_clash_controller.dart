import 'package:dio/dio.dart';

class MacosClashConnection {
  const MacosClashConnection({
    required this.id,
    required this.chains,
  });

  final String id;
  final List<String> chains;
}

class MacosClashControllerException implements Exception {
  const MacosClashControllerException(
    this.message, {
    this.statusCode,
    this.cause,
  });

  final String message;
  final int? statusCode;
  final Object? cause;

  @override
  String toString() {
    final status = statusCode == null ? '' : ' (HTTP $statusCode)';
    return '$message$status';
  }
}

abstract interface class MacosClashControl {
  Future<String> selectedOutbound(String groupTag);

  Future<List<MacosClashConnection>> activeConnections();

  Future<void> selectOutbound(String groupTag, String outboundTag);

  Future<void> closeConnection(String connectionId);

  Future<int> urlTest(
    String proxyTag, {
    String testUrl = 'https://www.gstatic.com/generate_204',
    int timeoutMs = 3000,
  });
}

class MacosClashController implements MacosClashControl {
  MacosClashController(this._dio);

  final Dio _dio;

  @override
  Future<String> selectedOutbound(String groupTag) async {
    try {
      final response = await _dio.get<dynamic>(
        '/proxies/${Uri.encodeComponent(groupTag)}',
      );
      final data = response.data;
      if (data is Map) {
        final selected = data['now'];
        if (selected is String && selected.isNotEmpty) {
          return selected;
        }
      }
      throw const MacosClashControllerException(
        'Clash API selector response has no active outbound',
      );
    } on MacosClashControllerException {
      rethrow;
    } on DioException catch (error) {
      throw MacosClashControllerException(
        'Clash API selector read failed',
        statusCode: error.response?.statusCode,
        cause: error,
      );
    } catch (error) {
      throw MacosClashControllerException(
        'Clash API selector read failed',
        cause: error,
      );
    }
  }

  @override
  Future<List<MacosClashConnection>> activeConnections() async {
    try {
      final response = await _dio.get<dynamic>('/connections');
      final data = response.data;
      if (data is! Map) {
        throw const MacosClashControllerException(
          'Clash API connections response is invalid',
        );
      }
      final rawConnections = data['connections'];
      if (rawConnections == null) {
        return const <MacosClashConnection>[];
      }
      if (rawConnections is! List) {
        throw const MacosClashControllerException(
          'Clash API connections response is invalid',
        );
      }

      final connections = <MacosClashConnection>[];
      for (final rawConnection in rawConnections) {
        if (rawConnection is! Map) continue;
        final id = rawConnection['id'];
        final rawChains = rawConnection['chains'];
        if (id is! String || id.isEmpty || rawChains is! List) continue;
        if (rawChains.any((chain) => chain is! String)) continue;
        connections.add(MacosClashConnection(
          id: id,
          chains: List<String>.unmodifiable(rawChains.cast<String>()),
        ));
      }
      return List<MacosClashConnection>.unmodifiable(connections);
    } on MacosClashControllerException {
      rethrow;
    } on DioException catch (error) {
      throw MacosClashControllerException(
        'Clash API connections read failed',
        statusCode: error.response?.statusCode,
        cause: error,
      );
    } catch (error) {
      throw MacosClashControllerException(
        'Clash API connections read failed',
        cause: error,
      );
    }
  }

  @override
  Future<void> selectOutbound(String groupTag, String outboundTag) async {
    try {
      await _dio.put<void>(
        '/proxies/${Uri.encodeComponent(groupTag)}',
        data: {'name': outboundTag},
      );
    } on DioException catch (error) {
      throw MacosClashControllerException(
        'Clash API rejected outbound selection',
        statusCode: error.response?.statusCode,
        cause: error,
      );
    } catch (error) {
      throw MacosClashControllerException(
        'Clash API outbound selection failed',
        cause: error,
      );
    }
  }

  @override
  Future<void> closeConnection(String connectionId) async {
    try {
      await _dio.delete<void>(
        '/connections/${Uri.encodeComponent(connectionId)}',
      );
    } on DioException catch (error) {
      if (error.response?.statusCode == 404) {
        return;
      }
      throw MacosClashControllerException(
        'Clash API connection close failed',
        statusCode: error.response?.statusCode,
        cause: error,
      );
    } catch (error) {
      throw MacosClashControllerException(
        'Clash API connection close failed',
        cause: error,
      );
    }
  }

  @override
  Future<int> urlTest(
    String proxyTag, {
    String testUrl = 'https://www.gstatic.com/generate_204',
    int timeoutMs = 3000,
  }) async {
    try {
      final response = await _dio.get<dynamic>(
        '/proxies/${Uri.encodeComponent(proxyTag)}/delay',
        queryParameters: {
          'url': testUrl,
          'timeout': timeoutMs,
        },
      );
      final data = response.data;
      if (data is Map) {
        final delay = data['delay'];
        if (delay is int && delay > 0) return delay;
        return int.tryParse(delay?.toString() ?? '') ?? -1;
      }
      return -1;
    } on DioException {
      return -1;
    }
  }
}
