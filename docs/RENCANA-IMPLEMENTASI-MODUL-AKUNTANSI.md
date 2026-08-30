# Rencana implementasi: Modul Akuntansi (Jurnal) on/off

Dokumen ini merinci cara memisahkan fitur jurnal/tutup periode dari POS inti, sesuai standar kasir UMKM di Indonesia dan pola modul yang sudah ada di aplikasi (antrian, scan meja, grosir, absensi).

**Keputusan produk:** jurnal **bukan** menu wajib. Ia menjadi **modul yang bisa diaktifkan/nonaktifkan**. Default untuk toko baru: **mati**. POS tidak boleh terkunci oleh tutup periode selama modul mati.

Dokumen terkait: `docs/PENJELASAN-BUKA-TUTUP-PERIODE-AKUNTANSI.md`

---

## 1. Tujuan

| Saat modul **mati** | Saat modul **nyala** |
|---|---|
| Kasir jualan normal | Setiap penjualan/pembelian relevan punya jurnal |
| Tidak posting jurnal otomatis | Jurnal otomatis + tutup periode |
| Tidak ada kunci periode di POS | POS diblokir jika tanggal jual tidak masuk periode terbuka |
| Tab laporan keuangan akuntansi disembunyikan | Menu/tab akuntansi tampil |
| Laporan kasir biasa tetap ada (penjualan, stok, kas, arus kas operasional) | Laporan akuntansi (neraca, jurnal, laba rugi dari jurnal, dll.) |

Satu aturan: **kalau akuntansi dipakai, penjualan harus sinkron dengan jurnal. Kalau tidak dipakai, jangan ganggu kasir.**

---

## 2. Yang tidak diubah di tahap ini

- Skema tabel jurnal (`journal_entries`, `journal_lines`, `accounting_periods`, COA) tetap.
- Data jurnal lama **tidak dihapus** saat modul dimatikan (hanya berhenti posting + disembunyikan).
- Tidak membangun aplikasi akuntansi terpisah.
- Tidak memindahkan semua tab ke sidebar baru di tahap 1 (cukup gating). Sidebar grup **Akuntansi** opsional di tahap 3.

---

## 3. Desain teknis singkat

### 3.1 Flag toko

- Key settings: `accounting_enabled` (boolean)
- Default: `false`
- Dicatat lewat `ModuleService` seperti modul lain

```php
// app/Services/ModuleService.php
'accounting_enabled' => 'Modul Akuntansi (Jurnal)',
```

### 3.2 Helper pusat

Buat `app/Support/Accounting/AccountingFeatures.php` (pola `FnBFeatures` / `ScanMejaFeatures`):

```php
AccountingFeatures::enabled(Store $store): bool
```

Semua titik posting dan kunci POS **wajib** lewat helper ini. Jangan cek `$store->settings['accounting_enabled']` tersebar tanpa helper.

### 3.3 Perilaku inti

```
AccountingFeatures::enabled === false
  → JournalPoster::* tidak dipanggil (atau return di awal)
  → AccountingPeriodGuard::posLockMessage selalu null
  → route laporan keuangan abort 404 / redirect laporan biasa
  → tab financial di ReportTabs disembunyikan

AccountingFeatures::enabled === true
  → perilaku sekarang (posting + kunci periode)
```

Lebih rapi: **guard di dalam JournalPoster** (satu tempat) plus **kunci POS hanya jika modul on**. Controller tetap boleh memanggil `postSaleTransaction`; di dalam poster, jika modul mati → no-op.

---

## 4. Tahap pengerjaan

### Tahap 1 — Fondasi saklar (prioritas)

**Hasil:** toko bisa nyala/mati modul; POS tidak terkunci jika mati; jurnal tidak bertambah jika mati.

1. Tambah `accounting_enabled` ke `ModuleService::MODULES`.
2. Buat `AccountingFeatures::enabled()`.
3. Saklar di **Pengaturan toko** (`resources/js/pages/settings/Profile.vue`), teks singkat:
   - Judul: *Aktifkan Modul Akuntansi (Jurnal)*
   - Penjelasan: *Laporan neraca, jurnal, tutup periode. Jika aktif, kasir hanya bisa bayar jika periode bulan berjalan terbuka.*
4. Simpan di `StoreSettingsController` + payload `ProfileController` (sama seperti `queue_module_enabled`).
5. Share ke Inertia: `HandleInertiaRequests` → `accounting_enabled`.
6. `JournalPoster`: di awal setiap `post*` / `postReversal` / `reverseStockAdjustment` (yang membuat/ubah jurnal), return jika modul mati.
   - `reverseStockAdjustment` yang **menghapus** jurnal: tetap no-op jika modul mati (jangan hapus histori diam-diam).
7. `POSController`:
   - `accountingPeriodLock` = null jika modul mati
   - Jangan panggil `posLockMessage` sebagai penolak bayar jika modul mati
8. Tes: toko dengan `accounting_enabled=false` bisa `process-payment` meskipun semua periode ditutup; **tidak** ada `journal_entries` baru.

**Berkas utama**

- `app/Services/ModuleService.php`
- `app/Support/Accounting/AccountingFeatures.php` (baru)
- `app/Support/Accounting/JournalPoster.php`
- `app/Support/Accounting/AccountingPeriodGuard.php` (opsional: `posLockMessage` cek enabled)
- `app/Http/Controllers/POSController.php`
- `app/Http/Controllers/StoreSettingsController.php`
- `app/Http/Controllers/Settings/ProfileController.php`
- `app/Http/Middleware/HandleInertiaRequests.php`
- `resources/js/pages/settings/Profile.vue`

**Kriteria selesai**

- [ ] Default toko baru: modul mati
- [ ] POS tidak menampilkan banner kunci periode jika modul mati
- [ ] Tombol bayar tidak disabled karena periode
- [ ] Penjualan/pembelian/stok/kas tidak menambah jurnal
- [ ] Audit log modul tercatat enable/disable

---

### Tahap 2 — Sembunyikan UI + kunci route

**Hasil:** user tidak melihat (dan tidak bisa buka URL) fitur akuntansi jika modul mati.

1. `ReportTabs.vue`: tab `financial: true` hanya jika `accounting_enabled`.
   - Tab **Arus Kas** (`/reports/cash-flow`) dan **Utang Usaha** perlu dipilah: jika itu laporan operasional kasir (bukan dari jurnal), **tetap tampil**. Jika bergantung jurnal, ikut modul.
2. Middleware atau gate di controller:
   - `JournalEntryController`
   - `AccountingPeriodController`
   - method financial di `ReportController` (neraca, laba rugi jurnal, trial balance, rekonsiliasi, CaLK, PPN dari jurnal, capital injection, dsb.)
   - Jika modul mati → 404 atau redirect `/reports` + flash: *Modul akuntansi belum aktif.*
3. POS: banner + disable bayar **hanya** jika modul on **dan** `posLockMessage` tidak null. Link ke periode boleh tetap.
4. Teks hapus transaksi/pembelian yang menyebut “jurnal akan dihapus”: tampilkan hanya jika modul on.

**Berkas utama**

- `resources/js/components/ReportTabs.vue`
- `resources/js/pages/POS/Index.vue`
- `app/Http/Controllers/JournalEntryController.php`
- `app/Http/Controllers/AccountingPeriodController.php`
- `app/Http/Controllers/ReportController.php` (method `/reports/financial/*` dan tax PPN jika dari jurnal)
- `resources/js/pages/Reports/Sales.vue` (pesan hapus)
- `resources/js/pages/Purchases/Index.vue`

**Kriteria selesai**

- [ ] URL `/reports/financial/journal` ditolak jika modul mati
- [ ] Tab Jurnal/Periode/Neraca/dll. tidak tampil
- [ ] Laporan Ringkasan / Penjualan / Stok tetap ada

---

### Tahap 3 — UX aktivasi (opsional, disarankan)

**Hasil:** nyalakan modul tidak “setengah jalan”.

Saat **mengaktifkan** modul:

1. Dialog konfirmasi: mulai saat ini setiap transaksi akan dijurnal; tutup periode akan mengunci kasir.
2. Wizard singkat (1 halaman):
   - Pastikan COA sudah ada (`ChartOfAccountsBootstrap::ensureForStore`)
   - Tawarkan buat periode **bulan berjalan**: nama `Agustus 2026`, dari `2026-08-01`, sampai `2026-08-31`, status open
3. Transaksi **sebelum** modul nyala tetap tanpa jurnal (sudah terdokumentasi di trial balance). Jangan backfill otomatis di tahap ini (risiko salah saldo). Backfill = tahap kemudian, manual/job, bukan default.

Saat **mematikan** modul:

1. Konfirmasi: laporan akuntansi disembunyikan; jurnal lama **tidak dihapus**; POS tidak lagi terkunci.
2. Periode tertutup tidak diubah.

**Kriteria selesai**

- [ ] Aktivasi pertama membuat/memastikan periode bulan berjalan terbuka
- [ ] Tidak ada backfill jurnal diam-diam
- [ ] Mematikan modul tidak menghapus histori

---

### Tahap 4 — Penguatan (boleh belakangan)

- Grup sidebar **Akuntansi** (bukan 10 tab di Laporan) jika modul on
- Validasi overlap periode + tanggal harus 1 s.d. akhir bulan (cegah “September 24 Agu–30 Sep”)
- Fitur **buka ulang periode** (hanya owner, dengan alasan) — terpisah, karena sekarang periode tertutup tidak bisa dibuka
- Job backfill jurnal untuk rentang tanggal (opsional, berbayar/advanced)
- Paket langganan: modul hanya di paket tertentu (`StorePlanLimits`) jika ingin dijual sebagai add-on

---

## 5. Titik kode yang harus di-gate

### Posting jurnal (no-op jika modul mati)

| Pemicu | Lokasi |
|---|---|
| Penjualan POS | `POSController` → `JournalPoster::postSaleTransaction` |
| Pembelian | `PurchaseController` |
| Kas shift | `CashTransactionController` |
| Koreksi stok | `StockAdjustmentController` |
| Stok opname | `StockOpnameController` |
| Setoran modal | `ReportController` (capital injection) |
| Jurnal pembalik | `JournalEntryController` |

Guard tanggal periode (hanya relevan jika modul on):

- `AccountingPeriodGuard` di POS, pembelian, pembayaran pembelian, beban operasional, batal transaksi

### UI

- Tab `financial: true` di `ReportTabs.vue`
- Banner `accountingPeriodLock` di `POS/Index.vue`
- Checkbox di `settings/Profile.vue`

---

## 6. Default toko lama vs toko baru

Ini keputusan data, harus eksplisit saat implementasi:

**Opsi A (disarankan untuk standar POS):** default `false` untuk semua, termasuk toko lama. Toko yang sudah pakai jurnal harus **menyalakan lagi** sekali. Risiko: toko yang sudah tutup periode kaget jurnal berhenti. Mitigasi: pengumuman di dashboard jika ada `journal_entries` tetapi flag belum diset.

**Opsi B:** toko yang **sudah punya** `journal_entries` atau `accounting_periods` di-set `accounting_enabled=true` otomatis (migrasi sekali). Toko tanpa histori jurnal = `false`.

**Rekomendasi:** **Opsi B**. Tidak mematikan akuntansi untuk toko yang sudah jalan (seperti toko Anda sekarang), tetapi toko baru tetap ringan.

Migrasi:

```text
Untuk setiap store:
  jika ada journal_entries ATAU accounting_periods
    settings.accounting_enabled = true
  selain itu
    settings.accounting_enabled = false (atau biarkan unset = false)
```

---

## 7. Setting periode setelah modul nyala

Tidak berubah dari best practice:

| Nama | Dari | Sampai | Status |
|---|---|---|---|
| Agustus 2026 | 01-08-2026 | 31-08-2026 | Terbuka (bulan berjalan) |
| September 2026 | 01-09-2026 | 30-09-2026 | Dibuat & dibuka setelah Agustus ditutup |

Tutup hanya setelah bulan selesai. Jangan overlap. Jangan mulai September tanggal 24 Agustus.

---

## 8. Tes yang wajib

| Kasus | Harapan |
|---|---|
| Modul off, periode tertutup, bayar POS | 200 sukses, **0** jurnal baru |
| Modul off, buka `/reports/financial/journal` | 404 / redirect |
| Modul on, periode bulan ini open, bayar POS | 200, 1 jurnal penjualan seimbang |
| Modul on, periode bulan ini closed, bayar POS | 422 + pesan periode, **0** transaksi baru |
| Nyalakan modul | log `module_activity_logs`, COA ada, periode bulan ini ditawarkan |
| Matikan modul | POS tidak terkunci, jurnal lama tetap di DB |
| Pembelian dengan modul off | stok bertambah, tanpa jurnal |
| Pembelian dengan modul on | stok + jurnal |

File tes usulan: `tests/Feature/AccountingModuleGatingTest.php` (plus perluas tes POS/periode yang sudah ada).

---

## 9. Urutan rollout

1. Merge tahap 1 + 2 + migrasi Opsi B (tanpa ini, saklar tidak ada artinya di UI).
2. Tahap 3 wizard (boleh PR terpisah).
3. Tahap 4 overlap/reopen/sidebar setelah modul dipakai tenang.

Tidak perlu feature flag env jika sudah ada `accounting_enabled` per toko.

---

## 10. Perkiraan ukuran kerja

| Tahap | Perkiraan | Risiko |
|---|---|---|
| 1 Fondasi | 0,5–1 hari | Terlewat 1 pemanggil JournalPoster |
| 2 UI + route | 0,5 hari | Tab arus kas/utang salah dikunci |
| 3 Wizard aktivasi | 0,5 hari | Periode overlap jika user isi manual |
| 4 Penguatan | terpisah | Scope merambat |

---

## 11. Ringkasan eksekusi

```
[Pengaturan toko]
    saklar Modul Akuntansi (Jurnal)  →  settings.accounting_enabled

[Modul OFF]  POS + stok + laporan kasir     (tanpa jurnal, tanpa kunci)
[Modul ON ]  + posting jurnal + tutup buku + kunci POS jika periode tertutup
```

Langkah pertama yang dikerjakan saat implementasi: helper `AccountingFeatures`, saklar settings, no-op di `JournalPoster`, hilangkan kunci POS jika off, lalu gating `ReportTabs` + route.
