import 'dart:ui' show ImageFilter;

import 'package:curved_navigation_bar/curved_navigation_bar.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../theme.dart';
import 'history_screen.dart';
import 'home_screen.dart';
import 'payslips_screen.dart';
import 'profile_screen.dart';
import 'requests_screen.dart';

/// Kerangka utama dengan navigasi bawah melengkung:
/// Riwayat · Pengajuan · **Absen** (tengah) · Slip Gaji · Profil.
class Shell extends StatefulWidget {
  const Shell({super.key});

  static const riwayat = 0;
  static const pengajuan = 1;
  static const absen = 2;
  static const slip = 3;
  static const profil = 4;

  /// Tab aktif; bisa diganti dari layar mana pun (mis. menu cepat di beranda).
  static final tab = ValueNotifier<int>(absen);

  /// Bertambah setiap foto profil diganti, agar beranda memuat ulang avatar.
  static final profileChanged = ValueNotifier<int>(0);

  @override
  State<Shell> createState() => _ShellState();
}

class _ShellState extends State<Shell> {
  static const _barHeight = 68.0;
  static const _curve = Curves.easeOutCubic;
  static const _duration = Duration(milliseconds: 380);

  static const _pages = [
    HistoryScreen(),
    RequestsScreen(),
    HomeScreen(),
    PayslipsScreen(),
    ProfileScreen(),
  ];

  @override
  void initState() {
    super.initState();
    Shell.tab.value = Shell.absen;
    Shell.tab.addListener(_onTab);
  }

  @override
  void dispose() {
    Shell.tab.removeListener(_onTab);
    super.dispose();
  }

  void _onTab() => setState(() {});

  Widget _item(int i, IconData icon, IconData activeIcon, String label) {
    final active = i == Shell.tab.value;
    return Icon(
      active ? activeIcon : icon,
      size: i == Shell.absen ? 34 : 24,
      color: active ? Colors.white : Brand.muted,
      semanticLabel: label,
    );
  }

  @override
  Widget build(BuildContext context) {
    final index = Shell.tab.value;

    return AnnotatedRegion<SystemUiOverlayStyle>(
      // Ikon status bar putih di atas header gradien.
      value: SystemUiOverlayStyle.light.copyWith(
        statusBarColor: Colors.transparent,
      ),
      child: Scaffold(
        body: IndexedStack(index: index, children: _pages),
        bottomNavigationBar: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Stack(
              clipBehavior: Clip.none,
              children: [
                // Bayangan: salinan bar yang diburamkan agar mengikuti lengkungan & gelembung.
                Positioned.fill(
                  child: IgnorePointer(
                    child: ExcludeSemantics(
                      child: Transform.translate(
                        offset: const Offset(0, -2),
                        child: ImageFiltered(
                          imageFilter: ImageFilter.blur(sigmaX: 8, sigmaY: 8),
                          child: CurvedNavigationBar(
                            index: index,
                            height: _barHeight,
                            color: const Color(0x1A000000),
                            buttonBackgroundColor: const Color(0x1A000000),
                            backgroundColor: Colors.transparent,
                            animationCurve: _curve,
                            animationDuration: _duration,
                            items: const [
                              SizedBox(),
                              SizedBox(),
                              SizedBox(),
                              SizedBox(),
                              SizedBox(),
                            ],
                            letIndexChange: (_) => false,
                          ),
                        ),
                      ),
                    ),
                  ),
                ),
                CurvedNavigationBar(
                  index: index,
                  height: _barHeight,
                  color: Colors.white,
                  buttonBackgroundColor: Brand.violet,
                  backgroundColor: Colors.transparent,
                  animationCurve: _curve,
                  animationDuration: _duration,
                  items: [
                    _item(
                      Shell.riwayat,
                      Icons.calendar_today_outlined,
                      Icons.calendar_today_outlined,
                      'Riwayat',
                    ),
                    _item(
                      Shell.pengajuan,
                      Icons.description_outlined,
                      Icons.description_outlined,
                      'Pengajuan',
                    ),
                    _item(
                      Shell.absen,
                      Icons.fingerprint_rounded,
                      Icons.fingerprint_rounded,
                      'Absen',
                    ),
                    _item(
                      Shell.slip,
                      Icons.account_balance_wallet_outlined,
                      Icons.account_balance_wallet_outlined,
                      'Slip Gaji',
                    ),
                    _item(
                      Shell.profil,
                      Icons.person_outline_rounded,
                      Icons.person_outline_rounded,
                      'Profil',
                    ),
                  ],
                  onTap: (i) => Shell.tab.value = i,
                ),
              ],
            ),
            // Paket tidak menangani area gestur sistem (edge-to-edge).
            Container(
              height: MediaQuery.viewPaddingOf(context).bottom,
              color: Colors.white,
            ),
          ],
        ),
      ),
    );
  }
}
