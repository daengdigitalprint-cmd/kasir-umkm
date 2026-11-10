import 'dart:io';

import 'package:flutter/material.dart';
import 'package:open_filex/open_filex.dart';
import 'package:path_provider/path_provider.dart';

import '../api.dart';
import '../main.dart';
import '../theme.dart';
import '../widgets/common.dart';

/// Slip gaji yang sudah disetujui pemilik toko; PDF bisa dibuka & disimpan.
class PayslipsScreen extends StatefulWidget {
  const PayslipsScreen({super.key});

  @override
  State<PayslipsScreen> createState() => _PayslipsScreenState();
}

class _PayslipsScreenState extends State<PayslipsScreen> {
  List<dynamic>? _items;
  String? _error;
  int? _downloading;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final data = await Api.instance.get('/attendance/payslips');
      if (mounted) setState(() => _items = data['items'] as List<dynamic>);
    } on ApiException catch (e) {
      if (e.isUnauthorized) return AbsensiApp.signOut();
      if (mounted) setState(() => _error = e.message);
    }
  }

  Future<void> _open(Map<String, dynamic> slip) async {
    setState(() => _downloading = slip['id'] as int);
    try {
      final bytes = await Api.instance.download(
        '/attendance/payslips/${slip['id']}/pdf',
      );
      final dir = await getApplicationDocumentsDirectory();
      final name = (slip['period'] as String).replaceAll(
        RegExp(r'[^A-Za-z0-9]+'),
        '-',
      );
      final file = File('${dir.path}/slip-$name.pdf');
      await file.writeAsBytes(bytes, flush: true);
      final result = await OpenFilex.open(file.path, type: 'application/pdf');
      if (result.type != ResultType.done && mounted) {
        await showMessage(
          context,
          'Tidak bisa membuka PDF',
          'Pasang aplikasi pembaca PDF terlebih dahulu.',
        );
      }
    } on ApiException catch (e) {
      if (mounted) {
        await showMessage(context, 'Gagal mengunduh slip', e.message);
      }
    } finally {
      if (mounted) setState(() => _downloading = null);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: RefreshIndicator(
        onRefresh: _load,
        edgeOffset: MediaQuery.of(context).padding.top,
        child: ListView(
          padding: EdgeInsets.zero,
          children: [
            const PageHeader(
              title: 'Slip Gaji',
              subtitle: 'Slip resmi dari pemilik toko',
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 18, 20, 24),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  if (_error != null)
                    ErrorView(message: _error!, onRetry: _load)
                  else if (_items == null)
                    const Padding(
                      padding: EdgeInsets.all(48),
                      child: Center(child: CircularProgressIndicator()),
                    )
                  else if (_items!.isEmpty)
                    const EmptyView(
                      icon: Icons.receipt_long_outlined,
                      title: 'Belum ada slip gaji',
                      subtitle:
                          'Slip muncul setelah gaji disetujui pemilik toko.',
                    )
                  else
                    ..._items!.map((raw) {
                      final slip = raw as Map<String, dynamic>;
                      final paid = slip['status'] == 'paid';
                      return Padding(
                        padding: const EdgeInsets.only(bottom: 10),
                        child: Container(
                          decoration: BoxDecoration(
                            color: Colors.white,
                            borderRadius: BorderRadius.circular(20),
                            boxShadow: Brand.softShadow(),
                          ),
                          child: InkWell(
                            borderRadius: BorderRadius.circular(20),
                            onTap: _downloading == null
                                ? () => _open(slip)
                                : null,
                            child: Padding(
                              padding: const EdgeInsets.all(18),
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Row(
                                    children: [
                                      Expanded(
                                        child: Text(
                                          slip['period'] as String,
                                          style: const TextStyle(
                                            fontWeight: FontWeight.w800,
                                            fontSize: 16,
                                            color: Brand.ink,
                                          ),
                                        ),
                                      ),
                                      StatusChip(paid ? 'paid' : 'approved'),
                                    ],
                                  ),
                                  const SizedBox(height: 4),
                                  Text(
                                    paid && slip['payment_date'] != null
                                        ? 'Dibayar ${formatDate(slip['payment_date'] as String, 'd MMMM yyyy')}'
                                        : 'Disetujui, menunggu pembayaran',
                                    style: const TextStyle(color: Brand.muted),
                                  ),
                                  const Divider(height: 24),
                                  Row(
                                    children: [
                                      Expanded(
                                        child: Column(
                                          crossAxisAlignment:
                                              CrossAxisAlignment.start,
                                          children: [
                                            const Text(
                                              'Gaji bersih',
                                              style: TextStyle(
                                                color: Brand.muted,
                                                fontSize: 12,
                                              ),
                                            ),
                                            Text(
                                              rupiah.format(slip['net_salary']),
                                              style: const TextStyle(
                                                fontSize: 22,
                                                fontWeight: FontWeight.w800,
                                                color: Brand.violet,
                                              ),
                                            ),
                                          ],
                                        ),
                                      ),
                                      _downloading == slip['id']
                                          ? const SizedBox(
                                              width: 24,
                                              height: 24,
                                              child: CircularProgressIndicator(
                                                strokeWidth: 2.5,
                                              ),
                                            )
                                          : const Row(
                                              children: [
                                                Icon(
                                                  Icons.picture_as_pdf_rounded,
                                                  color: Brand.absent,
                                                ),
                                                SizedBox(width: 4),
                                                Text(
                                                  'Buka PDF',
                                                  style: TextStyle(
                                                    fontWeight: FontWeight.w700,
                                                  ),
                                                ),
                                              ],
                                            ),
                                    ],
                                  ),
                                ],
                              ),
                            ),
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
