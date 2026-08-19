import 'package:dio/dio.dart';

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

class MacosClashController {
  MacosClashController(this._dio);

  final Dio _dio;

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
