import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:image/image.dart' as img;
import 'package:path_provider/path_provider.dart';

import '../api.dart';
import '../config.dart';
import '../theme.dart';

/// Foto profil karyawan (dimuat dengan token login) atau inisial nama bila belum ada.
class EmployeeAvatar extends StatelessWidget {
  const EmployeeAvatar({
    super.key,
    required this.name,
    this.photoUrl,
    this.size = 48,
    this.radius = 16,
    this.onLight = false,
  });

  final String name;
  final String? photoUrl;
  final double size;
  final double radius;

  /// true = latar terang (inisial putih di atas gradien), false = di atas header gradien.
  final bool onLight;

  String get _initials => name
      .trim()
      .split(RegExp(r'\s+'))
      .where((p) => p.isNotEmpty)
      .take(2)
      .map((p) => p.characters.first.toUpperCase())
      .join();

  @override
  Widget build(BuildContext context) {
    final fallback = Container(
      decoration: BoxDecoration(
        gradient: onLight ? Brand.heroGradient : null,
        color: onLight ? null : Colors.white,
      ),
      alignment: Alignment.center,
      child: Text(
        _initials,
        style: TextStyle(
          color: onLight ? Colors.white : Brand.violet,
          fontWeight: FontWeight.w800,
          fontSize: size * 0.36,
        ),
      ),
    );

    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(radius),
        boxShadow: [
          BoxShadow(
            color: Brand.violetDeep.withValues(alpha: 0.22),
            blurRadius: 12,
            offset: const Offset(0, 6),
          ),
        ],
      ),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(radius),
        child: photoUrl == null
            ? fallback
            : Image.network(
                '$apiBase$photoUrl',
                headers: Api.instance.authHeaders,
                fit: BoxFit.cover,
                gaplessPlayback: true,
                errorBuilder: (_, _, _) => fallback,
                loadingBuilder: (context, child, progress) =>
                    progress == null ? child : fallback,
              ),
      ),
    );
  }
}

/// Perkecil & potong persegi foto profil (512 px) sebelum diunggah.
Future<File> prepareProfilePhoto(String path) async {
  final bytes = await File(path).readAsBytes();
  final output = await compute(_squareJpeg, bytes);
  final dir = await getTemporaryDirectory();
  final file = File(
    '${dir.path}/profil_${DateTime.now().millisecondsSinceEpoch}.jpg',
  );
  await file.writeAsBytes(output, flush: true);
  return file;
}

Uint8List _squareJpeg(Uint8List bytes) {
  final decoded = img.decodeImage(bytes);
  if (decoded == null) return bytes;
  final oriented = img.bakeOrientation(decoded);
  final square = img.copyResizeCropSquare(oriented, size: 512);
  return Uint8List.fromList(img.encodeJpg(square, quality: 85));
}
