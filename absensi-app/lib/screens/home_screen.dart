import 'dart:async';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:geolocator/geolocator.dart';
import 'package:intl/intl.dart';

import '../api.dart';
import '../main.dart';
import '../services/location.dart';
import '../services/reminders.dart';
import '../theme.dart';
import '../widgets/avatar.dart';
import '../widgets/common.dart';
import '../widgets/swipe_button.dart';
import '../widgets/week_chart.dart';
import 'selfie_screen.dart';
import 'shell.dart';

/// Beranda absen: jam, jadwal hari ini, status lokasi, dan tombol absen masuk/pulang.
class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  static const _storage = FlutterSecureStorage();

  Map<String, dynamic>? _me;
  Map<String, dynamic>? _summary;
  List<Map<String, dynamic>> _days = [];
  String? _error;
  bool _loading = true;
  bool _busy = false;
  bool _remindersOn = true;
  DateTime _now = DateTime.now();
  Timer? _ticker;
  LocationResult? _location;
  String? _locationError;

  /// Outlet terdekat untuk absen masuk bila karyawan terdaftar di beberapa outlet.
  Map<String, dynamic>? _outlet;

  @override
  void initState() {
    super.initState();
    _ticker = Timer.periodic(
      const Duration(seconds: 1),
      (_) => setState(() => _now = DateTime.now()),
    );
    _init();
    Shell.profileChanged.addListener(_load);
  }

  @override
  void dispose() {
    _ticker?.cancel();
    Shell.profileChanged.removeListener(_load);
    super.dispose();
  }

  Future<void> _init() async {
    _remindersOn = (await _storage.read(key: 'reminders')) != 'off';
    await Reminders.requestPermission();
    await _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = _me == null;
      _error = null;
    });
    try {
      final me = await Api.instance.get('/attendance/me');
      if (!mounted) return;
      setState(() {
        _me = me;
        _outlet = null;
      });
      unawaited(_loadSummary());
      unawaited(_refreshLocation());
      unawaited(_scheduleReminders());
    } on ApiException catch (e) {
      if (e.isUnauthorized) return AbsensiApp.signOut();
      setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _loadSummary() async {
    try {
      final now = DateTime.now();
      final data = await Api.instance.get(
        '/attendance/history',
        query: {'month': DateFormat('yyyy-MM').format(now)},
      );
      final days = (data['days'] as List).cast<Map<String, dynamic>>().toList();
      // Grafik 7 hari: di awal bulan, ambil juga data bulan lalu.
      if (now.day < 7) {
        final prev = await Api.instance.get(
          '/attendance/history',
          query: {
            'month': DateFormat('yyyy-MM')
                .format(DateTime(now.year, now.month - 1)),
          },
        );
        days.addAll((prev['days'] as List).cast<Map<String, dynamic>>());
      }
      if (mounted) {
        setState(() {
          _summary = data['summary'] as Map<String, dynamic>;
          _days = days;
        });
      }
    } catch (_) {
      // Ringkasan bersifat pelengkap; abaikan bila gagal.
    }
  }

  Future<void> _scheduleReminders() async {
    final me = _me;
    if (me == null) return;
    final attendance = me['attendance'] as Map<String, dynamic>?;
    await Reminders.schedule(
      weekSchedule: me['week_schedule'] as List<dynamic>,
      clockedInToday: attendance?['clock_in'] != null,
      clockedOutToday: attendance?['clock_out'] != null,
      enabled: _remindersOn,
    );
  }

  Map<String, dynamic> get _policy => _me!['policy'] as Map<String, dynamic>;

  /// Aturan outlet yang sedang dipakai: outlet terdekat (absen masuk multi-outlet)
  /// atau outlet dari server (absen pulang selalu ke outlet absen masuk).
  String get _storeName =>
      (_outlet?['name'] ?? (_me!['store'] as Map<String, dynamic>)['name'])
          as String;
  double get _radius =>
      ((_outlet?['radius_meters'] ?? _policy['radius_meters']) as num)
          .toDouble();
  bool get _requireGeo =>
      (_outlet?['require_geolocation'] ?? _policy['require_geolocation']) ==
      true;
  bool get _requireSelfie =>
      (_outlet?['require_selfie'] ?? _policy['require_selfie']) == true;

  /// Pilih outlet terdekat (yang radiusnya mencakup posisi lebih dulu).
  Map<String, dynamic>? _nearestOutlet(LocationResult result) {
    final outlets = (_me!['outlets'] as List<dynamic>? ?? const [])
        .cast<Map<String, dynamic>>()
        .where((o) => o['office'] != null)
        .toList();
    if (_me!['next_action'] != 'clock_in' || outlets.length < 2) return null;
    double distanceTo(Map<String, dynamic> o) {
      final office = o['office'] as Map<String, dynamic>;
      return Geolocator.distanceBetween(
        result.position.latitude,
        result.position.longitude,
        (office['latitude'] as num).toDouble(),
        (office['longitude'] as num).toDouble(),
      );
    }

    outlets.sort((a, b) => distanceTo(a).compareTo(distanceTo(b)));
    return outlets.first;
  }

  Future<void> _refreshLocation() async {
    final office = _policy['office'] as Map<String, dynamic>?;
    try {
      final result = await LocationService.current(
        officeLat: (office?['latitude'] as num?)?.toDouble(),
        officeLng: (office?['longitude'] as num?)?.toDouble(),
      );
      final outlet = _nearestOutlet(result);
      final office2 = outlet?['office'] as Map<String, dynamic>?;
      final located = office2 == null
          ? result
          : LocationResult(
              result.position,
              Geolocator.distanceBetween(
                result.position.latitude,
                result.position.longitude,
                (office2['latitude'] as num).toDouble(),
                (office2['longitude'] as num).toDouble(),
              ),
            );
      if (mounted) {
        setState(() {
          _outlet = outlet;
          _location = located;
          _locationError = null;
        });
      }
    } on LocationException catch (e) {
      if (mounted) setState(() => _locationError = e.message);
    } catch (_) {
      if (mounted) {
        setState(
          () => _locationError = 'Lokasi belum didapat. Pastikan GPS menyala.',
        );
      }
    }
  }

  Future<void> _submit(String action) async {
    final me = _me!;
    final employee = me['employee'] as Map<String, dynamic>;
    final requireGeo = _requireGeo;
    final radius = _radius;
    final maxAccuracy = radius > 150 ? radius : 150.0;

    Future<void> fail(String title, String message) async {
      if (mounted) await showMessage(context, title, message);
    }

    setState(() => _busy = true);
    try {
      // 1. Lokasi + deteksi fake GPS
      LocationResult? location;
      try {
        await _refreshLocation();
        location = _location;
      } catch (_) {}
      if (requireGeo) {
        if (location == null) {
          await fail(
            'Lokasi belum didapat',
            _locationError ?? 'Nyalakan GPS lalu coba lagi.',
          );
          return;
        }
        if (location.isMocked) {
          await fail(
            'Lokasi palsu terdeteksi',
            'Matikan aplikasi pemalsu lokasi (fake GPS) lalu coba lagi.',
          );
          return;
        }
        if (location.position.accuracy > maxAccuracy) {
          await fail(
            'Sinyal GPS lemah',
            'Akurasi lokasi ±${location.position.accuracy.round()} m. Pindah ke tempat terbuka sebentar lalu coba lagi.',
          );
          return;
        }
        if (location.distanceMeters != null &&
            location.distanceMeters! > radius) {
          await fail(
            'Di luar area toko',
            'Anda berjarak ${_distanceLabel(location.distanceMeters!)} dari toko (batas ${radius.round()} m).',
          );
          return;
        }
      }

      // 2. Selfie dengan watermark
      final requireSelfie = _requireSelfie;
      File? photo;
      if (requireSelfie) {
        if (!mounted) return;
        photo = await Navigator.of(context).push<File>(
          MaterialPageRoute(
            fullscreenDialog: true,
            builder: (_) => SelfieScreen(
              title: action == 'clock_in'
                  ? 'Selfie absen masuk'
                  : 'Selfie absen pulang',
              watermark: [
                '${action == 'clock_in' ? 'MASUK' : 'PULANG'}  ${DateFormat('dd/MM/yyyy HH:mm:ss').format(DateTime.now())}',
                '${employee['name']} - $_storeName',
                if (location != null)
                  'GPS ${location.position.latitude.toStringAsFixed(5)}, ${location.position.longitude.toStringAsFixed(5)}'
                      '${location.distanceMeters != null ? '  (${location.distanceMeters!.round()} m dari toko)' : ''}',
              ],
            ),
          ),
        );
        if (photo == null) return; // dibatalkan
      }

      // 3. Kirim
      final result = await Api.instance.postCapture(
        action == 'clock_in' ? '/attendance/clock-in' : '/attendance/clock-out',
        {
          if (location != null) ...{
            'latitude': location.position.latitude.toString(),
            'longitude': location.position.longitude.toString(),
            'accuracy': location.position.accuracy.toString(),
            'is_mocked': location.isMocked ? '1' : '0',
          },
        },
        photo,
      );
      if (!mounted) return;
      await _showSuccess(result['message'] as String, photo);
      await _load();
    } on ApiException catch (e) {
      if (e.isUnauthorized) return AbsensiApp.signOut();
      final title = switch (e.code) {
        'device_mismatch' => 'HP tidak terdaftar',
        'device_taken' => 'HP milik karyawan lain',
        'module_inactive' => 'Absensi belum aktif',
        _ => 'Absen gagal',
      };
      await fail(title, e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _showSuccess(String message, File? photo) {
    return showModalBottomSheet<void>(
      context: context,
      showDragHandle: true,
      builder: (context) => Padding(
        padding: const EdgeInsets.fromLTRB(24, 0, 24, 32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const CircleAvatar(
              radius: 34,
              backgroundColor: Brand.present,
              child: Icon(Icons.check_rounded, color: Colors.white, size: 40),
            ),
            const SizedBox(height: 14),
            Text(
              message,
              textAlign: TextAlign.center,
              style: const TextStyle(
                fontSize: 18,
                fontWeight: FontWeight.w700,
                color: Brand.ink,
              ),
            ),
            if (photo != null) ...[
              const SizedBox(height: 16),
              ClipRRect(
                borderRadius: BorderRadius.circular(16),
                child: Image.file(photo, height: 180),
              ),
            ],
            const SizedBox(height: 20),
            FilledButton(
              onPressed: () => Navigator.pop(context),
              child: const Text('Selesai'),
            ),
          ],
        ),
      ),
    );
  }

  String _distanceLabel(double meters) => meters >= 1000
      ? '${(meters / 1000).toStringAsFixed(1)} km'
      : '${meters.round()} m';

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }
    if (_me == null) {
      return Scaffold(
        body: ErrorView(
          message: _error ?? 'Gagal memuat data.',
          onRetry: _load,
        ),
      );
    }

    final me = _me!;
    final employee = me['employee'] as Map<String, dynamic>;
    final today = me['today'] as Map<String, dynamic>;
    final schedule = today['schedule'] as Map<String, dynamic>?;
    final attendance = me['attendance'] as Map<String, dynamic>?;
    final nextAction = me['next_action'] as String;
    final device = me['device'] as Map<String, dynamic>;
    final hour = _now.hour;
    final greeting = hour < 11
        ? 'Selamat pagi'
        : hour < 15
        ? 'Selamat siang'
        : hour < 18
        ? 'Selamat sore'
        : 'Selamat malam';
    final name = employee['name'] as String;
    // Ringkas agar muat di header: cukup jam shift (nama shift ada di tab Profil).
    final shiftText = today['is_holiday'] == true
        ? 'Libur'
        : schedule == null
        ? 'Tanpa jadwal'
        : '${schedule['shift_start']}–${schedule['shift_end']}';

    return Scaffold(
      body: RefreshIndicator(
        onRefresh: _load,
        edgeOffset: MediaQuery.of(context).padding.top,
        child: ListView(
          padding: EdgeInsets.zero,
          children: [
            // ===== Hero =====
            Stack(
              clipBehavior: Clip.none,
              children: [
                Container(
                  height: 186 + MediaQuery.of(context).padding.top,
                  decoration: const BoxDecoration(
                    gradient: Brand.heroGradient,
                    borderRadius: BorderRadius.vertical(
                      bottom: Radius.circular(36),
                    ),
                  ),
                ),
                Padding(
                  padding: EdgeInsets.fromLTRB(
                    20,
                    MediaQuery.of(context).padding.top + 14,
                    20,
                    0,
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          GestureDetector(
                            onTap: () => Shell.tab.value = Shell.profil,
                            child: EmployeeAvatar(
                              name: name,
                              photoUrl: employee['photo_url'] as String?,
                              size: 52,
                              radius: 18,
                            ),
                          ),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  '$greeting,',
                                  style: TextStyle(
                                    color: Colors.white.withValues(alpha: 0.8),
                                    fontSize: 12.5,
                                  ),
                                ),
                                Text(
                                  name,
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                  style: const TextStyle(
                                    color: Colors.white,
                                    fontSize: 18,
                                    fontWeight: FontWeight.w800,
                                  ),
                                ),
                                Text(
                                  _storeName,
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                  style: TextStyle(
                                    color: Colors.white.withValues(alpha: 0.75),
                                    fontSize: 12,
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 16),
                      Row(
                        crossAxisAlignment: CrossAxisAlignment.end,
                        children: [
                          Text(
                            DateFormat('HH:mm').format(_now),
                            style: const TextStyle(
                              color: Colors.white,
                              fontSize: 40,
                              height: 1,
                              fontWeight: FontWeight.w800,
                              letterSpacing: -0.5,
                            ),
                          ),
                          const SizedBox(width: 10),
                          Expanded(
                            child: Padding(
                              padding: const EdgeInsets.only(bottom: 3),
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(
                                    DateFormat('EEEE', 'id_ID').format(_now),
                                    style: const TextStyle(
                                      color: Colors.white,
                                      fontSize: 13,
                                      fontWeight: FontWeight.w700,
                                    ),
                                  ),
                                  Text(
                                    DateFormat(
                                      'd MMMM yyyy',
                                      'id_ID',
                                    ).format(_now),
                                    style: TextStyle(
                                      color: Colors.white.withValues(
                                        alpha: 0.8,
                                      ),
                                      fontSize: 12,
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          ),
                          Flexible(
                            child: _HeroChip(
                              icon: Icons.schedule_rounded,
                              text: shiftText,
                              accent: true,
                            ),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
                // Kartu kehadiran hari ini menumpang di bawah hero
                Padding(
                  padding: EdgeInsets.fromLTRB(
                    20,
                    152 + MediaQuery.of(context).padding.top,
                    20,
                    0,
                  ),
                  child: _TodayCard(
                    attendance: attendance,
                    schedule: schedule,
                    now: _now,
                  ),
                ),
              ],
            ),

            Padding(
              padding: const EdgeInsets.fromLTRB(20, 18, 20, 28),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  if (device['is_this_device'] == false) ...[
                    _Banner(
                      color: Brand.absent,
                      icon: Icons.phonelink_lock_rounded,
                      text:
                          (device['message'] as String?) ??
                          'Akun ini terdaftar di HP lain (${device['name']}). Minta pemilik toko melepas HP lama di menu Karyawan.',
                    ),
                    const SizedBox(height: 16),
                  ],

                  // ===== Tombol geser absen =====
                  if (nextAction == 'done')
                    const _DoneBadge()
                  else
                    SwipeButton(
                      label: nextAction == 'clock_in'
                          ? 'Swipe to Check In'
                          : 'Swipe to Check Out',
                      icon: nextAction == 'clock_in'
                          ? Icons.fingerprint_rounded
                          : Icons.logout_rounded,
                      colors: nextAction == 'clock_in'
                          ? const [Color(0xFF6A4DFF), Brand.violetDark]
                          : const [Color(0xFFFFA940), Brand.orangeDark],
                      busy: _busy,
                      enabled: device['is_this_device'] != false,
                      onSwiped: () => _submit(nextAction),
                    ),
                  const SizedBox(height: 10),
                  Text(
                    [
                      if (_requireSelfie) 'Wajib selfie',
                      if (_requireGeo) 'Radius ${_radius.round()} m',
                      'Toleransi telat ${_policy['grace_minutes']} menit',
                    ].join('  ·  '),
                    textAlign: TextAlign.center,
                    style: const TextStyle(color: Brand.muted, fontSize: 12),
                  ),
                  const SizedBox(height: 14),
                  _LocationPill(
                    location: _location,
                    error: _locationError,
                    requireGeo: _requireGeo,
                    radius: _radius,
                    label: _location?.distanceMeters != null
                        ? _distanceLabel(_location!.distanceMeters!)
                        : null,
                    onRetry: () async {
                      final message = _locationError ?? '';
                      if (message.contains('Pengaturan') ||
                          message.contains('GPS di HP mati')) {
                        await LocationService.openSettings();
                      }
                      await _refreshLocation();
                    },
                  ),

                  // ===== Ringkasan bulan ini =====
                  if (_summary != null) ...[
                    const SizedBox(height: 22),
                    SectionTitle(
                      'Bulan ${DateFormat('MMMM', 'id_ID').format(_now)}',
                      action: TextButton(
                        onPressed: () => Shell.tab.value = Shell.riwayat,
                        child: const Text('Lihat riwayat'),
                      ),
                    ),
                    _MonthSummary(summary: _summary!),
                    const SizedBox(height: 12),
                    WeekChart(days: _days, today: _now),
                  ],
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _HeroChip extends StatelessWidget {
  const _HeroChip({
    required this.icon,
    required this.text,
    this.accent = false,
  });

  final IconData icon;
  final String text;
  final bool accent;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: 0.16),
        borderRadius: BorderRadius.circular(99),
        border: Border.all(color: Colors.white.withValues(alpha: 0.18)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 15, color: accent ? Brand.lime : Colors.white),
          const SizedBox(width: 6),
          Flexible(
            child: Text(
              text,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(
                color: Colors.white,
                fontSize: 12.5,
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// Kartu "Kehadiran hari ini": jam masuk → jam pulang + progres shift.
class _TodayCard extends StatelessWidget {
  const _TodayCard({
    required this.attendance,
    required this.schedule,
    required this.now,
  });

  final Map<String, dynamic>? attendance;
  final Map<String, dynamic>? schedule;
  final DateTime now;

  double? _progress() {
    if (schedule == null || attendance?['clock_in'] == null) return null;
    if (attendance?['clock_out'] != null) return 1;
    DateTime at(String hhmm) {
      final p = hhmm.split(':');
      return DateTime(
        now.year,
        now.month,
        now.day,
        int.parse(p[0]),
        int.parse(p[1]),
      );
    }

    final start = at(schedule!['shift_start'] as String);
    var end = at(schedule!['shift_end'] as String);
    if (!end.isAfter(start)) end = end.add(const Duration(days: 1));
    final total = end.difference(start).inMinutes;
    if (total <= 0) return null;
    return (now.difference(start).inMinutes / total).clamp(0.0, 1.0);
  }

  @override
  Widget build(BuildContext context) {
    final clockIn = attendance?['clock_in'] as String?;
    final clockOut = attendance?['clock_out'] as String?;
    final late = (attendance?['late_minutes'] as int?) ?? 0;
    final progress = _progress();

    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(24),
        boxShadow: Brand.softShadow(Brand.violetDeep),
      ),
      child: Column(
        children: [
          Row(
            children: [
              const Text(
                'Kehadiran hari ini',
                style: TextStyle(fontWeight: FontWeight.w800, color: Brand.ink),
              ),
              const Spacer(),
              if (attendance?['status'] != null)
                StatusChip(attendance!['status'] as String),
            ],
          ),
          const SizedBox(height: 14),
          Row(
            children: [
              Expanded(
                child: _TimeBlock(
                  icon: Icons.login_rounded,
                  color: Brand.present,
                  label: 'Masuk',
                  value: clockIn,
                ),
              ),
              Container(width: 1, height: 44, color: Brand.line),
              Expanded(
                child: _TimeBlock(
                  icon: Icons.logout_rounded,
                  color: Brand.orange,
                  label: 'Pulang',
                  value: clockOut,
                ),
              ),
            ],
          ),
          if (progress != null) ...[
            const SizedBox(height: 14),
            ClipRRect(
              borderRadius: BorderRadius.circular(99),
              child: LinearProgressIndicator(
                value: progress,
                minHeight: 7,
                backgroundColor: Brand.violet.withValues(alpha: 0.1),
                valueColor: AlwaysStoppedAnimation(
                  clockOut != null ? Brand.present : Brand.violet,
                ),
              ),
            ),
            const SizedBox(height: 6),
            Row(
              children: [
                Text(
                  clockOut != null
                      ? 'Kerja ${formatDuration(attendance?['hours_worked'] as num?)}'
                      : 'Shift berjalan ${(progress * 100).round()}%',
                  style: const TextStyle(
                    fontSize: 12,
                    color: Brand.muted,
                    fontWeight: FontWeight.w600,
                  ),
                ),
                const Spacer(),
                if (late > 0)
                  Text(
                    'Telat $late menit',
                    style: const TextStyle(
                      fontSize: 12,
                      color: Brand.late,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}

class _TimeBlock extends StatelessWidget {
  const _TimeBlock({
    required this.icon,
    required this.color,
    required this.label,
    required this.value,
  });

  final IconData icon;
  final Color color;
  final String label;
  final String? value;

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(icon, size: 16, color: color),
            const SizedBox(width: 5),
            Text(
              label,
              style: const TextStyle(
                color: Brand.muted,
                fontSize: 13,
                fontWeight: FontWeight.w600,
              ),
            ),
          ],
        ),
        const SizedBox(height: 4),
        Text(
          value ?? '--:--',
          style: TextStyle(
            fontSize: 26,
            fontWeight: FontWeight.w800,
            color: value == null ? const Color(0xFFC9CDDA) : Brand.ink,
          ),
        ),
      ],
    );
  }
}

class _DoneBadge extends StatelessWidget {
  const _DoneBadge();

  @override
  Widget build(BuildContext context) {
    return Container(
      height: 64,
      padding: const EdgeInsets.symmetric(horizontal: 18),
      decoration: BoxDecoration(
        color: Brand.present.withValues(alpha: 0.1),
        borderRadius: BorderRadius.circular(32),
      ),
      child: const Row(
        children: [
          Icon(Icons.verified_rounded, color: Brand.present, size: 30),
          SizedBox(width: 12),
          Expanded(
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Absen hari ini lengkap',
                  style: TextStyle(
                    fontWeight: FontWeight.w800,
                    color: Brand.ink,
                  ),
                ),
                Text(
                  'Terima kasih, selamat istirahat!',
                  style: TextStyle(color: Brand.muted, fontSize: 12.5),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _LocationPill extends StatelessWidget {
  const _LocationPill({
    required this.location,
    required this.error,
    required this.requireGeo,
    required this.radius,
    required this.label,
    required this.onRetry,
  });

  final LocationResult? location;
  final String? error;
  final bool requireGeo;
  final double radius;
  final String? label;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    late final Color color;
    late final IconData icon;
    late final String text;

    if (error != null) {
      (color, icon, text) = (
        Brand.late,
        Icons.location_disabled_rounded,
        error!,
      );
    } else if (location == null) {
      (color, icon, text) = (
        Brand.muted,
        Icons.my_location_rounded,
        'Mencari lokasi Anda...',
      );
    } else if (location!.isMocked) {
      (color, icon, text) = (
        Brand.absent,
        Icons.gpp_bad_rounded,
        'Lokasi palsu terdeteksi',
      );
    } else if (location!.distanceMeters == null) {
      (color, icon, text) = (
        Brand.present,
        Icons.location_on_rounded,
        'Lokasi didapat · akurasi ±${location!.position.accuracy.round()} m',
      );
    } else if (location!.distanceMeters! <= radius) {
      (color, icon, text) = (
        Brand.present,
        Icons.where_to_vote_rounded,
        'Di area toko · $label',
      );
    } else {
      (color, icon, text) = (
        requireGeo ? Brand.absent : Brand.late,
        Icons.wrong_location_rounded,
        'Di luar area toko · $label',
      );
    }

    return Material(
      color: color.withValues(alpha: 0.08),
      borderRadius: BorderRadius.circular(16),
      child: InkWell(
        borderRadius: BorderRadius.circular(16),
        onTap: onRetry,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
          child: Row(
            children: [
              Icon(icon, color: color, size: 22),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  text,
                  style: TextStyle(
                    color: color == Brand.muted ? Brand.ink : color,
                    fontWeight: FontWeight.w700,
                    fontSize: 13.5,
                  ),
                ),
              ),
              Icon(
                Icons.refresh_rounded,
                color: color.withValues(alpha: 0.8),
                size: 20,
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _MonthSummary extends StatelessWidget {
  const _MonthSummary({required this.summary});

  final Map<String, dynamic> summary;

  @override
  Widget build(BuildContext context) {
    final present = summary['present_days'] as int;
    final late = summary['late_days'] as int;
    final items = [
      (
        'Tepat waktu',
        present - late,
        Brand.present,
        Icons.check_circle_rounded,
      ),
      ('Telat', late, Brand.late, Icons.schedule_rounded),
      (
        'Alpa',
        summary['absent_days'] as int,
        Brand.absent,
        Icons.cancel_rounded,
      ),
      (
        'Cuti',
        summary['leave_days'] as int,
        Brand.leave,
        Icons.beach_access_rounded,
      ),
    ];
    return Row(
      children: [
        for (final (label, value, color, icon) in items)
          Expanded(
            child: Container(
              margin: EdgeInsets.only(right: label == 'Cuti' ? 0 : 8),
              padding: const EdgeInsets.symmetric(vertical: 14),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(18),
                boxShadow: Brand.softShadow(),
              ),
              child: Column(
                children: [
                  Icon(icon, color: color, size: 20),
                  const SizedBox(height: 6),
                  Text(
                    '$value',
                    style: TextStyle(
                      fontSize: 20,
                      fontWeight: FontWeight.w800,
                      color: color,
                    ),
                  ),
                  Text(
                    label,
                    style: const TextStyle(
                      fontSize: 11,
                      color: Brand.muted,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ],
              ),
            ),
          ),
      ],
    );
  }
}

class _Banner extends StatelessWidget {
  const _Banner({required this.color, required this.icon, required this.text});

  final Color color;
  final IconData icon;
  final String text;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.1),
        borderRadius: BorderRadius.circular(16),
      ),
      child: Row(
        children: [
          Icon(icon, color: color),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              text,
              style: TextStyle(color: color, fontWeight: FontWeight.w600),
            ),
          ),
        ],
      ),
    );
  }
}
