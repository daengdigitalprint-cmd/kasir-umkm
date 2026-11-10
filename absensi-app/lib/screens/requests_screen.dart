import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../api.dart';
import '../main.dart';
import '../theme.dart';
import '../widgets/common.dart';

/// Pengajuan cuti & lembur milik karyawan.
class RequestsScreen extends StatefulWidget {
  const RequestsScreen({super.key});

  @override
  State<RequestsScreen> createState() => _RequestsScreenState();
}

class _RequestsScreenState extends State<RequestsScreen> {
  int _tab = 0;
  Map<String, dynamic>? _leaves;
  Map<String, dynamic>? _overtimes;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final results = await Future.wait([
        Api.instance.get('/attendance/leaves'),
        Api.instance.get('/attendance/overtimes'),
      ]);
      if (mounted) {
        setState(() {
          _leaves = results[0];
          _overtimes = results[1];
        });
      }
    } on ApiException catch (e) {
      if (e.isUnauthorized) return AbsensiApp.signOut();
      if (mounted) setState(() => _error = e.message);
    }
  }

  Future<void> _openForm() async {
    final sent = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (_) => _tab == 0
          ? _LeaveForm(
              types: (_leaves?['types'] as List<dynamic>? ?? [])
                  .cast<Map<String, dynamic>>(),
            )
          : const _OvertimeForm(),
    );
    if (sent == true) await _load();
  }

  @override
  Widget build(BuildContext context) {
    final items =
        ((_tab == 0 ? _leaves : _overtimes)?['items'] as List<dynamic>?) ?? [];

    return Scaffold(
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _leaves == null ? null : _openForm,
        icon: const Icon(Icons.add_rounded),
        label: Text(_tab == 0 ? 'Ajukan cuti' : 'Ajukan lembur'),
      ),
      body: RefreshIndicator(
        onRefresh: _load,
        edgeOffset: MediaQuery.of(context).padding.top,
        child: ListView(
          padding: EdgeInsets.zero,
          children: [
            PageHeader(
              title: 'Pengajuan',
              subtitle: 'Cuti, izin, dan lembur',
              bottom: Container(
                padding: const EdgeInsets.all(4),
                decoration: BoxDecoration(
                  color: Colors.white.withValues(alpha: 0.16),
                  borderRadius: BorderRadius.circular(16),
                ),
                child: Row(
                  children: [
                    for (final (index, icon, label) in [
                      (0, Icons.beach_access_rounded, 'Cuti / Izin'),
                      (1, Icons.more_time_rounded, 'Lembur'),
                    ])
                      Expanded(
                        child: GestureDetector(
                          onTap: () => setState(() => _tab = index),
                          child: AnimatedContainer(
                            duration: const Duration(milliseconds: 200),
                            padding: const EdgeInsets.symmetric(vertical: 10),
                            decoration: BoxDecoration(
                              color: _tab == index
                                  ? Colors.white
                                  : Colors.transparent,
                              borderRadius: BorderRadius.circular(12),
                            ),
                            child: Row(
                              mainAxisAlignment: MainAxisAlignment.center,
                              children: [
                                Icon(
                                  icon,
                                  size: 18,
                                  color: _tab == index
                                      ? Brand.violet
                                      : Colors.white,
                                ),
                                const SizedBox(width: 6),
                                Text(
                                  label,
                                  style: TextStyle(
                                    fontWeight: FontWeight.w700,
                                    color: _tab == index
                                        ? Brand.violet
                                        : Colors.white,
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ),
                      ),
                  ],
                ),
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 18, 20, 96),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  if (_error != null)
                    ErrorView(message: _error!, onRetry: _load)
                  else if (_leaves == null)
                    const Padding(
                      padding: EdgeInsets.all(48),
                      child: Center(child: CircularProgressIndicator()),
                    )
                  else if (items.isEmpty)
                    EmptyView(
                      icon: _tab == 0
                          ? Icons.beach_access_outlined
                          : Icons.more_time_rounded,
                      title: _tab == 0
                          ? 'Belum ada pengajuan cuti'
                          : 'Belum ada pengajuan lembur',
                      subtitle: _tab == 0
                          ? 'Cuti yang disetujui tidak dihitung alpa.'
                          : 'Hanya lembur yang disetujui yang dibayar.',
                    )
                  else
                    ...items.map((raw) {
                      final item = raw as Map<String, dynamic>;
                      final title = _tab == 0
                          ? item['type_label'] as String
                          : '${formatDate(item['work_date'] as String)} · ${formatDuration(item['hours'] as num)}';
                      final subtitle = _tab == 0
                          ? '${formatDate(item['start_date'] as String, 'd MMM')} – ${formatDate(item['end_date'] as String, 'd MMM yyyy')}'
                          : (item['reason'] as String? ?? 'Tanpa keterangan');
                      return Padding(
                        padding: const EdgeInsets.only(bottom: 10),
                        child: Container(
                          padding: const EdgeInsets.all(14),
                          decoration: BoxDecoration(
                            color: Colors.white,
                            borderRadius: BorderRadius.circular(18),
                            boxShadow: Brand.softShadow(),
                          ),
                          child: Row(
                            children: [
                              Container(
                                width: 44,
                                height: 44,
                                decoration: BoxDecoration(
                                  color:
                                      (_tab == 0 ? Brand.leave : Brand.orange)
                                          .withValues(alpha: 0.12),
                                  borderRadius: BorderRadius.circular(14),
                                ),
                                child: Icon(
                                  _tab == 0
                                      ? Icons.beach_access_rounded
                                      : Icons.more_time_rounded,
                                  color: _tab == 0 ? Brand.leave : Brand.orange,
                                ),
                              ),
                              const SizedBox(width: 12),
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text(
                                      title,
                                      style: const TextStyle(
                                        fontWeight: FontWeight.w700,
                                        color: Brand.ink,
                                      ),
                                    ),
                                    const SizedBox(height: 2),
                                    Text(
                                      [
                                        subtitle,
                                        if (item['reviewed_by'] != null)
                                          'oleh ${item['reviewed_by']}',
                                      ].join(' · '),
                                      style: const TextStyle(
                                        color: Brand.muted,
                                        fontSize: 12.5,
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                              StatusChip(item['status'] as String?),
                            ],
                          ),
                        ),
                      );
                    }),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _LeaveForm extends StatefulWidget {
  const _LeaveForm({required this.types});

  final List<Map<String, dynamic>> types;

  @override
  State<_LeaveForm> createState() => _LeaveFormState();
}

class _LeaveFormState extends State<_LeaveForm> {
  String _type = 'annual';
  DateTimeRange? _range;
  final _reason = TextEditingController();
  bool _sending = false;

  Future<void> _send() async {
    if (_range == null) {
      return showMessage(
        context,
        'Tanggal belum dipilih',
        'Pilih tanggal mulai dan selesai cuti.',
      );
    }
    setState(() => _sending = true);
    try {
      await Api.instance.post('/attendance/leaves', {
        'type': _type,
        'start_date': DateFormat('yyyy-MM-dd').format(_range!.start),
        'end_date': DateFormat('yyyy-MM-dd').format(_range!.end),
        'reason': _reason.text.trim(),
      });
      if (mounted) Navigator.pop(context, true);
    } on ApiException catch (e) {
      if (mounted) await showMessage(context, 'Pengajuan gagal', e.message);
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.fromLTRB(
        20,
        0,
        20,
        MediaQuery.of(context).viewInsets.bottom + 24,
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const Text(
            'Ajukan cuti / izin',
            style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800),
          ),
          const SizedBox(height: 16),
          DropdownButtonFormField<String>(
            initialValue: _type,
            decoration: const InputDecoration(labelText: 'Jenis'),
            items: widget.types
                .map(
                  (t) => DropdownMenuItem(
                    value: t['value'] as String,
                    child: Text(t['label'] as String),
                  ),
                )
                .toList(),
            onChanged: (v) => setState(() => _type = v ?? 'annual'),
          ),
          const SizedBox(height: 12),
          OutlinedButton.icon(
            style: OutlinedButton.styleFrom(
              minimumSize: const Size.fromHeight(52),
              alignment: Alignment.centerLeft,
            ),
            onPressed: () async {
              final picked = await showDateRangePicker(
                context: context,
                firstDate: DateTime.now().subtract(const Duration(days: 30)),
                lastDate: DateTime.now().add(const Duration(days: 365)),
                initialDateRange: _range,
              );
              if (picked != null) setState(() => _range = picked);
            },
            icon: const Icon(Icons.date_range_rounded),
            label: Text(
              _range == null
                  ? 'Pilih tanggal'
                  : '${DateFormat('d MMM', 'id_ID').format(_range!.start)} – ${DateFormat('d MMM yyyy', 'id_ID').format(_range!.end)}',
            ),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _reason,
            decoration: const InputDecoration(labelText: 'Alasan (opsional)'),
            maxLength: 255,
          ),
          FilledButton(
            onPressed: _sending ? null : _send,
            child: Text(_sending ? 'Mengirim...' : 'Kirim pengajuan'),
          ),
        ],
      ),
    );
  }
}

class _OvertimeForm extends StatefulWidget {
  const _OvertimeForm();

  @override
  State<_OvertimeForm> createState() => _OvertimeFormState();
}

class _OvertimeFormState extends State<_OvertimeForm> {
  DateTime _date = DateTime.now();
  double _hours = 1;
  final _reason = TextEditingController();
  bool _sending = false;

  Future<void> _send() async {
    setState(() => _sending = true);
    try {
      await Api.instance.post('/attendance/overtimes', {
        'work_date': DateFormat('yyyy-MM-dd').format(_date),
        'hours': _hours,
        'reason': _reason.text.trim(),
      });
      if (mounted) Navigator.pop(context, true);
    } on ApiException catch (e) {
      if (mounted) await showMessage(context, 'Pengajuan gagal', e.message);
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.fromLTRB(
        20,
        0,
        20,
        MediaQuery.of(context).viewInsets.bottom + 24,
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const Text(
            'Ajukan lembur',
            style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800),
          ),
          const SizedBox(height: 16),
          OutlinedButton.icon(
            style: OutlinedButton.styleFrom(
              minimumSize: const Size.fromHeight(52),
              alignment: Alignment.centerLeft,
            ),
            onPressed: () async {
              final picked = await showDatePicker(
                context: context,
                firstDate: DateTime.now().subtract(const Duration(days: 30)),
                lastDate: DateTime.now(),
                initialDate: _date,
              );
              if (picked != null) setState(() => _date = picked);
            },
            icon: const Icon(Icons.event_rounded),
            label: Text(DateFormat('EEEE, d MMMM yyyy', 'id_ID').format(_date)),
          ),
          const SizedBox(height: 16),
          Text(
            'Lama lembur: ${formatDuration(_hours)}',
            style: const TextStyle(fontWeight: FontWeight.w700),
          ),
          Slider(
            value: _hours,
            min: 0.5,
            max: 8,
            divisions: 15,
            label: formatDuration(_hours),
            onChanged: (v) => setState(() => _hours = v),
          ),
          TextField(
            controller: _reason,
            decoration: const InputDecoration(
              labelText: 'Pekerjaan yang dilakukan',
            ),
            maxLength: 255,
          ),
          FilledButton(
            onPressed: _sending ? null : _send,
            child: Text(_sending ? 'Mengirim...' : 'Kirim pengajuan'),
          ),
        ],
      ),
    );
  }
}
