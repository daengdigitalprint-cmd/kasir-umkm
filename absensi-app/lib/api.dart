import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:http/http.dart' as http;

import 'config.dart';
import 'services/session.dart';

/// Kesalahan dari server dengan pesan yang siap ditampilkan ke karyawan.
class ApiException implements Exception {
  ApiException(this.message, {this.status = 0, this.code});

  final String message;
  final int status;
  final String? code;

  bool get isUnauthorized => status == 401;

  @override
  String toString() => message;
}

/// Klien API absensi (`/api/v1/...`).
class Api {
  Api._();

  static final instance = Api._();
  final _client = http.Client();
  static const _timeout = Duration(seconds: 30);

  /// Header otentikasi, juga dipakai untuk memuat gambar privat (foto profil).
  Map<String, String> get authHeaders => _headers;

  Map<String, String> get _headers {
    final session = Session.instance;
    return {
      'Accept': 'application/json',
      if (session.token != null) 'Authorization': 'Bearer ${session.token}',
      if (session.deviceId != null) 'X-Device-Id': session.deviceId!,
      'X-Device-Name': session.deviceName,
    };
  }

  Uri _uri(String path, [Map<String, String>? query]) =>
      Uri.parse('$apiBase/api/v1$path').replace(queryParameters: query);

  Future<Map<String, dynamic>> get(String path, {Map<String, String>? query}) =>
      _send(() => _client.get(_uri(path, query), headers: _headers));

  Future<Map<String, dynamic>> post(String path, Map<String, dynamic> body) =>
      _send(
        () => _client.post(
          _uri(path),
          headers: {..._headers, 'Content-Type': 'application/json'},
          body: jsonEncode(body),
        ),
      );

  /// Kirim absen: data lokasi + foto selfie (multipart).
  Future<Map<String, dynamic>> postCapture(
    String path,
    Map<String, String> fields,
    File? photo,
  ) => _send(() async {
    final request = http.MultipartRequest('POST', _uri(path))
      ..headers.addAll(_headers)
      ..fields.addAll(fields);
    if (photo != null) {
      request.files.add(
        await http.MultipartFile.fromPath(
          'photo',
          photo.path,
          filename: 'selfie.jpg',
        ),
      );
    }
    return http.Response.fromStream(await _client.send(request));
  });

  Future<Map<String, dynamic>> delete(String path) =>
      _send(() => _client.delete(_uri(path), headers: _headers));

  Future<Uint8List> download(String path) async {
    final response = await _guard(
      () => _client.get(_uri(path), headers: _headers),
    );
    if (response.statusCode != 200) throw _toException(response);
    return response.bodyBytes;
  }

  Future<Map<String, dynamic>> _send(
    Future<http.Response> Function() call,
  ) async {
    final response = await _guard(call);
    if (response.statusCode >= 200 && response.statusCode < 300) {
      return response.body.isEmpty
          ? {}
          : jsonDecode(response.body) as Map<String, dynamic>;
    }
    throw _toException(response);
  }

  Future<http.Response> _guard(Future<http.Response> Function() call) async {
    try {
      return await call().timeout(_timeout);
    } on SocketException {
      throw ApiException(
        'Tidak ada koneksi internet. Periksa jaringan lalu coba lagi.',
      );
    } on HttpException {
      throw ApiException('Gagal terhubung ke server. Coba lagi sebentar lagi.');
    } on Exception catch (e) {
      if (e.toString().contains('TimeoutException')) {
        throw ApiException('Server terlalu lama merespons. Coba lagi.');
      }
      rethrow;
    }
  }

  ApiException _toException(http.Response response) {
    Map<String, dynamic> body = {};
    try {
      body = jsonDecode(response.body) as Map<String, dynamic>;
    } catch (_) {}

    // Pesan validasi Laravel: ambil pesan pertama yang paling relevan.
    final errors = body['errors'];
    if (errors is Map && errors.isNotEmpty) {
      final first = errors.values.first;
      final message = first is List && first.isNotEmpty
          ? first.first.toString()
          : first.toString();
      return ApiException(
        message,
        status: response.statusCode,
        code: 'validation',
      );
    }

    final message = switch (response.statusCode) {
      401 => 'Sesi login berakhir. Silakan masuk lagi.',
      429 => 'Terlalu banyak percobaan. Tunggu sebentar.',
      >= 500 => 'Terjadi gangguan di server. Coba lagi nanti.',
      _ =>
        body['message']?.toString() ??
            'Permintaan gagal (${response.statusCode}).',
    };

    final useFixed =
        response.statusCode == 401 ||
        response.statusCode == 429 ||
        response.statusCode >= 500;

    return ApiException(
      useFixed ? message : (body['message']?.toString() ?? message),
      status: response.statusCode,
      code: body['code']?.toString(),
    );
  }
}
