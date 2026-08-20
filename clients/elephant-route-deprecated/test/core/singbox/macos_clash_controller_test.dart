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

  test('reads the active selector member through the Clash API', () async {
    final adapter = _RecordingAdapter(
      statusCode: 200,
      responseData: const {
        'name': '节点选择',
        'type': 'Selector',
        'now': '东京',
      },
    );
    final dio = Dio(BaseOptions(baseUrl: 'http://127.0.0.1:9090'))
      ..httpClientAdapter = adapter;
    final controller = MacosClashController(dio);

    final selected = await controller.selectedOutbound('节点选择');

    expect(selected, '东京');
    expect(adapter.lastOptions?.method, 'GET');
    expect(
      adapter.lastOptions?.path,
      '/proxies/%E8%8A%82%E7%82%B9%E9%80%89%E6%8B%A9',
    );
  });

  test('reads a selector JSON object served as text/plain', () async {
    final dio = Dio(BaseOptions(baseUrl: 'http://127.0.0.1:9090'))
      ..httpClientAdapter = _RecordingAdapter(
        statusCode: 200,
        contentType: Headers.textPlainContentType,
        responseData: const {
          'name': '节点选择',
          'type': 'Selector',
          'now': '香港',
        },
      );
    final controller = MacosClashController(dio);

    final selected = await controller.selectedOutbound('节点选择');

    expect(selected, '香港');
  });

  test('rejects a selector response without an active member', () async {
    final dio = Dio(BaseOptions(baseUrl: 'http://127.0.0.1:9090'))
      ..httpClientAdapter = _RecordingAdapter(
        statusCode: 200,
        responseData: const {'name': '节点选择', 'type': 'Selector'},
      );
    final controller = MacosClashController(dio);

    await expectLater(
      controller.selectedOutbound('节点选择'),
      throwsA(isA<MacosClashControllerException>()),
    );
  });

  test('parses only valid tracked connections', () async {
    final adapter = _RecordingAdapter(
      statusCode: 200,
      responseData: const {
        'connections': [
          {
            'id': 'old-1',
            'chains': ['东京', '节点选择'],
          },
          {
            'id': '',
            'chains': ['东京', '节点选择'],
          },
          {
            'id': 'invalid-chain',
            'chains': [1, '节点选择'],
          },
          {'id': 'missing-chain'},
        ],
      },
    );
    final dio = Dio(BaseOptions(baseUrl: 'http://127.0.0.1:9090'))
      ..httpClientAdapter = adapter;
    final controller = MacosClashController(dio);

    final connections = await controller.activeConnections();

    expect(connections, hasLength(1));
    expect(connections.single.id, 'old-1');
    expect(connections.single.chains, ['东京', '节点选择']);
    expect(adapter.lastOptions?.method, 'GET');
    expect(adapter.lastOptions?.path, '/connections');
  });

  test('treats an absent connections field as an empty snapshot', () async {
    final dio = Dio(BaseOptions(baseUrl: 'http://127.0.0.1:9090'))
      ..httpClientAdapter = _RecordingAdapter(
        statusCode: 200,
        responseData: const {'downloadTotal': 0, 'uploadTotal': 0},
      );
    final controller = MacosClashController(dio);

    expect(await controller.activeConnections(), isEmpty);
  });

  test('parses connections JSON served as text/plain', () async {
    final dio = Dio(BaseOptions(baseUrl: 'http://127.0.0.1:9090'))
      ..httpClientAdapter = _RecordingAdapter(
        statusCode: 200,
        contentType: Headers.textPlainContentType,
        responseData: const {
          'connections': [
            {
              'id': 'old-1',
              'chains': ['东京', '节点选择'],
            },
          ],
        },
      );
    final controller = MacosClashController(dio);

    final connections = await controller.activeConnections();

    expect(connections.single.id, 'old-1');
    expect(connections.single.chains, ['东京', '节点选择']);
  });

  test('reads delay JSON served as text/plain', () async {
    final dio = Dio(BaseOptions(baseUrl: 'http://127.0.0.1:9090'))
      ..httpClientAdapter = _RecordingAdapter(
        statusCode: 200,
        contentType: Headers.textPlainContentType,
        responseData: const {'delay': 46},
      );
    final controller = MacosClashController(dio);

    expect(await controller.urlTest('香港'), 46);
  });

  test('closes one encoded connection through the Clash API', () async {
    final adapter = _RecordingAdapter(statusCode: 204);
    final dio = Dio(BaseOptions(baseUrl: 'http://127.0.0.1:9090'))
      ..httpClientAdapter = adapter;
    final controller = MacosClashController(dio);

    await controller.closeConnection('old/1');

    expect(adapter.lastOptions?.method, 'DELETE');
    expect(adapter.lastOptions?.path, '/connections/old%2F1');
  });

  test('treats a missing connection as already closed', () async {
    final dio = Dio(BaseOptions(baseUrl: 'http://127.0.0.1:9090'))
      ..httpClientAdapter = _RecordingAdapter(statusCode: 404);
    final controller = MacosClashController(dio);

    await expectLater(controller.closeConnection('gone'), completes);
  });

  test('wraps a rejected connection close response', () async {
    final dio = Dio(BaseOptions(baseUrl: 'http://127.0.0.1:9090'))
      ..httpClientAdapter = _RecordingAdapter(statusCode: 503);
    final controller = MacosClashController(dio);

    await expectLater(
      controller.closeConnection('old-1'),
      throwsA(
        isA<MacosClashControllerException>()
            .having((error) => error.statusCode, 'statusCode', 503),
      ),
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
  _RecordingAdapter({
    required this.statusCode,
    this.responseData,
    this.contentType = Headers.jsonContentType,
  });

  final int statusCode;
  final Object? responseData;
  final String contentType;
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
        Headers.contentTypeHeader: [contentType],
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
