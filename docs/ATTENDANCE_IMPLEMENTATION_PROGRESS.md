# Progres Implementasi Modul Absensi (Addon Terpisah) + Integrasi Payroll

> Dokumen ini melacak progres implementasi modul absensi secara bertahap (incremental).
> Rencana detail & best practice: [ATTENDANCE_PAYROLL_INTEGRATION_PLAN.md](./ATTENDANCE_PAYROLL_INTEGRATION_PLAN.md)

## Ringkasan Progres Keseluruhan

**Terakhir diperbarui:** 2026-08-15 21:00 WITA

| Tahap | Status | PIC | Tenggat Awal |
|-------|--------|-----|--------------|
| Fase 0 — Persiapan & Dokumen Tracking | ✅ Selesai | Asisten AI | 2026-08-15 |
| Fase 1 — Fondasi Addon | ✅ Selesai | Asisten AI | 2026-08-15 |
| Fase 2 — Model Data | ✅ Selesai | Asisten AI | 2026-08-16 |
| Fase 3 — Layanan Inti | ✅ Selesai | Asisten AI | 2026-08-16 |
| Fase 4 — Kontroller & API | ✅ Selesai | Asisten AI | 2026-08-17 |
| Fase 5 — Frontend & Menu | ✅ Selesai | Asisten AI | 2026-08-18 |
| Fase 6 — Integrasi Payroll & Command | ✅ Selesai | Asisten AI | 2026-08-19 |
| Fase 7 — Pengaturan Admin Absensi | ✅ Selesai | Asisten AI | 2026-08-20 |
| Fase 8 — CRUD Jadwal Kerja, Cuti & Lembur | ✅ Selesai | Asisten AI | 2026-08-20 |
| Fase 9 — Uji End-to-End | ✅ Selesai | Asisten AI | 2026-08-20 |
| Fase 10 — Gating Addon Berbayar Absensi + Payroll | ✅ Selesai | Asisten AI | 2026-08-15 |

**Kemajuan:** 11 dari 11 tahap selesai.

**Catatan:** Operasi git (commit) di-skip sesuai permintaan; progres dicatat hanya pada dokumen ini.

---

## Daftar Tahapan

1. **Fase 0 — Persiapan & Dokumen Tracking**: struktur dokumen progres + daftar tahapan + status/PIC/tenggat.
2. **Fase 1 — Fondasi Addon**: struktur modul `app/Modules/Attendance`, `AttendanceSettings`, middleware aktivasi, alias middleware, `routes/attendance.php`, flag di `HandleInertiaRequests`.
3. **Fase 2 — Model Data**: migration + model + relasi untuk `employees`, `work_schedules`, `attendances`, `attendance_photos`, `leave_requests`, `overtime_requests`.
4. **Fase 3 — Layanan Inti**: `GeolocationService` (Haversine + radius), `AttendancePhotoService` (upload privat + validasi), `AttendanceService` (clock in/out, applySchedule, aggregate).
5. **Fase 4 — Kontroller & API**: `AttendanceController` (clock in/out, index), `FormRequest`, registrasi route.
6. **Fase 5 — Frontend & Menu**: halaman Vue (Clock + Index), menu sidebar `attendance`, seeder `role_menu_permissions`, share data.
7. **Fase 6 — Integrasi Payroll & Command**: kontrak `AttendanceService::aggregate()` ke payroll + command `attendance:from-cash-shifts`.
8. **Fase 7 — Pengaturan Admin Absensi**: halaman set lokasi kantor (lat/lng), radius, dan toggle kebijakan (selfie/geolokasi/lembur).
9. **Fase 8 — CRUD Jadwal Kerja, Cuti & Lembur**: controller + route + halaman Vue untuk `work_schedules`, `leave_requests`, `overtime_requests`.
10. **Fase 9 — Uji End-to-End**: beli paket → buka `/attendance/settings` → set lokasi → check-in/check-out, termasuk blokir di luar radius.
11. **Fase 10 — Gating Addon Berbayar Absensi + Payroll**: migrasi flag `stores.attendance_payroll_until` + model pricing, service addon, checkout/submit, konfirmasi admin, form harga super admin, gating middleware & halaman pengaturan berbasis pembelian.

---

## Detail Per Tahap

### Fase 0 — Persiapan & Dokumen Tracking

- **Status:** ✅ Selesai
- **Penanggung jawab:** Asisten AI (TraeCode)
- **Tenggat awal:** 2026-08-15
- **Waktu mulai:** 2026-08-15 18:40 WITA
- **Waktu selesai aktual:** 2026-08-15 18:45 WITA

**Hasil:**
- Mempelajari rencana `ATTENDANCE_PAYROLL_INTEGRATION_PLAN.md` (v2.0) dan konvensi kode eksisting (Laravel 12, Inertia/Vue 3, pola `LoyaltySettings` + `stores.settings`, route di `routes/web.php`, middleware di `bootstrap/app.php`, menu `role_menu_permissions`).
- Menetapkan keputusan arsitektur awal:
  - Pendekatan **modular monolith** (modul di dalam aplikasi Laravel yang sama, bukan microservice).
  - Direktori modul: `app/Modules/Attendance/` (namespace `App\Modules\Attendance`, tanpa perubahan `composer.json`).
  - Migrasi tetap di `database/migrations/` (konvensi standar, agar `migrate`/`rollback` prediktabel).
  - Konfigurasi absensi disimpan di `stores.settings['attendance']` (konsisten dengan `LoyaltySettings`, tanpa mengubah skema `stores`).
- Membuat dokumen progres ini beserta daftar 7 tahapan, kolom status/PIC/tenggat.

**Hambatan & solusi:**
- Repo memiliki banyak file untracked (basis kode belum sepenuhnya ter-commit). Solusi: hanya men-`git add` file milik modul absensi secara spesifik, bukan `git add -A`/`git add .`.

**Artefak:**
- [ATTENDANCE_IMPLEMENTATION_PROGRESS.md](./ATTENDANCE_IMPLEMENTATION_PROGRESS.md)
- [ATTENDANCE_PAYROLL_INTEGRATION_PLAN.md](./ATTENDANCE_PAYROLL_INTEGRATION_PLAN.md)

### Fase 1 — Fondasi Addon

- **Status:** ✅ Selesai
- **Penanggung jawab:** Asisten AI (TraeCode)
- **Tenggat awal:** 2026-08-15
- **Waktu mulai:** 2026-08-15 18:45 WITA
- **Waktu selesai aktual:** 2026-08-15 18:48 WITA

**Hasil:**
- Membuat struktur modul `app/Modules/Attendance/` (namespace `App\Modules\Attendance`) — terisolasi dari aplikasi utama.
- `Support/AttendanceSettings.php`: konfigurasi tersimpan di `stores.settings['attendance']` (pola `LoyaltySettings`), default radius 100m, selfie & geolokasi wajib, grace 15 menit, lembur wajib approval.
- `Http/Middleware/EnsureAttendanceEnabled.php`: gerbang aktivasi — redirect bila addon nonaktif.
- Registrasi alias middleware `attendance.enabled` di `bootstrap/app.php`.
- `routes/attendance.php` (scaffold) di-require dari `routes/web.php`.
- Flag `attendance_enabled` + `attendance_settings` dibagikan ke frontend via `HandleInertiaRequests`.

**Hambatan & solusi:**
- Pemilihan lokasi konfigurasi: dipilih `stores.settings['attendance']` (JSON) alih-alih menambah kolom `latitude/longitude` di `stores`, agar modul tetap swakelola tanpa mengubah skema inti.

**Artefak:**
- `app/Modules/Attendance/Support/AttendanceSettings.php`
- `app/Modules/Attendance/Http/Middleware/EnsureAttendanceEnabled.php`
- `routes/attendance.php`
- `bootstrap/app.php` (alias middleware)
- `app/Http/Middleware/HandleInertiaRequests.php` (share flag)
- `routes/web.php` (require route modul)

### Fase 2 — Model Data

- **Status:** ✅ Selesai
- **Penanggung jawab:** Asisten AI (TraeCode)
- **Tenggat awal:** 2026-08-16
- **Waktu mulai:** 2026-08-15 18:48 WITA
- **Waktu selesai aktual:** 2026-08-15 18:51 WITA

**Hasil:**
- 6 migration baru di `database/migrations/` (konvensi standar Laravel, `foreignId()->constrained()`):
  - `2026_08_15_000001_create_attendance_employees_table.php` — tabel `employees` (link opsional ke `users`).
  - `2026_08_15_000002_create_attendance_work_schedules_table.php` — `work_schedules` (jadwal, `work_days` JSON).
  - `2026_08_15_000003_create_attendances_table.php` — `attendances` (clock in/out + lat/lng/akurasi + status/source).
  - `2026_08_15_000004_create_attendance_photos_table.php` — `attendance_photos` (bukti selfie, disk privat).
  - `2026_08_15_000005_create_attendance_leave_requests_table.php` — `leave_requests`.
  - `2026_08_15_000006_create_attendance_overtime_requests_table.php` — `overtime_requests`.
- 6 model Eloquent di `app/Modules/Attendance/Models/` dengan relasi & `casts()` lengkap.

**Hambatan & solusi:**
- Tidak ada hambatan berarti. Penamaan tabel memakai awalan `attendance_` untuk `employees`/`work_schedules`/`leave_requests`/`overtime_requests` guna menghindari bentrok nama generik dengan modul lain; `attendances`/`attendance_photos` memakai nama default.

**Artefak:**
- `database/migrations/2026_08_15_000001_*` s.d. `000006_*`
- `app/Modules/Attendance/Models/{Employee,WorkSchedule,Attendance,AttendancePhoto,LeaveRequest,OvertimeRequest}.php`

### Fase 3 — Layanan Inti

- **Status:** ✅ Selesai
- **Penanggung jawab:** Asisten AI (TraeCode)
- **Tenggat awal:** 2026-08-16
- **Waktu mulai:** 2026-08-15 18:51 WITA
- **Waktu selesai aktual:** 2026-08-15 18:54 WITA

**Hasil:**
- `GeolocationService`: Haversine distance + validasi radius (server-side).
- `AttendancePhotoService`: upload foto selfie ke disk privat `local` (storage/app/private), validasi mime (jpeg/png/webp) & maks 5 MB.
- `AttendanceService`: clock in/out (transaksi + lock baris, anti double), validasi geolokasi & selfie sesuai kebijakan toko, `applySchedule()` untuk telat/pulang-cepat/jam-kerja/lembur, `aggregate()` sebagai kontrak data ke payroll.

**Hambatan & solusi:**
- Kesalahan desain awal yang diletakkan kolom `clock_in_photo_id`/`clock_out_photo_id` di `attendances` dibatalkan; bukti foto cukup direlasikan lewat tabel `attendance_photos` (relasi `attendance_id` + `type`), lebih sederhana dan bebas duplikasi kolom.

**Artefak:**
- `app/Modules/Attendance/Services/GeolocationService.php`
- `app/Modules/Attendance/Services/AttendancePhotoService.php`
- `app/Modules/Attendance/Services/AttendanceService.php`

### Fase 4 — Kontroller & API

- **Status:** ✅ Selesai
- **Penanggung jawab:** Asisten AI (TraeCode)
- **Tenggat awal:** 2026-08-17
- **Waktu mulai:** 2026-08-15 18:54 WITA
- **Waktu selesai aktual:** 2026-08-15 18:55 WITA

**Hasil:**
- `AttendanceController`: halaman `index` (status hari ini + ringkasan), `clockIn`, `clockOut` mandiri karyawan.
- Validasi geolokasi (lat/lng/akurasi) & foto via `$request->validate()` inline (konsisten dengan `LoyaltyController`).
- Route `attendance.index`, `attendance.clock-in`, `attendance.clock-out` terdaftar di `routes/attendance.php`.

**Hambatan & solusi:**
- Repo tidak memakai `FormRequest` (0 file di `app/Http/Requests`); solusi: validasi inline `$request->validate()` mengikuti pola controller eksisting, bukan menambah abstraksi baru.

**Artefak:**
- `app/Modules/Attendance/Http/Controllers/AttendanceController.php`
- `routes/attendance.php`

### Fase 5 — Frontend & Menu

- **Status:** ✅ Selesai
- **Penanggung jawab:** Asisten AI (TraeCode)
- **Tenggat awal:** 2026-08-18
- **Waktu mulai:** 2026-08-15 18:55 WITA
- **Waktu selesai aktual:** 2026-08-15 18:58 WITA

**Hasil:**
- Halaman `resources/js/pages/Attendance/Index.vue`: status hari ini, kamera selfie (`getUserMedia` + capture canvas), geolokasi (`navigator.geolocation`), tombol check-in/check-out via `useForm`.
- Menu "Absensi" ditambahkan ke `MainMenu.vue` (seksi Laporan & Keuangan, `backofficeOnly`, `attendanceOnly`).
- Filter `attendanceOnly` memakai `attendance_enabled` dari `HandleInertiaRequests`.
- `RoleMenuPermissionSeeder`: menu `attendance` ditambahkan untuk role `admin`, `manager`, `owner`.

**Hambatan & solusi:**
- Menu Vue pakai konvensi flag khusus per modul (mis. `queueModuleOnly`); solusi: tambah flag `attendanceOnly` mengikuti pola yang sama, bukan mekanisme baru.

**Artefak:**
- `resources/js/pages/Attendance/Index.vue`
- `resources/js/components/MainMenu.vue`
- `database/seeders/RoleMenuPermissionSeeder.php`

### Fase 6 — Integrasi Payroll & Command

- **Status:** ✅ Selesai
- **Penanggung jawab:** Asisten AI (TraeCode)
- **Tenggat awal:** 2026-08-19
- **Waktu mulai:** 2026-08-15 18:58 WITA
- **Waktu selesai aktual:** 2026-08-15 19:02 WITA

**Hasil:**
- `AttendanceService::generateFromCashShifts()`: konversi `cash_shifts → attendances` idempoten, `work_date` mengikuti tanggal mulai shift pada timezone toko, data manual tidak ditimpa.
- Command `attendance:from-cash-shifts` (opsi `--store`, `--date`) untuk backfill harian.
- Verifikasi: command terdaftar di `php artisan list`, 3 route absensi terdaftar setelah `route:clear`, 6 migration terdaftar di `migrate:status`.
- Kontrak integrasi payroll tersedia via `AttendanceService::aggregate()` (dibuat di Fase 3); `PayrollService` akan memakainya saat modul payroll diimplementasikan.

**Hambatan & solusi:**
- Route baru tidak muncul di `route:list` karena route cache aktif; solusi: `php artisan route:clear`.
- Modul `PayrollService` belum ada di kode (payroll masih berupa rencana); solusi: integrasi payroll dilakukan lewat kontrak `aggregate()` dan akan dihubungkan saat payroll dibangun.

**Artefak:**
- `app/Modules/Attendance/Services/AttendanceService.php` (method `generateFromCashShifts`)
- `app/Console/Commands/GenerateAttendanceFromCashShifts.php`

### Fase 7 — Pengaturan Admin Absensi

- **Status:** ✅ Selesai
- **Penanggung jawab:** Asisten AI (TraeCode)
- **Tenggat awal:** 2026-08-20
- **Waktu mulai:** 2026-08-15 19:20 WITA
- **Waktu selesai aktual:** 2026-08-15 19:30 WITA

**Hasil:**
- `AttendanceSettingsController`: halaman `settings()` (render Inertia) + `update()` (validasi inline, guard role admin/manager/owner).
- Route `attendance.settings` (GET) & `attendance.settings.update` (POST) terdaftar; route pengaturan sengaja berada di luar middleware `attendance.enabled` agar pemilik bisa membuka pengaturan dan mengaktifkan modul saat masih nonaktif.
- `Settings.vue`: form lokasi kantor (lat/lng, radius meter, tombol "Gunakan lokasi saya" via `navigator.geolocation`) + toggle kebijakan (wajib selfie, wajib geolokasi, toleransi terlambat, lembur wajib approval) + toggle aktivasi modul.
- Verifikasi: `php artisan route:list --name=attendance` menampilkan 5 route (index, clock-in, clock-out, settings, settings.update); tidak ada diagnostik error pada controller, route, dan halaman Vue.

**Hambatan & solusi:**
- Komponen `Switch` tidak tersedia di repo; solusi: pakai `Checkbox` (`@/components/ui/checkbox`) untuk semua toggle boolean.
- Route pengaturan awalnya berada di belakang middleware `attendance.enabled`, sehingga pemilik tidak bisa membuka pengaturan saat modul belum aktif (deadlock aktivasi); solusi: pindahkan route pengaturan ke grup tanpa `attendance.enabled` dan tambah toggle `enabled` di form + validasi.

**Artefak:**
- `app/Modules/Attendance/Http/Controllers/AttendanceSettingsController.php`
- `routes/attendance.php`
- `resources/js/pages/Attendance/Settings.vue`

### Fase 8 — CRUD Jadwal Kerja, Cuti & Lembur

- **Status:** ✅ Selesai
- **Penanggung jawab:** Asisten AI (TraeCode)
- **Tenggat awal:** 2026-08-20
- **Waktu mulai:** 2026-08-15 19:30 WITA
- **Waktu selesai aktual:** 2026-08-15 20:05 WITA

**Hasil:**
- `AdminAttendanceController` (abstract): helper `currentStore()` + `authorizeAdmin()` (guard role admin/manager/owner) dipakai semua controller CRUD.
- `WorkScheduleController`: index/store/update/destroy jadwal kerja (`work_days` 1–7, `shift_start`/`shift_end` `H:i`, `break_minutes`, `valid_from`/`valid_to`, `is_active`).
- `LeaveRequestController`: index/store/update/destroy cuti (tipe, rentang tanggal, alasan, approval `approved`/`rejected` + `approved_by`/`approved_at`).
- `OvertimeRequestController`: index/store/update/destroy lembur (`work_date`, `hours` 0.5–24, alasan, approval).
- `ensureOwned()` guard kepemilikan `store_id` (abort 404 bila beda toko).
- Route resource `schedules`, `leaves`, `overtimes` (`only: index, store, update, destroy`) di belakang middleware `attendance.enabled`.
- `AttendanceNav.vue`: sub-navigasi Absensi/Jadwal/Cuti/Lembur/Pengaturan, dipakai semua halaman Attendance.
- Halaman Vue: `Schedules.vue`, `Leaves.vue`, `Overtimes.vue` (form + tabel, `useForm`, `router.delete`).
- Verifikasi: `php artisan route:list --path=attendance` menampilkan 17 route; `npm run build` sukses (asset `Schedules`, `Leaves`, `Overtimes`, `AttendanceNav` terbentuk).

**Hambatan & solusi:**
- Tidak ada hambatan berarti; `$fillable` setiap model diverifikasi cocok dengan field hasil validasi controller sebelum `create()`/`update()`.

**Artefak:**
- `app/Modules/Attendance/Http/Controllers/AdminAttendanceController.php`
- `app/Modules/Attendance/Http/Controllers/WorkScheduleController.php`
- `app/Modules/Attendance/Http/Controllers/LeaveRequestController.php`
- `app/Modules/Attendance/Http/Controllers/OvertimeRequestController.php`
- `routes/attendance.php`
- `resources/js/components/AttendanceNav.vue`
- `resources/js/pages/Attendance/{Schedules,Leaves,Overtimes}.vue`

### Fase 9 — Uji End-to-End

- **Status:** ✅ Selesai
- **Penanggung jawab:** Asisten AI (TraeCode)
- **Tenggat awal:** 2026-08-20
- **Waktu mulai:** 2026-08-15 20:05 WITA
- **Waktu selesai aktual:** 2026-08-15 20:15 WITA

**Hasil:**
- `tests/Feature/AttendanceEndToEndTest.php` dengan dua skenario:
  1. Alur lengkap: paket belum dibeli → buka `/attendance/settings` (status belum aktif) → simulasi beli paket (`attendance_payroll_until` masa depan) + set lokasi kantor → check-in dari dalam radius → check-out; asersi status `present`, `clock_in`/`clock_out` terisi.
  2. Check-in diblokir saat berada di luar radius (koordinat ±5 km), tidak ada attendance terbuat.
- Hasil uji: `2 passed (24 assertions)`.

**Hambatan & solusi:**
- Status absensi awalnya diuji sebagai `on_duty`/`off_duty`, tetapi implementasi memakai konstanta `present`/`late`/`absent`; solusi: samakan asersi test ke `present`.
- `applySchedule()` tidak mengisi `status` bila karyawan tidak punya jadwal aktif (status jadi `null`); solusi: isi `status = STATUS_PRESENT` pada cabang tanpa jadwal agar check-in selalu menghasilkan status valid.
- Test sempat gagal dengan error CSRF 419 di lingkungan test; solusi: `beforeEach` memanggil `withoutMiddleware(ValidateCsrfToken::class)`.

**Artefak:**
- `tests/Feature/AttendanceEndToEndTest.php`
- `app/Modules/Attendance/Services/AttendanceService.php` (isi `status` pada cabang tanpa jadwal)

### Fase 10 — Gating Addon Berbayar Absensi + Payroll

- **Status:** ✅ Selesai
- **Penanggung jawab:** Asisten AI (TraeCode)
- **Tenggat awal:** 2026-08-15
- **Waktu mulai:** 2026-08-15 20:30 WITA
- **Waktu selesai aktual:** 2026-08-15 21:00 WITA

**Hasil:**
- Migrasi `add_attendance_payroll_until_to_stores_table` (kolom `stores.attendance_payroll_until`, nullable datetime) + `create_attendance_payroll_pricing_table` (harga bulanan 49000 / tahunan 490000).
- `Store`: `attendance_payroll_until` masuk `$fillable` + cast `datetime`.
- Model single-row `AttendancePayrollPricing` (`current()` + `toPayload()`).
- `AttendancePayrollAddOnService`: `priceFor()`, `isPurchased()`, `createPayment()` (anti double-submit pending, durasi 30/365), `applyConfirmedAddOn()` (extend bila masih aktif).
- `SubscriptionController`: payload `attendancePayrollInfo`, `checkoutAttendancePayroll()`, `submitAttendancePayroll()`.
- `SuperAdminController`: branch konfirmasi `attendance_payroll_addon`, `updateAttendancePayrollPricing()`.
- Route: `subscription.checkout-attendance-payroll`, `subscription.buy-attendance-payroll`, `superadmin.attendance-payroll-pricing.update`.
- Frontend: kartu addon di `Subscription/Index.vue`, `AttendancePayrollCheckout.vue`, form harga di `SuperAdmin/Dashboard.vue`, status paket + CTA beli di `Attendance/Settings.vue`.
- Gating: `AttendanceSettings::enabled()` = pembelian (`attendance_payroll_until >= now()`) DAN toggle toko (`stores.settings['attendance']['enabled']`). Toggle manual dipindah ke `/settings/profile` (konsisten dengan fitur resep/produksi/antrian). `EnsureAttendanceEnabled` redirect ke `subscription.index` bila belum beli, atau `profile.edit` bila belum aktifkan toggle.
- Uji: `AttendancePayrollAddOnServiceTest` (5 passed) + `AttendanceEndToEndTest` (2 passed).

**Hambatan & solusi:**
- Test end-to-end sempat gagal karena relasi `user->store` ter-cache sebelum flag `attendance_payroll_until` di-update; solusi: `$user->unsetRelation('store')` setelah update flag di test (di produksi tiap request memuat store baru).

**Artefak:**
- `database/migrations/2026_08_15_000001_add_attendance_payroll_until_to_stores_table.php`
- `database/migrations/2026_08_15_000002_create_attendance_payroll_pricing_table.php`
- `app/Models/Store.php`
- `app/Models/AttendancePayrollPricing.php`
- `app/Services/AttendancePayrollAddOnService.php`
- `app/Http/Controllers/SubscriptionController.php`
- `app/Http/Controllers/SuperAdminController.php`
- `routes/web.php`
- `app/Modules/Attendance/Support/AttendanceSettings.php`
- `app/Modules/Attendance/Http/Middleware/EnsureAttendanceEnabled.php`
- `app/Modules/Attendance/Http/Controllers/AttendanceSettingsController.php`
- `resources/js/pages/Subscription/Index.vue`
- `resources/js/pages/Subscription/AttendancePayrollCheckout.vue`
- `resources/js/pages/SuperAdmin/Dashboard.vue`
- `resources/js/pages/Attendance/Settings.vue`
- `tests/Feature/AttendancePayrollAddOnServiceTest.php`
- `tests/Feature/AttendanceEndToEndTest.php`
