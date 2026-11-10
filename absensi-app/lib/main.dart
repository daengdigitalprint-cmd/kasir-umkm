import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:intl/date_symbol_data_local.dart';

import 'config.dart';
import 'screens/login_screen.dart';
import 'screens/shell.dart';
import 'services/reminders.dart';
import 'services/session.dart';
import 'theme.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await initializeDateFormatting('id_ID');
  await Session.instance.load();
  await Reminders.init();
  runApp(const AbsensiApp());
}

class AbsensiApp extends StatelessWidget {
  const AbsensiApp({super.key});

  static final navigatorKey = GlobalKey<NavigatorState>();

  /// Dipanggil saat token tidak berlaku lagi atau pengguna keluar.
  static Future<void> signOut() async {
    await Session.instance.clear();
    await Reminders.cancelAll();
    navigatorKey.currentState?.pushAndRemoveUntil(
      MaterialPageRoute(builder: (_) => const LoginScreen()),
      (_) => false,
    );
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: appName,
      navigatorKey: navigatorKey,
      debugShowCheckedModeBanner: false,
      theme: buildTheme(),
      locale: const Locale('id', 'ID'),
      supportedLocales: const [Locale('id', 'ID'), Locale('en')],
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
      home: Session.instance.token == null
          ? const LoginScreen()
          : const Shell(),
    );
  }
}
