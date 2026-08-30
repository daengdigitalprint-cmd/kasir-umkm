# Rencana Gating Paket Berbayar — Absensi + Payroll

> Status: RENCANA (belum diimplementasikan)
> Tanggal: 2026-08-15
> Tujuan: menjadikan modul Absensi dan Payroll sebagai **satu paket addon berbayar** yang aktif setelah dibeli di halaman `/subscription`, menggantikan aktivasi manual gratis saat ini.

## 1. Ringkasan

Saat ini modul absensi diaktifkan manual lewat toggle `stores.settings['attendance']['enabled']` di `/attendance/settings`. Ini tidak berbayar.

Target: aktivasi pindah ke mekanisme pembelian paket addon, meniru pola `outlet_addon` yang sudah ada. Satu pembelian mengaktifkan absensi + payroll sekaligus (flag tunggal).

## 2. Temuan Audit (sistem yang sudah ada)

- `subscription_plans` — paket Starter/GrowUp, kolom `features` array; tidak ada konsep "addon" di sini.
- `subscription_payments.type` — `string`, default `subscription`; sudah dipakai `outlet_addon`. Tidak perlu migrasi kolom.
- `OutletAddOnService` — pola lengkap addon berbayar: `createPayment()` (anti double-submit) + `applyConfirmedAddOn()`.
- `Store` — kolom `extra_outlets` (int) untuk menyimpan hasil pembelian addon.
- `SubscriptionController` — `index()` + `checkoutOutlet()` + `submitOutletAddOn()`.
- `SuperAdminController::confirmPayment()` — branch `type === 'outlet_addon'` memanggil `applyConfirmedAddOn`.
- `Subscription/Index.vue` — tab `subscription | outlet` + kartu addon outlet + `OutletCheckout.vue`.
- `AttendanceSettings::enabled()` — baca `stores.settings['attendance']['enabled']` (toggle manual).
- `EnsureAttendanceEnabled` — redirect ke `attendance.settings` saat off.

## 3. Desain Target

Satu addon `attendance_payroll_addon` dengan **flag tunggal** `stores.attendance_payroll_until` (datetime, `null` = belum beli / kedaluwarsa).

```
Dibeli  →  admin konfirmasi  →  attendance_payroll_until = now + durasi
Absensi aktif = attendance_payroll_until >= now()
Payroll aktif = flag yang sama (menu payroll muncul saat flag aktif, saat modul payroll dibangun)
```

Sumber kebenaran aktivasi berpindah dari `settings['attendance']['enabled']` ke kolom `attendance_payroll_until`. Toggle manual di `/attendance/settings` dihapus.

## 4. Perubahan per Lapisan

### 4.1 Database

- Migrasi baru: `add_attendance_payroll_until_to_stores_table`
  - `$table->timestamp('attendance_payroll_until')->nullable()->after('extra_outlets');`
- Tidak perlu migrasi untuk `subscription_payments.type` (sudah `string`).
- `Store::$fillable` + `$casts` tambah `attendance_payroll_until` (datetime).

### 4.2 Layanan (backend)

- Buat model single-row `App\Models\AttendancePayrollPricing` (meniru `SubscriptionTransferSetting`):
  - Tabel `attendance_payroll_pricing`: `monthly_price` (decimal), `yearly_price` (decimal).
  - `current()` + `toPayload()`.
  - Seed awal: `monthly_price = 49000`, `yearly_price = 490000`.
- Buat `app/Services/AttendancePayrollAddOnService.php` (salin pola `OutletAddOnService`):
  - Baca harga dari `AttendancePayrollPricing::current()` (bukan hardcode).
  - `priceFor(string $billingCycle)`.
  - `createPayment(Store, string $paymentMethod, ?string $notes, ?User)` — `type = 'attendance_payroll_addon'`, anti double-submit (pending), simpan `duration_days` 30/365.
  - `applyConfirmedAddOn(SubscriptionPayment)` — set `stores.attendance_payroll_until = now()->addDays(duration_days)`.

### 4.3 Kontroller subscription

- `SubscriptionController::index()` — tambah payload `attendancePayrollInfo`:
  - `is_purchased`, `expires_at`, `price_monthly`, `price_yearly`, `has_pending_addon`, `can_buy_addon`.
- Tambah `checkoutAttendancePayroll()` (render `Subscription/AttendancePayrollCheckout`).
- Tambah `submitAttendancePayroll(Request)` (validasi `payment_method`, `billing_cycle`, `payment_proof`; panggil service).

### 4.4 Konfirmasi admin

- `SuperAdminController::confirmPayment()` — tambah branch:
  ```php
  if ($payment->type === 'attendance_payroll_addon') {
      AttendancePayrollAddOnService::applyConfirmedAddOn($payment);
      $store->subscription_notes = "Paket Absensi+Payroll dikonfirmasi via #{$payment->invoice_number}";
      $store->save();
      return;
  }
  ```
- `SuperAdmin/Dashboard.vue` — tambah label untuk `type === 'attendance_payroll_addon'` (mirip blok outlet_addon).

### 4.4.1 Pengaturan harga addon di super admin

- `SuperAdminController` — tambah `updateAttendancePayrollPricing(Request)` (validasi `monthly_price`/`yearly_price` numeric min 0; simpan ke `AttendancePayrollPricing::current()`).
- `routes/web.php` — `Route::post('/attendance-payroll-pricing', ...)->name('attendance-payroll-pricing.update')`.
- `SuperAdmin/Dashboard.vue` — form harga bulanan & tahunan (meniru form `transferSettings`), kirim ke route di atas.
- `SubscriptionController` membaca harga via `AttendancePayrollPricing::current()` agar checkout selalu pakai harga terkini.

### 4.5 Modul Attendance (gating)

- `AttendanceSettings::enabled()` — ubah jadi cek `store->attendance_payroll_until >= now()`.
- Hapus method `update(['enabled' => ...])` untuk toggle manual (atau buang parameter `enabled`).
- `AttendanceSettingsController::update()` — buang field `enabled`; halaman settings hanya mengelola lokasi/radius/kebijakan, bukan aktivasi.
- `EnsureAttendanceEnabled` — pesan error jadi "Paket Absensi + Payroll belum aktif. Beli di halaman Langganan." dan redirect ke `/subscription` (bukan `/attendance/settings`).

### 4.6 Frontend

- `Attendance/Settings.vue`:
  - Hapus toggle "Aktifkan modul".
  - Tampilkan status paket (aktif sampai tanggal X) + tombol "Beli paket" → `/subscription` bila belum aktif.
- `Subscription/Index.vue` — tambah tab/kartu addon "Absensi + Payroll" (mirip kartu outlet): status, harga, tombol beli.
- `Subscription/AttendancePayrollCheckout.vue` — halaman checkout baru (salin `OutletCheckout.vue`).
- `MainMenu.vue` — menu Absensi tetap tampil (sudah), gating via redirect middleware; menu Payroll ditunda sampai modul payroll dibangun, memakai flag yang sama.

### 4.7 Shared props

- `HandleInertiaRequests` — `attendance_enabled` tetap dari `AttendanceSettings::enabled()` (sekarang berbasis pembelian).

## 5. Alur Aktivasi End-to-End

1. Owner buka `/subscription`.
2. Pilih addon "Absensi + Payroll" → checkout → unggah bukti transfer/QRIS.
3. `subscription_payments` terisi `type=attendance_payroll_addon`, `status=pending`.
4. Super admin konfirmasi di Dashboard → `status=confirmed`, `stores.attendance_payroll_until` diisi.
5. Menu Absensi (dan kelak Payroll) aktif; `/attendance` bisa diakses.
6. Saat kedaluwarsa (`attendance_payroll_until < now`), akses otomatis diblokir middleware, arahkan ke `/subscription` untuk perpanjang.

## 6. Keputusan (final)

| Item | Keputusan |
|------|-----------|
| Harga bulanan | Rp 49.000 |
| Harga tahunan | Rp 490.000 |
| Harga editable | Ya, di super admin (`attendance_payroll_pricing`) |
| Cakupan paket | Semua paket, termasuk Starter |
| Durasi | 30 hari bulanan / 365 hari tahunan |
| Payroll menu | Nanti saat modul payroll dibangun (flag sama) |
| Backfill toko aktif manual | Tidak; semua pemilik beli ulang |

## 7. Langkah Implementasi (fase kecil)

1. Migrasi `add_attendance_payroll_until_to_stores_table` + `create_attendance_payroll_pricing_table`; `Store` fillable/cast.
2. Model `AttendancePayrollPricing` + seed harga awal.
3. `AttendancePayrollAddOnService` + test service (meniru `OutletAddOnTest`).
4. `SubscriptionController` payload + checkout + submit (baca harga dari pricing).
5. `Subscription/Index.vue` + `AttendancePayrollCheckout.vue`.
6. `SuperAdminController` branch konfirmasi + `updateAttendancePayrollPricing` + label Dashboard + form harga.
7. Ubah `AttendanceSettings::enabled()` + `AttendanceSettingsController` + `EnsureAttendanceEnabled`.
8. `Attendance/Settings.vue` status paket + CTA beli.
9. Update `AttendanceEndToEndTest` (aktivasi via pembelian, bukan toggle).
10. Update dokumen progres.

## 8. Risiko & Catatan

- Tidak ada backfill: toko yang aktif manual akan terkunci (`attendance_payroll_until` null) dan harus beli addon. Pastikan pesan redirect jelas mengarahkan ke `/subscription`.
- Anti double-submit penting: cek pending `type=attendance_payroll_addon` sebelum buat payment baru.
- Jangan lupa `attendance_payroll_until` diperlakukan `null` = tidak aktif (aman).
- Harga di super admin: jangan izinkan nilai 0 kecuali memang mau gratis; validasi `min:0` dan tampilkan format Rupiah di checkout.
