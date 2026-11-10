import 'package:absensi/widgets/common.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('format durasi jam desimal menjadi jam & menit', () {
    expect(formatDuration(7.17), '7 j 10 m');
    expect(formatDuration(0.08), '5 m');
    expect(formatDuration(8), '8 j');
    expect(formatDuration(null), '—');
  });

  test('label status absen dalam bahasa Indonesia', () {
    expect(StatusInfo.of('absent').label, 'Alpa');
    expect(StatusInfo.of('late').label, 'Terlambat');
    expect(StatusInfo.of('paid').label, 'Dibayar');
  });
}
