import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:image/image.dart' as img;
import 'package:path_provider/path_provider.dart';

/// Tempel jam, tanggal, nama, dan lokasi ke foto selfie agar foto lama tidak bisa dipakai ulang.
class Watermark {
  static Future<File> apply(String sourcePath, List<String> lines) async {
    final bytes = await File(sourcePath).readAsBytes();
    final output = await compute(_render, _Job(bytes, lines));
    final dir = await getTemporaryDirectory();
    final file = File(
      '${dir.path}/selfie_${DateTime.now().millisecondsSinceEpoch}.jpg',
    );
    await file.writeAsBytes(output, flush: true);
    return file;
  }

  static Uint8List _render(_Job job) {
    var image = img.decodeImage(job.bytes);
    if (image == null) return job.bytes;

    image = img.bakeOrientation(image);
    if (image.width > 900) {
      image = img.copyResize(image, width: 900);
    }

    const lineHeight = 30;
    const padding = 16;
    final boxHeight = padding * 2 + lineHeight * job.lines.length;
    img.fillRect(
      image,
      x1: 0,
      y1: image.height - boxHeight,
      x2: image.width,
      y2: image.height,
      color: img.ColorRgba8(13, 23, 56, 170),
    );
    for (var i = 0; i < job.lines.length; i++) {
      img.drawString(
        image,
        job.lines[i],
        font: img.arial24,
        x: padding,
        y: image.height - boxHeight + padding + i * lineHeight,
        color: i == 0
            ? img.ColorRgb8(160, 234, 113)
            : img.ColorRgb8(255, 255, 255),
      );
    }

    return Uint8List.fromList(img.encodeJpg(image, quality: 82));
  }
}

class _Job {
  _Job(this.bytes, this.lines);

  final Uint8List bytes;
  final List<String> lines;
}
