import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:image_picker/image_picker.dart';

import '../api.dart';
import '../config.dart';
import '../main.dart';
import '../services/reminders.dart';
import '../theme.dart';
import '../widgets/avatar.dart';
import '../widgets/common.dart';
import 'shell.dart';

/// Profil karyawan: data akun, HP terdaftar, pengingat absen, dan keluar.
class ProfileScreen extends StatefulWidget {
  const ProfileScreen({super.key});

  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  static const _storage = FlutterSecureStorage();

  Map<String, dynamic>? _me;
  String? _error;
  bool _remindersOn = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    _remindersOn = (await _storage.read(key: 'reminders')) != 'off';
    try {
      final me = await Api.instance.get('/attendance/me');
      if (mounted) setState(() => _me = me);
    } on ApiException catch (e) {
      if (e.isUnauthorized) return AbsensiApp.signOut();
      if (mounted) setState(() => _error = e.message);
    }
  }

  Future<void> _toggleReminders(bool value) async {
    setState(() => _remindersOn = value);
    await _storage.write(key: 'reminders', value: value ? 'on' : 'off');
    final me = _me;
    if (me == null) return;
    final attendance = me['attendance'] as Map<String, dynamic>?;
    await Reminders.schedule(
      weekSchedule: me['week_schedule'] as List<dynamic>,
      clockedInToday: attendance?['clock_in'] != null,
      clockedOutToday: attendance?['clock_out'] != null,
      enabled: value,
    );
  }

  bool _uploading = false;

  Future<void> _changePhoto() async {
    final hasPhoto = (_me?['employee']?['photo_url']) != null;
    final choice = await showModalBottomSheet<String>(
      context: context,
      showDragHandle: true,
      builder: (context) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Padding(
              padding: EdgeInsets.only(bottom: 8),
              child: Text(
                'Foto profil',
                style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800),
              ),
            ),
            ListTile(
              leading: const Icon(
                Icons.photo_camera_rounded,
                color: Brand.violet,
              ),
              title: const Text('Ambil foto'),
              onTap: () => Navigator.pop(context, 'camera'),
            ),
            ListTile(
              leading: const Icon(
                Icons.photo_library_rounded,
                color: Brand.violet,
              ),
              title: const Text('Pilih dari galeri'),
              onTap: () => Navigator.pop(context, 'gallery'),
            ),
            if (hasPhoto)
              ListTile(
                leading: const Icon(
                  Icons.delete_outline_rounded,
                  color: Brand.absent,
                ),
                title: const Text(
                  'Hapus foto',
                  style: TextStyle(color: Brand.absent),
                ),
                onTap: () => Navigator.pop(context, 'delete'),
              ),
            const SizedBox(height: 8),
          ],
        ),
      ),
    );
    if (choice == null || !mounted) return;

    setState(() => _uploading = true);
    try {
      if (choice == 'delete') {
        await Api.instance.delete('/attendance/profile/photo');
      } else {
        final picked = await ImagePicker().pickImage(
          source: choice == 'camera' ? ImageSource.camera : ImageSource.gallery,
          preferredCameraDevice: CameraDevice.front,
          maxWidth: 1600,
          imageQuality: 90,
        );
        if (picked == null) return;
        final file = await prepareProfilePhoto(picked.path);
        await Api.instance.postCapture('/attendance/profile/photo', {}, file);
      }
      await _load();
      Shell.profileChanged.value++;
    } on ApiException catch (e) {
      if (mounted) {
        await showMessage(context, 'Gagal menyimpan foto', e.message);
      }
    } catch (_) {
      if (mounted) {
        await showMessage(
          context,
          'Gagal menyimpan foto',
          'Foto tidak bisa dibuka. Coba foto lain.',
        );
      }
    } finally {
      if (mounted) setState(() => _uploading = false);
    }
  }

  Future<void> _logout() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Keluar dari akun?'),
        content: const Text('Pengingat absen di HP ini juga akan dimatikan.'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Batal'),
          ),
          FilledButton(
            style: FilledButton.styleFrom(
              minimumSize: const Size(0, 44),
              backgroundColor: Brand.absent,
            ),
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Keluar'),
          ),
        ],
      ),
    );
    if (ok != true) return;
    await Api.instance
        .post('/auth/logout', {})
        .catchError((_) => <String, dynamic>{});
    await AbsensiApp.signOut();
  }

  @override
  Widget build(BuildContext context) {
    final me = _me;
    final employee = me?['employee'] as Map<String, dynamic>?;
    final store = me?['store'] as Map<String, dynamic>?;
    final device = me?['device'] as Map<String, dynamic>?;
    final schedules = (me?['week_schedule'] as List<dynamic>?) ?? [];
    final name = employee?['name'] as String? ?? '';

    return Scaffold(
      body: RefreshIndicator(
        onRefresh: _load,
        edgeOffset: MediaQuery.of(context).padding.top,
        child: ListView(
          padding: EdgeInsets.zero,
          children: [
            const PageHeader(
              title: 'Profil',
              subtitle: 'Akun & pengaturan aplikasi',
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 18, 20, 24),
              child: _error != null
                  ? ErrorView(message: _error!, onRetry: _load)
                  : me == null
                  ? const Padding(
                      padding: EdgeInsets.all(48),
                      child: Center(child: CircularProgressIndicator()),
                    )
                  : Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        Container(
                          padding: const EdgeInsets.all(18),
                          decoration: BoxDecoration(
                            color: Colors.white,
                            borderRadius: BorderRadius.circular(22),
                            boxShadow: Brand.softShadow(),
                          ),
                          child: Row(
                            children: [
                              GestureDetector(
                                onTap: _uploading ? null : _changePhoto,
                                child: Stack(
                                  clipBehavior: Clip.none,
                                  children: [
                                    EmployeeAvatar(
                                      name: name,
                                      photoUrl:
                                          employee?['photo_url'] as String?,
                                      size: 72,
                                      radius: 24,
                                      onLight: true,
                                    ),
                                    if (_uploading)
                                      const Positioned.fill(
                                        child: DecoratedBox(
                                          decoration: BoxDecoration(
                                            color: Color(0x88000000),
                                            borderRadius: BorderRadius.all(
                                              Radius.circular(24),
                                            ),
                                          ),
                                          child: Center(
                                            child: SizedBox(
                                              width: 24,
                                              height: 24,
                                              child: CircularProgressIndicator(
                                                strokeWidth: 2.5,
                                                color: Colors.white,
                                              ),
                                            ),
                                          ),
                                        ),
                                      ),
                                    Positioned(
                                      right: -4,
                                      bottom: -4,
                                      child: Container(
                                        width: 28,
                                        height: 28,
                                        decoration: BoxDecoration(
                                          color: Brand.violet,
                                          shape: BoxShape.circle,
                                          border: Border.all(
                                            color: Colors.white,
                                            width: 2.5,
                                          ),
                                        ),
                                        child: const Icon(
                                          Icons.photo_camera_rounded,
                                          size: 14,
                                          color: Colors.white,
                                        ),
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                              const SizedBox(width: 14),
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text(
                                      name,
                                      style: const TextStyle(
                                        fontSize: 18,
                                        fontWeight: FontWeight.w800,
                                        color: Brand.ink,
                                      ),
                                    ),
                                    const SizedBox(height: 2),
                                    Text(
                                      '${employee?['position'] ?? 'Karyawan'} · ${store?['name']}',
                                      style: const TextStyle(
                                        color: Brand.muted,
                                        fontSize: 13,
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                            ],
                          ),
                        ),
                        const SizedBox(height: 22),
                        const SectionTitle('Jadwal kerja'),
                        _Group(
                          children: schedules.isEmpty
                              ? [
                                  const _Tile(
                                    icon: Icons.event_busy_outlined,
                                    color: Brand.muted,
                                    title: 'Belum ada jadwal kerja',
                                  ),
                                ]
                              : schedules.map((raw) {
                                  final s = raw as Map<String, dynamic>;
                                  const days = [
                                    'Sen',
                                    'Sel',
                                    'Rab',
                                    'Kam',
                                    'Jum',
                                    'Sab',
                                    'Min',
                                  ];
                                  final workDays = (s['work_days'] as List)
                                      .map((d) => days[(d as int) - 1])
                                      .join(', ');
                                  return _Tile(
                                    icon: Icons.schedule_outlined,
                                    color: Brand.violet,
                                    title:
                                        'Shift ${s['name']} · ${s['shift_start']}–${s['shift_end']}',
                                    subtitle: workDays,
                                  );
                                }).toList(),
                        ),
                        const SizedBox(height: 22),
                        const SectionTitle('Pengaturan'),
                        _Group(
                          children: [
                            _Tile(
                              icon: Icons.smartphone_outlined,
                              color: device?['is_this_device'] == false
                                  ? Brand.absent
                                  : Brand.present,
                              title: 'HP absensi',
                              subtitle: device?['bound'] == true
                                  ? (device?['is_this_device'] == true
                                        ? 'HP ini sudah terdaftar'
                                        : 'Terdaftar di HP lain (${device?['name']})')
                                  : (device?['is_this_device'] == false
                                        ? 'HP ini milik karyawan lain'
                                        : 'HP ini akan terdaftar saat absen pertama'),
                            ),
                            _Tile(
                              icon: Icons.notifications_none_outlined,
                              color: Brand.orange,
                              title: 'Pengingat absen',
                              subtitle:
                                  '10 menit sebelum masuk & saat jam pulang',
                              trailing: Switch(
                                value: _remindersOn,
                                onChanged: _toggleReminders,
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(height: 22),
                        OutlinedButton.icon(
                          style: OutlinedButton.styleFrom(
                            minimumSize: const Size.fromHeight(52),
                            foregroundColor: Brand.absent,
                            side: BorderSide(
                              color: Brand.absent.withValues(alpha: 0.3),
                            ),
                          ),
                          onPressed: _logout,
                          icon: const Icon(Icons.logout_rounded),
                          label: const Text('Keluar'),
                        ),
                        const SizedBox(height: 16),
                        const Text(
                          appName,
                          textAlign: TextAlign.center,
                          style: TextStyle(color: Brand.muted, fontSize: 12),
                        ),
                      ],
                    ),
            ),
          ],
        ),
      ),
    );
  }
}

class _Group extends StatelessWidget {
  const _Group({required this.children});

  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(20),
        boxShadow: Brand.softShadow(),
      ),
      child: Column(
        children: [
          for (var i = 0; i < children.length; i++) ...[
            if (i > 0) const Divider(height: 1, indent: 60),
            children[i],
          ],
        ],
      ),
    );
  }
}

class _Tile extends StatelessWidget {
  const _Tile({
    required this.icon,
    required this.color,
    required this.title,
    this.subtitle,
    this.trailing,
  });

  final IconData icon;
  final Color color;
  final String title;
  final String? subtitle;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    return ListTile(
      contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
      minLeadingWidth: 28,
      leading: Icon(
        icon,
        color: color == Brand.absent ? Brand.absent : Brand.ink,
        size: 22,
      ),
      title: Text(
        title,
        style: const TextStyle(
          fontWeight: FontWeight.w700,
          color: Brand.ink,
          fontSize: 14.5,
        ),
      ),
      subtitle: subtitle == null
          ? null
          : Text(
              subtitle!,
              style: const TextStyle(color: Brand.muted, fontSize: 12.5),
            ),
      trailing: trailing,
    );
  }
}
