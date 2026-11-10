import 'dart:math';

import 'package:android_id/android_id.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Token login dan identitas HP, disimpan terenkripsi di perangkat.
class Session {
  Session._();

  static final instance = Session._();
  static const _storage = FlutterSecureStorage();

  String? token;
  String? deviceId;
  String deviceName = 'HP Android';

  Future<void> load() async {
    token = await _storage.read(key: 'token');
    deviceId = await _resolveDeviceId();
  }

  Future<void> saveToken(String value) async {
    token = value;
    await _storage.write(key: 'token', value: value);
  }

  Future<void> clear() async {
    token = null;
    await _storage.delete(key: 'token');
  }

  /// ID unik HP untuk fitur "1 HP per karyawan". Memakai ANDROID_ID (tetap sama walau
  /// aplikasi dipasang ulang); bila tidak tersedia, ID acak yang disimpan di perangkat.
  Future<String> _resolveDeviceId() async {
    String? id;
    try {
      id = await const AndroidId().getId();
    } catch (_) {
      id = null;
    }
    if (id != null && id.isNotEmpty) return 'android-$id';

    final saved = await _storage.read(key: 'device_id');
    if (saved != null) return saved;

    final random = Random.secure();
    final generated =
        'local-${List.generate(16, (_) => random.nextInt(256).toRadixString(16).padLeft(2, '0')).join()}';
    await _storage.write(key: 'device_id', value: generated);
    return generated;
  }
}
