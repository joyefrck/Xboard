import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:elephant_network/core/singbox/macos_clash_controller.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('hot-selects an outbound through the Clash API', () async {
    final adapter = _RecordingAdapter(statusCode: 204);
    final dio = Dio(BaseOptions(baseUrl: 'http://127.0.0.1:9090'))
      ..httpClientAdapter = adapter;
    final controller = MacosClashController(dio);

    await controller.selectOutbound('节点选择', '东京 01');

    expect(adapter.lastOptions?.method, 'PUT');
    expect(
      adapter.lastOptions?.path,
      '/proxies/%E8%8A%82%E7%82%B9%E9%80%89%E6%8B%A9',
    );
    expect(adapter.lastOptions?.data, {'name': '东京 01'});
  });

  test('reads a positive delay through the Clash API', () async {
    final adapter = _RecordingAdapter(
      statusCode: 200,
      responseData: const {'delay': 46},
    );
    final dio = Dio(BaseOptions(baseUrl: 'http://127.0.0.1:9090'))
      ..httpClientAdapter = adapter;
    final controller = MacosClashController(dio);

    final delay = await controller.urlTest('东京 01');

    expect(delay, 46);
    expect(adapter.lastOptions?.method, 'GET');
    expect(
      adapter.lastOptions?.path,
      '/proxies/%E4%B8%9C%E4%BA%AC%2001/delay',
    );
  });

  test('wraps a rejected selector response', () async {
    final dio = Dio(BaseOptions(baseUrl: 'http://127.0.0.1:9090'))
      ..httpClientAdapter = _RecordingAdapter(statusCode: 503);
    final controller = MacosClashController(dio);

    await expectLater(
      controller.selectOutbound('节点选择', 'Tokyo'),
      throwsA(
        isA<MacosClashControllerException>()
            .having((error) => error.statusCode, 'statusCode', 503),
      ),
    );
  });

  test('wraps a selector transport failure', () async {
    final dio = Dio(BaseOptions(baseUrl: 'http://127.0.0.1:9090'))
      ..httpClientAdapter = _ThrowingAdapter();
    final controller = MacosClashController(dio);

    await expectLater(
      controller.selectOutbound('节点选择', 'Tokyo'),
      throwsA(
        isA<MacosClashControllerException>()
            .having((error) => error.statusCode, 'statusCode', isNull),
      ),
    );
  });
}

class _RecordingAdapter implements HttpClientAdapter {
  _RecordingAdapter({required this.statusCode, this.responseData});

  final int statusCode;
  final Object? responseData;
  RequestOptions? lastOptions;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    lastOptions = options;
    return ResponseBody.fromString(
      responseData == null ? '' : jsonEncode(responseData),
      statusCode,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

class _ThrowingAdapter implements HttpClientAdapter {
  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) {
    throw DioException.connectionError(
      requestOptions: options,
      reason: 'connection refused',
    );
  }

  @override
  void close({bool force = false}) {}
}
