import 'package:flutter/material.dart';

/// Tombol geser untuk konfirmasi (mis. "Swipe to Check In").
/// Aksi hanya terpicu bila tombol digeser hampir sampai ujung, sehingga tidak
/// mudah terpencet tanpa sengaja.
class SwipeButton extends StatefulWidget {
  const SwipeButton({
    super.key,
    required this.label,
    required this.icon,
    required this.colors,
    required this.onSwiped,
    this.busy = false,
    this.enabled = true,
  });

  final String label;
  final IconData icon;
  final List<Color> colors;
  final Future<void> Function() onSwiped;
  final bool busy;
  final bool enabled;

  @override
  State<SwipeButton> createState() => _SwipeButtonState();
}

class _SwipeButtonState extends State<SwipeButton>
    with SingleTickerProviderStateMixin {
  static const _height = 64.0;
  static const _padding = 5.0;
  static const _knob = _height - _padding * 2;

  double _drag = 0;
  double _max = 1;
  late final AnimationController _reset =
      AnimationController(
        vsync: this,
        duration: const Duration(milliseconds: 260),
      )..addListener(
        () => setState(
          () => _drag = _from * (1 - Curves.easeOut.transform(_reset.value)),
        ),
      );
  double _from = 0;

  @override
  void dispose() {
    _reset.dispose();
    super.dispose();
  }

  void _snapBack() {
    _from = _drag;
    _reset.forward(from: 0);
  }

  Future<void> _end() async {
    if (_drag >= _max * 0.85) {
      setState(() => _drag = _max);
      await widget.onSwiped();
    }
    if (mounted) _snapBack();
  }

  @override
  Widget build(BuildContext context) {
    final active = widget.enabled && !widget.busy;

    return LayoutBuilder(
      builder: (context, constraints) {
        _max = constraints.maxWidth - _knob - _padding * 2;
        final progress = (_drag / _max).clamp(0.0, 1.0);

        return Container(
          height: _height,
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(_height / 2),
            gradient: active ? LinearGradient(colors: widget.colors) : null,
            color: active ? null : const Color(0xFFD5D8E3),
            boxShadow: active
                ? [
                    BoxShadow(
                      color: widget.colors.last.withValues(alpha: 0.35),
                      blurRadius: 18,
                      offset: const Offset(0, 8),
                    ),
                  ]
                : null,
          ),
          child: Stack(
            alignment: Alignment.centerLeft,
            children: [
              // Label + panah petunjuk, memudar saat digeser
              Center(
                child: Opacity(
                  opacity: (1 - progress * 1.4).clamp(0.0, 1.0),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      const SizedBox(width: _knob * 0.6),
                      Text(
                        widget.busy ? 'Memproses...' : widget.label,
                        style: const TextStyle(
                          color: Colors.white,
                          fontSize: 16,
                          fontWeight: FontWeight.w800,
                          letterSpacing: 0.2,
                        ),
                      ),
                      const SizedBox(width: 8),
                      if (!widget.busy)
                        Icon(
                          Icons.keyboard_double_arrow_right_rounded,
                          color: Colors.white.withValues(alpha: 0.75),
                        ),
                    ],
                  ),
                ),
              ),
              Positioned(
                left: _padding + _drag,
                child: GestureDetector(
                  onHorizontalDragUpdate: active
                      ? (d) => setState(
                          () => _drag = (_drag + d.delta.dx).clamp(0.0, _max),
                        )
                      : null,
                  onHorizontalDragEnd: active ? (_) => _end() : null,
                  child: Container(
                    width: _knob,
                    height: _knob,
                    decoration: const BoxDecoration(
                      color: Colors.white,
                      shape: BoxShape.circle,
                    ),
                    child: widget.busy
                        ? Padding(
                            padding: const EdgeInsets.all(15),
                            child: CircularProgressIndicator(
                              strokeWidth: 3,
                              color: widget.colors.last,
                            ),
                          )
                        : Icon(
                            widget.icon,
                            color: active
                                ? widget.colors.last
                                : const Color(0xFF9AA0B4),
                            size: 28,
                          ),
                  ),
                ),
              ),
            ],
          ),
        );
      },
    );
  }
}
