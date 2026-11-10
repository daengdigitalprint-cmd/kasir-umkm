import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../api.dart';
import '../main.dart';
import '../theme.dart';
import '../widgets/common.dart';

/// Riwayat absen: kalender bulanan berwarna + rekap + daftar harian.
class HistoryScreen extends StatefulWidget {
  const HistoryScreen({super.key});

  @override
  State<HistoryScreen> createState() => _HistoryScreenState();
}

class _HistoryScreenState extends State<HistoryScreen> {
  DateTime _month = DateTime(DateTime.now().year, DateTime.now().month);
  Map<String, dynamic>? _data;
  String? _error;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final data = await Api.instance.get(
        '/attendance/history',
        query: {'month': DateFormat('yyyy-MM').format(_month)},
      );
      if (mounted) setState(() => _data = data);
    } on ApiException catch (e) {
      if (e.isUnauthorized) return AbsensiApp.signOut();
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  void _shift(int months) {
    setState(() => _month = DateTime(_month.year, _month.month + months));
    _load();
  }

  @override
  Widget build(BuildContext context) {
    final isCurrentMonth =
        _month.year == DateTime.now().year &&
        _month.month == DateTime.now().month;

    return Scaffold(
      body: RefreshIndicator(
        onRefresh: _load,
        edgeOffset: MediaQuery.of(context).padding.top,
        child: ListView(
          padding: EdgeInsets.zero,
          children: [
            PageHeader(
              title: 'Riwayat Absen',
              subtitle: 'Kehadiran dan rekap per bulan',
              bottom: Container(
                decoration: BoxDecoration(
                  color: Colors.white.withValues(alpha: 0.16),
                  borderRadius: BorderRadius.circular(16),
                ),
                child: Row(
                  children: [
                    IconButton(
                      onPressed: () => _shift(-1),
                      icon: const Icon(
                        Icons.chevron_left_rounded,
                        color: Colors.white,
                      ),
                    ),
                    Expanded(
                      child: Text(
                        DateFormat('MMMM yyyy', 'id_ID').format(_month),
                        textAlign: TextAlign.center,
                        style: const TextStyle(
                          fontSize: 16,
                          fontWeight: FontWeight.w800,
                          color: Colors.white,
                        ),
                      ),
                    ),
                    IconButton(
                      onPressed: isCurrentMonth ? null : () => _shift(1),
                      icon: Icon(
                        Icons.chevron_right_rounded,
                        color: isCurrentMonth ? Colors.white38 : Colors.white,
                      ),
                    ),
                  ],
                ),
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 18, 20, 24),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  if (_loading && _data == null)
                    const Padding(
                      padding: EdgeInsets.all(48),
                      child: Center(child: CircularProgressIndicator()),
                    )
                  else if (_error != null)
                    ErrorView(message: _error!, onRetry: _load)
                  else if (_data != null) ...[
                    _Summary(
                      summary: _data!['summary'] as Map<String, dynamic>,
                    ),
                    const SizedBox(height: 14),
                    _Calendar(
                      month: _month,
                      days: _data!['days'] as List<dynamic>,
                      holidays: _data!['holidays'] as List<dynamic>,
                    ),
                    const SizedBox(height: 18),
                    const SectionTitle('Detail harian'),
                    if ((_data!['days'] as List).isEmpty)
                      const EmptyView(
                        icon: Icons.event_busy_rounded,
                        title: 'Belum ada absen bulan ini',
                      )
                    else
                      ...(_data!['days'] as List).reversed.map(
                        (d) => _DayTile(day: d as Map<String, dynamic>),
                      ),
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

class _Summary extends StatelessWidget {
  const _Summary({required this.summary});

  final Map<String, dynamic> summary;

  @override
  Widget build(BuildContext context) {
    final present = summary['present_days'] as int;
    final late = summary['late_days'] as int;
    final items = [
      ('Tepat waktu', present - late, Brand.present),
      ('Telat', late, Brand.late),
      ('Alpa', summary['absent_days'] as int, Brand.absent),
      ('Cuti', summary['leave_days'] as int, Brand.leave),
    ];

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          children: [
            Row(
              children: items
                  .map(
                    (item) => Expanded(
                      child: Column(
                        children: [
                          Text(
                            '${item.$2}',
                            style: TextStyle(
                              fontSize: 24,
                              fontWeight: FontWeight.w800,
                              color: item.$3,
                            ),
                          ),
                          Text(
                            item.$1,
                            style: const TextStyle(
                              fontSize: 12,
                              color: Brand.muted,
                            ),
                          ),
                        ],
                      ),
                    ),
                  )
                  .toList(),
            ),
            const Divider(height: 24),
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceAround,
              children: [
                _Metric(
                  label: 'Jam kerja',
                  value: formatDuration(summary['total_hours'] as num?),
                ),
                _Metric(
                  label: 'Lembur',
                  value: formatDuration(summary['total_overtime'] as num?),
                ),
                _Metric(
                  label: 'Total telat',
                  value: '${summary['total_late_min']} menit',
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _Metric extends StatelessWidget {
  const _Metric({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) => Column(
    children: [
      Text(
        value,
        style: const TextStyle(fontWeight: FontWeight.w700, color: Brand.ink),
      ),
      Text(label, style: const TextStyle(fontSize: 12, color: Brand.muted)),
    ],
  );
}

class _Calendar extends StatelessWidget {
  const _Calendar({
    required this.month,
    required this.days,
    required this.holidays,
  });

  final DateTime month;
  final List<dynamic> days;
  final List<dynamic> holidays;

  @override
  Widget build(BuildContext context) {
    final byDate = {
      for (final d in days)
        (d as Map<String, dynamic>)['work_date'] as String:
            d['status'] as String,
    };
    final holidayDates = {
      for (final h in holidays) (h as Map<String, dynamic>)['date'] as String,
    };
    final first = DateTime(month.year, month.month);
    final daysInMonth = DateTime(month.year, month.month + 1, 0).day;
    final leading = first.weekday - 1; // Senin di kolom pertama
    final today = DateTime.now();

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          children: [
            Row(
              children: ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min']
                  .map(
                    (d) => Expanded(
                      child: Text(
                        d,
                        textAlign: TextAlign.center,
                        style: const TextStyle(
                          fontSize: 12,
                          color: Brand.muted,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    ),
                  )
                  .toList(),
            ),
            const SizedBox(height: 8),
            GridView.count(
              crossAxisCount: 7,
              shrinkWrap: true,
              physics: const NeverScrollableScrollPhysics(),
              mainAxisSpacing: 6,
              crossAxisSpacing: 6,
              children: [
                for (var i = 0; i < leading; i++) const SizedBox.shrink(),
                for (var day = 1; day <= daysInMonth; day++)
                  Builder(
                    builder: (context) {
                      final date = DateFormat('yyyy-MM-dd')
                          .format(DateTime(month.year, month.month, day));
                      final status = byDate[date];
                      final isHoliday = holidayDates.contains(date);
                      final color = status != null
                          ? StatusInfo.of(status).color
                          : null;
                      final isToday =
                          today.year == month.year &&
                          today.month == month.month &&
                          today.day == day;
                      return Container(
                        decoration: BoxDecoration(
                          color:
                              color?.withValues(alpha: 0.14) ??
                              (isHoliday ? const Color(0xFFF1F2F6) : null),
                          borderRadius: BorderRadius.circular(10),
                          border: isToday
                              ? Border.all(color: Brand.violet, width: 2)
                              : null,
                        ),
                        alignment: Alignment.center,
                        child: Text(
                          '$day',
                          style: TextStyle(
                            fontWeight: status != null || isToday
                                ? FontWeight.w800
                                : FontWeight.w500,
                            color:
                                color ?? (isHoliday ? Brand.absent : Brand.ink),
                          ),
                        ),
                      );
                    },
                  ),
              ],
            ),
            const SizedBox(height: 10),
            Wrap(
              spacing: 12,
              runSpacing: 6,
              alignment: WrapAlignment.center,
              children: [
                for (final s in ['present', 'late', 'absent', 'leave'])
                  Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Container(
                        width: 10,
                        height: 10,
                        decoration: BoxDecoration(
                          color: StatusInfo.of(s).color,
                          shape: BoxShape.circle,
                        ),
                      ),
                      const SizedBox(width: 4),
                      Text(
                        StatusInfo.of(s).label,
                        style: const TextStyle(
                          fontSize: 12,
                          color: Brand.muted,
                        ),
                      ),
                    ],
                  ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _DayTile extends StatelessWidget {
  const _DayTile({required this.day});

  final Map<String, dynamic> day;

  @override
  Widget build(BuildContext context) {
    final clockIn = day['clock_in'] as String?;
    final clockOut = day['clock_out'] as String?;
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Card(
        child: ListTile(
          title: Text(
            formatDate(day['work_date'] as String),
            style: const TextStyle(fontWeight: FontWeight.w700),
          ),
          subtitle: Text(
            clockIn == null
                ? (day['notes'] as String? ?? 'Tidak ada absen')
                : 'Masuk $clockIn · Pulang ${clockOut ?? '--:--'}${(day['late_minutes'] as int) > 0 ? ' · telat ${day['late_minutes']} m' : ''}',
          ),
          trailing: StatusChip(day['status'] as String?),
        ),
      ),
    );
  }
}
