/// Alamat server. Build produksi memakai default; untuk uji lokal:
///   flutter run --dart-define=API_BASE=http://10.0.2.2:8077
const String apiBase = String.fromEnvironment(
  'API_BASE',
  defaultValue: 'https://kasirumkm.web.id',
);

const String appName = 'Absensi UMKM Kasir';
