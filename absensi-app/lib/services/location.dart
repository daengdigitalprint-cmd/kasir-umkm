import 'package:geolocator/geolocator.dart';

class LocationResult {
  LocationResult(this.position, this.distanceMeters);

  final Position position;

  /// Jarak ke kantor (meter), null bila toko tidak memakai lokasi kantor.
  final double? distanceMeters;

  bool get isMocked => position.isMocked;
}

class LocationException implements Exception {
  LocationException(this.message, {this.openSettings = false});

  final String message;
  final bool openSettings;

  @override
  String toString() => message;
}

/// Ambil lokasi presisi tinggi + deteksi lokasi palsu (fake GPS).
class LocationService {
  static Future<LocationResult> current({
    double? officeLat,
    double? officeLng,
  }) async {
    if (!await Geolocator.isLocationServiceEnabled()) {
      throw LocationException(
        'GPS di HP mati. Nyalakan Lokasi lalu coba lagi.',
        openSettings: true,
      );
    }

    var permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) {
      permission = await Geolocator.requestPermission();
    }
    if (permission == LocationPermission.denied) {
      throw LocationException('Izin lokasi diperlukan untuk absen.');
    }
    if (permission == LocationPermission.deniedForever) {
      throw LocationException(
        'Izin lokasi ditolak permanen. Buka Pengaturan aplikasi dan izinkan Lokasi.',
        openSettings: true,
      );
    }

    final position = await Geolocator.getCurrentPosition(
      locationSettings: AndroidSettings(
        accuracy: LocationAccuracy.best,
        timeLimit: const Duration(seconds: 25),
      ),
    );

    final distance = officeLat != null && officeLng != null
        ? Geolocator.distanceBetween(
            position.latitude,
            position.longitude,
            officeLat,
            officeLng,
          )
        : null;

    return LocationResult(position, distance);
  }

  static Future<void> openSettings() async {
    if (!await Geolocator.isLocationServiceEnabled()) {
      await Geolocator.openLocationSettings();
    } else {
      await Geolocator.openAppSettings();
    }
  }
}
