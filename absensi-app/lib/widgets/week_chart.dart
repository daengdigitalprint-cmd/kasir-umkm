import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../theme.dart';
import 'common.dart';

/// Grafik batang jam kerja 7 hari terakhir; warna batang mengikuti status hari itu.
class WeekChart extends StatelessWidget {
  const WeekChart({super.key, required this.days, required this.today});

  /// Data harian dari API riwayat (`work_date`, `status`, `hours_worked`).
  final List<Map<String, dynamic>> days;
  final DateTime today;

  @override
  Widget build(BuildContext context) {
    final byDate = {for (final d in days) d['work_date'] as String: d};
    final range = List.generate(
      7,
      (i) => DateTime(
        today.year,
        today.month,
        today.day,
      ).subtract(Duration(days: 6 - i)),
    );
    final bars = range.map((date) {
      final row = byDate[DateFormat('yyyy-MM-dd').format(date)];
      return (
        date: date,
        status: row?['status'] as String?,
        hours: (row?['hours_worked'] as num?)?.toDouble() ?? 0,
      );
    }).toList();

    final maxHours = bars.fold<double>(8, (m, b) => b.hours > m ? b.hours : m);
    final total = bars.fold<double>(0, (sum, b) => sum + b.hours);
    final workedDays = bars.where((b) => b.hours > 0).length;

    return Container(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 12),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(22),
        boxShadow: Brand.softShadow(),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text(
                    'Jam kerja 7 hari',
                    style: TextStyle(
                      color: Brand.muted,
                      fontSize: 12.5,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  Text(
                    formatDuration(total),
                    style: const TextStyle(
                      fontSize: 22,
                      fontWeight: FontWeight.w800,
                      color: Brand.ink,
                    ),
                  ),
                ],
              ),
              const Spacer(),
              Container(
                padding: const EdgeInsets.symmetric(
                  horizontal: 10,
                  vertical: 6,
                ),
                decoration: BoxDecoration(
                  color: Brand.violet.withValues(alpha: 0.08),
                  borderRadius: BorderRadius.circular(99),
                ),
                child: Text(
                  workedDays == 0
                      ? 'Belum ada'
                      : 'Rata-rata ${formatDuration(total / workedDays)}/hari',
                  style: const TextStyle(
                    color: Brand.violet,
                    fontSize: 12,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 16),
          SizedBox(
            height: 132,
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                for (final bar in bars)
                  Expanded(
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.end,
                      children: [
                        if (bar.hours > 0)
                          Text(
                            bar.hours.toStringAsFixed(
                              bar.hours % 1 == 0 ? 0 : 1,
                            ),
                            style: const TextStyle(
                              fontSize: 10.5,
                              color: Brand.muted,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                        const SizedBox(height: 4),
                        TweenAnimationBuilder<double>(
                          tween: Tween(
                            begin: 0,
                            end: bar.hours > 0
                                ? bar.hours / maxHours
                                : (bar.status == 'absent' ? 0.06 : 0.03),
                          ),
                          duration: const Duration(milliseconds: 700),
                          curve: Curves.easeOutCubic,
                          builder: (context, value, _) => Container(
                            width: 22,
                            height: 92 * value,
                            decoration: BoxDecoration(
                              borderRadius: BorderRadius.circular(8),
                              gradient: bar.status == null
                                  ? null
                                  : LinearGradient(
                                      begin: Alignment.bottomCenter,
                                      end: Alignment.topCenter,
                                      colors: [
                                        StatusInfo.of(bar.status).color,
                                        StatusInfo.of(bar.status).color
                                            .withValues(alpha: 0.6),
                                      ],
                                    ),
                              color: bar.status == null
                                  ? const Color(0xFFE9EBF2)
                                  : null,
                            ),
                          ),
                        ),
                        const SizedBox(height: 8),
                        Text(
                          DateFormat('E', 'id_ID').format(bar.date),
                          style: TextStyle(
                            fontSize: 11.5,
                            fontWeight: _isToday(bar.date)
                                ? FontWeight.w800
                                : FontWeight.w500,
                            color: _isToday(bar.date)
                                ? Brand.violet
                                : Brand.muted,
                          ),
                        ),
                      ],
                    ),
                  ),
              ],
            ),
          ),
          const SizedBox(height: 10),
          Wrap(
            spacing: 12,
            children: [
              for (final s in ['present', 'late', 'absent'])
                Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Container(
                      width: 8,
                      height: 8,
                      decoration: BoxDecoration(
                        color: StatusInfo.of(s).color,
                        shape: BoxShape.circle,
                      ),
                    ),
                    const SizedBox(width: 4),
                    Text(
                      StatusInfo.of(s).label,
                      style: const TextStyle(fontSize: 11, color: Brand.muted),
                    ),
                  ],
                ),
            ],
          ),
        ],
      ),
    );
  }

  bool _isToday(DateTime d) =>
      d.year == today.year && d.month == today.month && d.day == today.day;
}
