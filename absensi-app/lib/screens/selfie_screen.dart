import 'dart:io';

import 'package:camera/camera.dart';
import 'package:flutter/material.dart';

import '../services/watermark.dart';
import '../theme.dart';

/// Kamera depan layar penuh dengan bingkai wajah. Hasilnya diberi watermark lalu dikembalikan.
class SelfieScreen extends StatefulWidget {
  const SelfieScreen({super.key, required this.title, required this.watermark});

  final String title;
  final List<String> watermark;

  @override
  State<SelfieScreen> createState() => _SelfieScreenState();
}

class _SelfieScreenState extends State<SelfieScreen>
    with WidgetsBindingObserver {
  CameraController? _controller;
  String? _error;
  bool _processing = false;
  File? _result;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _start();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _controller?.dispose();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    final controller = _controller;
    if (controller == null || !controller.value.isInitialized) return;
    if (state == AppLifecycleState.inactive) {
      controller.dispose();
      _controller = null;
    } else if (state == AppLifecycleState.resumed && _result == null) {
      _start();
    }
  }

  Future<void> _start() async {
    try {
      final cameras = await availableCameras();
      if (cameras.isEmpty) {
        throw CameraException('none', 'Kamera tidak ditemukan');
      }
      final front = cameras.firstWhere(
        (c) => c.lensDirection == CameraLensDirection.front,
        orElse: () => cameras.first,
      );
      final controller = CameraController(
        front,
        ResolutionPreset.high,
        enableAudio: false,
        imageFormatGroup: ImageFormatGroup.jpeg,
      );
      await controller.initialize();
      if (!mounted) {
        await controller.dispose();
        return;
      }
      setState(() {
        _controller = controller;
        _error = null;
      });
    } on CameraException catch (e) {
      setState(
        () => _error =
            e.code == 'CameraAccessDenied' ||
                e.code == 'CameraAccessDeniedWithoutPrompt'
            ? 'Izin kamera ditolak. Buka Pengaturan aplikasi dan izinkan Kamera.'
            : 'Kamera tidak bisa dibuka (${e.description ?? e.code}).',
      );
    }
  }

  Future<void> _capture() async {
    final controller = _controller;
    if (controller == null || _processing) return;
    setState(() => _processing = true);
    try {
      final shot = await controller.takePicture();
      final file = await Watermark.apply(shot.path, widget.watermark);
      setState(() => _result = file);
    } catch (e) {
      setState(() => _error = 'Gagal mengambil foto. Coba lagi.');
    } finally {
      if (mounted) setState(() => _processing = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.black,
      appBar: AppBar(
        backgroundColor: Colors.black,
        foregroundColor: Colors.white,
        title: Text(
          widget.title,
          style: const TextStyle(color: Colors.white, fontSize: 18),
        ),
      ),
      body: _error != null
          ? Center(
              child: Padding(
                padding: const EdgeInsets.all(32),
                child: Text(
                  _error!,
                  textAlign: TextAlign.center,
                  style: const TextStyle(color: Colors.white),
                ),
              ),
            )
          : _result != null
          ? _Review(
              file: _result!,
              onRetake: () => setState(() => _result = null),
              onUse: () => Navigator.pop(context, _result),
            )
          : _controller == null
          ? const Center(child: CircularProgressIndicator(color: Colors.white))
          : Stack(
              fit: StackFit.expand,
              children: [
                Center(child: CameraPreview(_controller!)),
                const CustomPaint(painter: _FaceGuidePainter()),
                Positioned(
                  left: 0,
                  right: 0,
                  top: 24,
                  child: Text(
                    'Posisikan wajah di dalam bingkai',
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      color: Colors.white.withValues(alpha: 0.9),
                      fontSize: 16,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ),
                Positioned(
                  left: 0,
                  right: 0,
                  bottom: 36,
                  child: Center(
                    child: GestureDetector(
                      onTap: _capture,
                      child: Container(
                        width: 78,
                        height: 78,
                        decoration: BoxDecoration(
                          shape: BoxShape.circle,
                          border: Border.all(color: Colors.white, width: 5),
                        ),
                        padding: const EdgeInsets.all(5),
                        child: _processing
                            ? const CircularProgressIndicator(
                                color: Colors.white,
                              )
                            : const DecoratedBox(
                                decoration: BoxDecoration(
                                  shape: BoxShape.circle,
                                  color: Colors.white,
                                ),
                              ),
                      ),
                    ),
                  ),
                ),
              ],
            ),
    );
  }
}

class _Review extends StatelessWidget {
  const _Review({
    required this.file,
    required this.onRetake,
    required this.onUse,
  });

  final File file;
  final VoidCallback onRetake;
  final VoidCallback onUse;

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Expanded(child: Center(child: Image.file(file))),
        Padding(
          padding: const EdgeInsets.fromLTRB(20, 12, 20, 28),
          child: Row(
            children: [
              Expanded(
                child: OutlinedButton.icon(
                  style: OutlinedButton.styleFrom(
                    minimumSize: const Size.fromHeight(52),
                    foregroundColor: Colors.white,
                    side: const BorderSide(color: Colors.white54),
                  ),
                  onPressed: onRetake,
                  icon: const Icon(Icons.refresh_rounded),
                  label: const Text('Ulangi'),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: FilledButton.icon(
                  style: FilledButton.styleFrom(
                    backgroundColor: Brand.lime,
                    foregroundColor: Brand.ink,
                  ),
                  onPressed: onUse,
                  icon: const Icon(Icons.check_rounded),
                  label: const Text('Pakai foto'),
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

/// Bingkai oval di tengah sebagai panduan posisi wajah.
class _FaceGuidePainter extends CustomPainter {
  const _FaceGuidePainter();

  @override
  void paint(Canvas canvas, Size size) {
    final oval = Rect.fromCenter(
      center: Offset(size.width / 2, size.height * 0.42),
      width: size.width * 0.66,
      height: size.height * 0.48,
    );
    final overlay = Path()
      ..addRect(Offset.zero & size)
      ..addOval(oval)
      ..fillType = PathFillType.evenOdd;
    canvas.drawPath(
      overlay,
      Paint()..color = Colors.black.withValues(alpha: 0.45),
    );
    canvas.drawOval(
      oval,
      Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = 3
        ..color = Brand.lime,
    );
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}
