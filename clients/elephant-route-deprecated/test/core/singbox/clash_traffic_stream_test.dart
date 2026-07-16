import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:elephant_network/core/singbox/clash_traffic_stream.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  group('ClashTrafficStreamClient', () {
    late HttpServer server;
    late ClashTrafficStreamClient client;
    var requestCount = 0;

    setUp(() async {
      requestCount = 0;
      server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
      client = ClashTrafficStreamClient(
        endpoint: Uri.parse(
          'http://${server.address.address}:${server.port}/traffic',
        ),
      );
    });

    tearDown(() async {
      await client.close();
      await server.close(force: true);
    });

    test('parses split newline-delimited samples over one HTTP request',
        () async {
      server.listen((request) async {
        requestCount++;
        request.response.statusCode = HttpStatus.ok;
        request.response.headers.contentType = ContentType.json;
        request.response.bufferOutput = false;
        request.response.write('{"up":12');
        await request.response.flush();
        request.response.write('3,"down":456}\n');
        request.response.write('{"up":789,"down":1011}\n');
        await request.response.flush();
      });

      final samples = await client.open().take(2).toList();

      expect(requestCount, 1);
      expect(
        samples,
        const <TrafficSample>[
          TrafficSample(up: 123, down: 456),
          TrafficSample(up: 789, down: 1011),
        ],
      );
    });

    test('ignores malformed lines without replacing the active connection',
        () async {
      server.listen((request) async {
        requestCount++;
        request.response.bufferOutput = false;
        request.response.write('not-json\n');
        request.response.write('{"up":"bad","down":2}\n');
        request.response.write('${jsonEncode({'up': 3, 'down': 4})}\n');
        await request.response.flush();
      });

      final sample = await client.open().first;

      expect(requestCount, 1);
      expect(sample, const TrafficSample(up: 3, down: 4));
    });

    test('rejects overlapping opens and releases ownership after cancellation',
        () async {
      final firstSample = Completer<void>();
      server.listen((request) async {
        requestCount++;
        request.response.bufferOutput = false;
        request.response.write('{"up":1,"down":2}\n');
        await request.response.flush();
      });

      final subscription = client.open().listen((_) {
        if (!firstSample.isCompleted) firstSample.complete();
      });
      await firstSample.future;

      expect(client.open, throwsStateError);

      await subscription.cancel();
      await client.close();

      final nextSample = await client.open().first;
      expect(nextSample, const TrafficSample(up: 1, down: 2));
      expect(requestCount, 2);
    });
  });
}
