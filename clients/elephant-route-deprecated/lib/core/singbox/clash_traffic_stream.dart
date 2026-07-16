import 'dart:async';
import 'dart:convert';
import 'dart:io';

class TrafficSample {
  const TrafficSample({required this.up, required this.down});

  final int up;
  final int down;

  @override
  bool operator ==(Object other) {
    return other is TrafficSample && other.up == up && other.down == down;
  }

  @override
  int get hashCode => Object.hash(up, down);

  @override
  String toString() => 'TrafficSample(up: $up, down: $down)';
}

abstract interface class TrafficStreamClient {
  Stream<TrafficSample> open();

  Future<void> close();
}

class ClashTrafficStreamClient implements TrafficStreamClient {
  ClashTrafficStreamClient({
    required this.endpoint,
    HttpClient Function()? clientFactory,
  }) : _clientFactory = clientFactory ?? HttpClient.new;

  final Uri endpoint;
  final HttpClient Function() _clientFactory;

  _TrafficStreamSession? _activeSession;

  @override
  Stream<TrafficSample> open() {
    if (_activeSession != null) {
      throw StateError('A Clash traffic stream is already active');
    }

    final session = _TrafficStreamSession(
      endpoint: endpoint,
      client: _clientFactory(),
      parseSample: _parseSample,
    );
    _activeSession = session;
    unawaited(session.done.whenComplete(() {
      if (identical(_activeSession, session)) {
        _activeSession = null;
      }
    }));
    return session.stream;
  }

  TrafficSample? _parseSample(String line) {
    if (line.trim().isEmpty) return null;
    try {
      final decoded = jsonDecode(line);
      if (decoded is! Map) return null;
      final up = decoded['up'];
      final down = decoded['down'];
      if (up is! num || down is! num) return null;
      return TrafficSample(up: up.toInt(), down: down.toInt());
    } on FormatException {
      return null;
    }
  }

  @override
  Future<void> close() async {
    final session = _activeSession;
    _activeSession = null;
    await session?.close();
  }
}

class _TrafficStreamSession {
  _TrafficStreamSession({
    required this.endpoint,
    required HttpClient client,
    required TrafficSample? Function(String line) parseSample,
  })  : _client = client,
        _parseSample = parseSample {
    _controller = StreamController<TrafficSample>(
      onListen: _start,
      onCancel: close,
    );
  }

  final Uri endpoint;
  final HttpClient _client;
  final TrafficSample? Function(String line) _parseSample;
  final Completer<void> _done = Completer<void>();

  late final StreamController<TrafficSample> _controller;
  StreamSubscription<String>? _lineSubscription;
  Future<void>? _closeFuture;

  Stream<TrafficSample> get stream => _controller.stream;
  Future<void> get done => _done.future;

  Future<void> _start() async {
    _client.connectionTimeout = const Duration(seconds: 3);
    _client.findProxy = (_) => 'DIRECT';

    try {
      final request = await _client.getUrl(endpoint);
      request.headers.set(HttpHeaders.acceptHeader, ContentType.json.mimeType);
      final response = await request.close();
      if (response.statusCode != HttpStatus.ok) {
        throw HttpException(
          'Clash traffic stream returned HTTP ${response.statusCode}',
          uri: endpoint,
        );
      }

      _lineSubscription =
          utf8.decoder.bind(response).transform(const LineSplitter()).listen(
        (line) {
          final sample = _parseSample(line);
          if (sample != null && !_controller.isClosed) {
            _controller.add(sample);
          }
        },
        onError: (Object error, StackTrace stackTrace) {
          if (!_controller.isClosed) {
            _controller.addError(error, stackTrace);
          }
          unawaited(close());
        },
        onDone: () => unawaited(close()),
        cancelOnError: true,
      );
    } catch (error, stackTrace) {
      if (!_controller.isClosed) {
        _controller.addError(error, stackTrace);
      }
      await close();
    }
  }

  Future<void> close() {
    return _closeFuture ??= _closeOwnedResources();
  }

  Future<void> _closeOwnedResources() async {
    _client.close(force: true);
    await _lineSubscription?.cancel();
    if (!_controller.isClosed) {
      await _controller.close();
    }
    if (!_done.isCompleted) {
      _done.complete();
    }
  }
}
