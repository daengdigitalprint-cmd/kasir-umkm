# Rencana Implementasi Fitur Program Afiliasi

## Status Dokumen

- **Status perencanaan:** Selesai
- **Status implementasi fitur:** Belum dimulai
- **Basis analisis:** Struktur aplikasi saat ini dan dokumen `fitur-affiliate-kasir-umkm.md`
- **Stack:** Laravel 12, Inertia v2, Vue 3, Tailwind CSS v4, Pest v4

## Ringkasan Analisis Aplikasi

Aplikasi sudah memiliki:

- Autentikasi user tunggal berbasis Laravel pada `routes/auth.php` dan `RegisteredUserController`.
- Entitas `users`, `stores`, `organizations`, `subscription_plans`, dan `subscription_payments`.
- Registrasi user dan pembuatan toko melalui `RegisteredUserController` dan `StoreController`.
- Pembayaran subscription yang masuk sebagai `pending`, lalu dikonfirmasi manual oleh Super Admin melalui `SuperAdminController@confirmPayment`.
- Dashboard Super Admin terpisah menggunakan `SuperAdminLayout.vue`.
- Frontend Inertia Vue dengan route/action TypeScript yang dihasilkan Wayfinder.
- Test feature Pest dengan database SQLite in-memory dan `RefreshDatabase` per test/file.

Fitur yang belum tersedia:

- Model, migration, route, service, controller, dan UI affiliate.
- Penyimpanan referral code di cookie/session.
- Atribusi referral permanen ke toko.
- Diskon affiliate pada checkout subscription.
- Ledger komisi, approval, payout, dan notifikasi affiliate.
- Alur refund subscription di dalam aplikasi.

## Keputusan Bisnis Yang Harus Dikunci

Keputusan berikut menjadi prasyarat sebelum implementasi Tahap 2 dan Tahap 3:

1. **Basis komisi:** direkomendasikan dari nominal payment setelah voucher dan diskon affiliate, karena itu nominal yang benar-benar dibayar.
2. **Jenis komisi:** direkomendasikan recurring untuk setiap payment subscription reguler yang dikonfirmasi; payment add-on tidak ikut komisi sampai ada keputusan terpisah.
3. **Masa pending:** direkomendasikan 14 hari sejak payment dikonfirmasi sebelum otomatis berubah menjadi `approved`.
4. **Diskon referral:** nilai awal harus ditetapkan, misalnya persentase tetap global yang disimpan sebagai snapshot di referral/payment.
5. **Minimal payout:** nilai awal harus ditetapkan, misalnya Rp100.000.
6. **Akses affiliate:** direkomendasikan affiliate tidak memakai login merchant. Gunakan akses terpisah berbasis email dan magic link/token, atau konfirmasi bahwa affiliate harus memiliki akun `users`.
7. **Refund:** karena refund subscription saat ini diproses melalui support di luar aplikasi, perlu ditentukan apakah admin akan memiliki aksi manual `cancelled` pada komisi atau modul refund dibuat lebih dulu.

## Tahap 0: Finalisasi Kontrak Bisnis Dan Data

**Status: Selesai untuk analisis, menunggu konfirmasi bisnis**

- [x] Memetakan alur registrasi user, pembuatan toko, checkout, dan konfirmasi payment.
- [x] Memastikan komisi aggregator POS pada `Transaction` bukan bagian dari fitur affiliate.
- [x] Menentukan titik integrasi utama: `StoreController`, `SubscriptionController`, dan `SuperAdminController`.
- [x] Mengidentifikasi bahwa refund subscription belum memiliki model/controller/status di aplikasi.
- [ ] Mengunci basis komisi.
- [ ] Mengunci recurring atau one-time.
- [ ] Mengunci masa pending.
- [ ] Mengunci nilai diskon dan minimal payout.
- [ ] Mengunci mekanisme login affiliate.
- [ ] Mengunci prosedur refund/cancellation.

**Kriteria selesai:** seluruh keputusan bisnis di atas terdokumentasi dan tidak ambigu.

## Tahap 1: Database Dan Model Affiliate

**Status: Belum dimulai**

### Migration yang direncanakan

Buat migration melalui Artisan, dengan urutan setelah tabel `users` dan `stores` tersedia:

1. `affiliates`
   - `user_id` nullable bila affiliate memakai akun user.
   - `name`, `email`, `phone` untuk identitas mandiri.
   - `referral_code` unique.
   - `commission_percentage` decimal.
   - `status`: `pending`, `active`, `suspended`.
   - Data rekening default nullable.
   - Tambahkan index pada `email` dan `status` bila diperlukan.

2. `affiliate_clicks`
   - `affiliate_id`, `ip_address`, `user_agent`, `clicked_at`.
   - Index gabungan yang mendukung throttle klik per affiliate, IP, dan waktu.

3. `affiliate_referrals`
   - `affiliate_id`.
   - `store_id` nullable atau wajib setelah attribution terjadi.
   - `referred_customer_id` tidak dipakai untuk customer POS; gunakan `store_id` karena produk yang dipromosikan adalah subscription toko.
   - Snapshot diskon dan waktu konversi.
   - Unique constraint agar satu toko hanya memiliki satu referral aktif.

4. `affiliate_transactions`
   - `affiliate_id`, `referral_id`, `subscription_payment_id`.
   - Snapshot `transaction_amount`, `commission_percentage`, dan `commission_amount`.
   - `status`: `pending`, `approved`, `paid`, `cancelled`.
   - `eligible_at`, `approved_at`, `paid_at`, dan `cancelled_at` bila diperlukan untuk audit.
   - Unique constraint pada `subscription_payment_id` agar payment tidak menghasilkan komisi ganda.

5. `affiliate_withdrawals`
   - `affiliate_id`, `amount_requested`.
   - Snapshot bank saat pengajuan.
   - `status`: `requested`, `processing`, `completed`, `rejected`.
   - `requested_at`, `processed_at`, `admin_note`.

6. Perubahan tabel existing
   - Tambahkan `affiliate_id` atau `affiliate_referral_id` pada `stores` hanya jika desain memilih attribution langsung pada toko.
   - Tambahkan `affiliate_referral_id` pada `subscription_payments` agar hubungan payment dan referral dapat ditelusuri tanpa query tidak langsung.
   - Jangan memakai kolom `commission_amount` pada `transactions` karena itu milik komisi aggregator POS.

### Model dan relasi

- Buat `Affiliate`, `AffiliateClick`, `AffiliateReferral`, `AffiliateTransaction`, dan `AffiliateWithdrawal`.
- Tambahkan relasi di `Store` dan `SubscriptionPayment`.
- Ikuti pola `casts()` yang digunakan model Laravel 12 di aplikasi.
- Gunakan enum PHP untuk status bila sesuai pola existing; nilai database tetap konsisten dengan status bisnis.
- Buat factory hanya untuk model yang dipakai dalam test dan ikuti fakta bahwa belum semua model bisnis memiliki factory.

**Kriteria selesai:** migration dapat dijalankan pada SQLite test dan MySQL deployment, relasi Eloquent tersedia, constraint anti-duplikasi teruji, dan rollback migration aman.

## Tahap 2: Tracking Referral Dan Attribution

**Status: Belum dimulai**

### Backend

- Buat middleware atau endpoint landing referral yang membaca `?ref=KODE`.
- Validasi hanya affiliate berstatus `active` yang boleh direkam.
- Simpan kode referral dalam cookie selama 30 hari sesuai konfigurasi aplikasi.
- Catat klik unik dengan throttle per affiliate/IP dalam rentang waktu yang ditentukan.
- Jangan menghitung refresh berulang sebagai klik baru.
- Saat toko dibuat di `StoreController@store`, resolve cookie referral dan buat satu `AffiliateReferral` untuk toko tersebut.
- Jangan re-attribute toko yang sudah memiliki referral.
- Simpan snapshot diskon dan affiliate saat konversi, sehingga perubahan konfigurasi berikutnya tidak mengubah histori.
- Bersihkan cookie/session referral setelah attribution berhasil.

### File target

- `app/Http/Middleware/` atau `app/Services/` untuk resolver/tracker, mengikuti pola middleware Laravel 12 di `bootstrap/app.php`.
- `app/Http/Controllers/StoreController.php`.
- `routes/web.php`.
- `app/Models/Store.php` dan model affiliate.
- `config/` untuk masa cookie, diskon, dan aturan klik bila konfigurasi diperlukan.

### Test minimum

- Kode valid menyimpan cookie.
- Affiliate nonaktif tidak dapat mengatribusikan referral.
- Klik refresh dalam throttle window tidak menggandakan hitungan.
- Toko baru hanya dapat memiliki satu referral.
- Toko yang sudah pernah teratribusi tidak berpindah ke affiliate baru.

**Kriteria selesai:** referral dapat dilacak dari URL sampai toko baru tanpa mengubah atribusi existing dan seluruh edge case tahap ini lulus test.

## Tahap 3: Diskon Checkout Dan Pencatatan Komisi

**Status: Belum dimulai**

### Checkout

- Ubah `SubscriptionController` agar referral toko dapat ditemukan secara server-side.
- Hitung harga checkout dengan urutan yang terdokumentasi: harga paket, diskon affiliate, lalu voucher bila aturan bisnis mengizinkan kombinasi.
- Tampilkan harga asli, diskon, dan total akhir di `Subscription/Checkout.vue`.
- Jangan percaya nominal dari frontend; hitung ulang saat `submitPayment()`.
- Simpan snapshot referral/diskon ke `SubscriptionPayment`.
- Tentukan secara eksplisit apakah payment `outlet_addon` dan `attendance_payroll_addon` dikecualikan.

### Komisi

- Buat service khusus, misalnya `AffiliateCommissionService`, untuk menghitung dan membuat ledger.
- Panggil service setelah payment reguler berhasil dikonfirmasi di `SuperAdminController@confirmPayment`.
- Buat transaksi komisi berstatus `pending`, dengan `eligible_at` berdasarkan masa tunggu.
- Pastikan konfirmasi payment yang sama tidak membuat komisi kedua.
- Untuk recurring, setiap payment reguler yang dikonfirmasi membuat satu transaksi komisi baru untuk referral toko yang sama.
- Tambahkan command terjadwal untuk mengubah komisi `pending` menjadi `approved` setelah `eligible_at`, atau sediakan proses admin jika scheduler belum dipakai.

### File target

- `app/Http/Controllers/SubscriptionController.php`.
- `app/Http/Controllers/SuperAdminController.php`.
- `app/Services/AffiliateCommissionService.php`.
- `app/Models/SubscriptionPayment.php`.
- `resources/js/pages/Subscription/Checkout.vue`.
- `app/Console/Commands/` dan konfigurasi schedule bila approval otomatis digunakan.

**Kriteria selesai:** total checkout dan commission snapshot konsisten server-side, payment confirmed menghasilkan satu komisi pending, recurring berjalan sesuai aturan, dan add-on tidak ikut secara tidak sengaja.

## Tahap 4: Akses Dan Dashboard Affiliate

**Status: Belum dimulai**

### Akses

- Implementasikan auth guard atau token login terpisah sesuai keputusan Tahap 0.
- Jangan memakai middleware `superadmin` atau middleware merchant pada dashboard affiliate.
- Batasi seluruh query berdasarkan affiliate yang sedang login.
- Tambahkan rate limit pada login/token request.

### Halaman Vue

Buat halaman di `resources/js/pages/Affiliate/`:

- `Login.vue` atau halaman request magic link.
- `Dashboard.vue` untuk link referral, total klik, konversi, conversion rate, pending, approved, dan paid.
- `Transactions.vue` untuk riwayat komisi dengan pagination.
- `Withdrawals.vue` untuk rekening, pengajuan payout, saldo approved, dan riwayat payout.

Gunakan `AdminLayout` hanya bila keputusan akses menyatakan affiliate adalah user merchant. Jika akses terpisah, buat layout affiliate sederhana yang tetap memakai komponen UI existing.

### Backend

- Controller dashboard affiliate.
- Form Request untuk update rekening dan pengajuan withdrawal.
- Resource/transformer atau payload Inertia yang hanya mengirim data yang dibutuhkan.
- Policy atau middleware untuk mencegah akses lintas affiliate.

**Kriteria selesai:** affiliate dapat login, melihat statistik/ledger, menyimpan rekening, dan hanya dapat mengajukan payout sesuai saldo approved serta minimal payout.

## Tahap 5: Panel Super Admin Dan Payout

**Status: Belum dimulai**

### Backend

- Tambahkan route di group `superadmin` pada `routes/web.php`.
- Tambahkan controller khusus bila `SuperAdminController` sudah terlalu besar; jangan menambah seluruh domain affiliate ke controller tersebut.
- Endpoint list affiliate dengan filter status dan statistik.
- Endpoint approve/suspend affiliate.
- Endpoint list withdrawal status `requested`.
- Endpoint perubahan status `processing`, `completed`, atau `rejected` dengan catatan.
- Gunakan database transaction dan lock saat memvalidasi saldo agar dua withdrawal tidak memakai saldo yang sama.
- Saat withdrawal selesai, tandai komisi yang dibayarkan sebagai `paid` atau gunakan allocation yang jelas bila satu payout membayar banyak komisi.

### Frontend

- Tambahkan tab affiliate pada `SuperAdminLayout.vue`.
- Buat halaman `resources/js/pages/SuperAdmin/Affiliates.vue`.
- Buat halaman `resources/js/pages/SuperAdmin/AffiliateWithdrawals.vue` atau tab terpisah sesuai pola halaman Super Admin existing.
- Tambahkan badge jumlah withdrawal `requested` bila payload dashboard menyediakannya.

### Pengaturan global

Simpan default commission percentage, minimal payout, cookie lifetime, diskon affiliate, dan pending days pada konfigurasi atau tabel settings existing setelah pola penyimpanan yang tepat dipastikan. Jangan membaca `env()` langsung dari controller.

**Kriteria selesai:** Super Admin dapat mengelola affiliate, memproses withdrawal dengan audit trail, dan sistem mencegah payout melebihi saldo approved.

## Tahap 6: Refund, Notifikasi, Dan Otomasi Status

**Status: Belum dimulai dan terblokir sebagian oleh gap refund existing**

### Refund

- Tentukan apakah refund subscription tetap diproses manual melalui support.
- Jika tetap manual, tambahkan aksi Super Admin untuk membatalkan komisi terkait dengan alasan wajib.
- Jika refund akan dikelola aplikasi, buat model/status refund dan hubungkan ke `SubscriptionPayment`.
- Saat refund disetujui, ubah komisi terkait menjadi `cancelled` dan cegah komisi tersebut dibayar.
- Untuk komisi yang sudah `paid`, tentukan kebijakan reversal/negative adjustment. Jangan menghapus histori.

### Notifikasi

Gunakan sistem notification existing untuk:

- Referral berhasil dikonversi.
- Komisi menjadi `approved`.
- Withdrawal masuk, diproses, selesai, atau ditolak.
- Admin menerima withdrawal baru.

Email/WhatsApp hanya ditambahkan setelah channel dan kredensial pengiriman aplikasi dikonfirmasi. Jangan menambah dependency tanpa persetujuan.

### Otomasi

- Command approval komisi pending.
- Scheduler untuk command tersebut.
- Logging untuk kegagalan pemrosesan dan perubahan status penting.

**Kriteria selesai:** perubahan status komisi idempoten, refund tidak dapat menghasilkan payout keliru, dan notifikasi tidak terkirim berulang untuk event yang sama.

## Tahap 7: Testing, Security, Dan Deployment Check

**Status: Belum dimulai**

### Test feature Pest

Buat test terfokus, minimal:

- Tracking referral valid, invalid, expired, dan affiliate suspended.
- Throttle klik dan click count.
- Attribution satu kali untuk toko.
- Diskon checkout dan kombinasi voucher sesuai keputusan bisnis.
- Komisi berdasarkan nominal yang benar.
- Payment confirmation idempotent.
- Recurring commission atau one-time sesuai keputusan.
- Pending menjadi approved setelah masa tunggu.
- Refund mengubah komisi menjadi cancelled.
- Withdrawal ditolak jika saldo tidak cukup atau di bawah minimal payout.
- Dua request withdrawal bersamaan tidak dapat menghabiskan saldo dua kali.
- Isolasi data: affiliate A tidak dapat melihat data affiliate B.
- Super Admin dapat mengelola affiliate dan withdrawal.

Aktifkan `RefreshDatabase` secara lokal pada test yang menyentuh database, gunakan setup manual atau factory yang tersedia, dan jangan mengandalkan factory bisnis yang belum ada.

### Verifikasi teknis

- Jalankan test feature affiliate yang relevan.
- Jalankan `vendor/bin/pint --dirty`.
- Jalankan lint/type check frontend sesuai script `package.json`.
- Jalankan `php artisan wayfinder:generate` setelah route/controller berubah bila generator tidak berjalan otomatis.
- Jalankan migration pada SQLite test dan verifikasi kompatibilitas MySQL untuk foreign key, enum, index, serta constraint unique.
- Pastikan perubahan tidak menyentuh komisi aggregator POS pada `Transaction`.

**Kriteria selesai:** test tahap affiliate lulus, format/lint lulus, migration tervalidasi, dan tidak ada regresi pada subscription existing.

## Urutan Eksekusi Yang Disarankan

1. Konfirmasi keputusan bisnis pada Tahap 0.
2. Implementasikan migration, model, relasi, dan test database pada Tahap 1.
3. Setelah struktur database dikonfirmasi, implementasikan tracking pada Tahap 2.
4. Implementasikan diskon dan ledger komisi pada Tahap 3.
5. Implementasikan dashboard affiliate pada Tahap 4.
6. Implementasikan panel Super Admin dan payout pada Tahap 5.
7. Selesaikan kebijakan refund, notifikasi, dan scheduler pada Tahap 6.
8. Jalankan regression/security/deployment check pada Tahap 7.

## Status Keseluruhan Saat Ini

- **Tahap 0:** Dalam proses, analisis struktur selesai; keputusan bisnis belum dikunci.
- **Tahap 1:** Belum dimulai.
- **Tahap 2:** Belum dimulai.
- **Tahap 3:** Belum dimulai.
- **Tahap 4:** Belum dimulai.
- **Tahap 5:** Belum dimulai.
- **Tahap 6:** Belum dimulai; sebagian bergantung pada keputusan refund.
- **Tahap 7:** Belum dimulai.

Dokumen ini menjadi baseline implementasi. Setiap tahap diubah ke **Dalam proses** ketika pengerjaan dimulai, lalu ke **Selesai** hanya setelah kriteria selesai dan test tahap tersebut lulus.
