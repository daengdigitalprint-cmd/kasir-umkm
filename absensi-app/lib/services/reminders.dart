import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:flutter_timezone/flutter_timezone.dart';
import 'package:timezone/data/latest_all.dart' as tzdata;
import 'package:timezone/timezone.dart' as tz;

/// Pengingat absen di HP (tanpa server push): dijadwalkan dari jadwal kerja karyawan
/// untuk 7 hari ke depan, dan dijadwalkan ulang setiap kali aplikasi membuka beranda.
/// Pengingat yang tidak relevan lagi (sudah absen) otomatis dilewati.
class Reminders {
  Reminders._();

  static final _plugin = FlutterLocalNotificationsPlugin();
  static bool _ready = false;

  static const _channel = AndroidNotificationDetails(
    'absen_reminder',
    'Pengingat absen',
    channelDescription: 'Pengingat absen masuk dan pulang sesuai jadwal kerja',
    importance: Importance.high,
    priority: Priority.high,
  );

  static Future<void> init() async {
    if (_ready) return;
    tzdata.initializeTimeZones();
    try {
      final info = await FlutterTimezone.getLocalTimezone();
      tz.setLocalLocation(tz.getLocation(info.identifier));
    } catch (_) {
      tz.setLocalLocation(tz.getLocation('Asia/Jakarta'));
    }
    await _plugin.initialize(
      settings: const InitializationSettings(
        android: AndroidInitializationSettings('@mipmap/ic_launcher'),
      ),
    );
    _ready = true;
  }

  static Future<void> requestPermission() async {
    await init();
    await _plugin
        .resolvePlatformSpecificImplementation<
          AndroidFlutterLocalNotificationsPlugin
        >()
        ?.requestNotificationsPermission();
  }

  /// [weekSchedule]: daftar jadwal dari API (`work_days` 1=Senin..7=Minggu, `shift_start`, `shift_end`).
  static Future<void> schedule({
    required List<dynamic> weekSchedule,
    required bool clockedInToday,
    required bool clockedOutToday,
    required bool enabled,
  }) async {
    await init();
    await _plugin.cancelAll();
    if (!enabled || weekSchedule.isEmpty) return;

    final now = tz.TZDateTime.now(tz.local);
    var id = 1;

    for (var offset = 0; offset < 7; offset++) {
      final day = tz.TZDateTime(
        tz.local,
        now.year,
        now.month,
        now.day,
      ).add(Duration(days: offset));
      for (final raw in weekSchedule) {
        final schedule = raw as Map<String, dynamic>;
        final workDays = (schedule['work_days'] as List)
            .map((d) => d as int)
            .toList();
        if (!workDays.contains(day.weekday)) continue;

        final start = _at(
          day,
          schedule['shift_start'] as String,
        ).subtract(const Duration(minutes: 10));
        final end = _at(day, schedule['shift_end'] as String);
        final isToday = offset == 0;

        if (start.isAfter(now) && !(isToday && clockedInToday)) {
          await _zoned(
            id++,
            start,
            'Waktunya absen masuk',
            'Shift ${schedule['name']} mulai ${schedule['shift_start']}. Jangan lupa absen ya.',
          );
        }
        if (end.isAfter(now) && !(isToday && clockedOutToday)) {
          await _zoned(
            id++,
            end,
            'Jangan lupa absen pulang',
            'Shift ${schedule['name']} selesai ${schedule['shift_end']}.',
          );
        }
      }
    }
  }

  static Future<void> cancelAll() async {
    await init();
    await _plugin.cancelAll();
  }

  static tz.TZDateTime _at(tz.TZDateTime day, String hhmm) {
    final parts = hhmm.split(':');
    return tz.TZDateTime(
      tz.local,
      day.year,
      day.month,
      day.day,
      int.parse(parts[0]),
      int.parse(parts[1]),
    );
  }

  static Future<void> _zoned(
    int id,
    tz.TZDateTime when,
    String title,
    String body,
  ) => _plugin.zonedSchedule(
    id: id,
    scheduledDate: when,
    notificationDetails: const NotificationDetails(android: _channel),
    androidScheduleMode: AndroidScheduleMode.inexactAllowWhileIdle,
    title: title,
    body: body,
  );
}
