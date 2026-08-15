# Rencana Implementasi Modul Absensi (Addon Terpisah) + Integrasi Payroll

## 📋 Overview

Dokumen ini menggantikan [versi sebelumnya](./ATTENDANCE_PAYROLL_INTEGRATION_PLAN.md) dan memperluasnya menjadi rencana **modul absensi yang berdiri sendiri sebagai addon**, terpisah dari modul **Shift Kasir**, namun tetap terhubung ke modul **Payroll** saat diaktifkan.

Modul ini dirancang mengikuti standar aplikasi absensi profesional dengan tiga kemampuan utama:

1. **Selfie sebagai bukti kehadiran** — karyawan wajib unggah foto selfie saat check-in & check-out.
2. **Validasi geolokasi** — absensi ditolak bila karyawan berada di luar radius lokasi kantor/outlet yang ditetapkan admin.
3. **Addon terpisah & attivasi on-demand** — struktur modul terisolasi dari aplikasi kasir utama, hanya aktif saat pemilik usaha mengaktifkan fitur payroll/absensi, dan otomatis tersinkron ke payroll.

> ⚠️ **Status:** Rencana (living document). Belum ada kode yang diubah.

---

## 🔍 Kondisi Codebase Saat Ini (Fakta)

| Komponen | Lokasi | Implikasi |
|----------|--------|-----------|
| Monolit Laravel 12 + Inertia/Vue 3 | root project | Addon paling cocok sebagai **modul dalam monolit**, bukan microservice terpisah |
| Feature flag via JSON | `stores.settings` + `app/Support/*` (LoyaltySettings, FnBFeatures, LaundryFeatures) | Pola aktivasi addon ikut pola ini |
| Gating paket | `StorePlanLimits::allowsAdvancedRetailFeatures()` | Absensi/payroll bisa di-gating ke paket berbayar |
| Share ke frontend | `HandleInertiaRequests::share()` | Tambah `attendance_enabled`, `attendance_settings` di sini |
| Akses menu | `EnsureMenuAccess` + `role_menu_permissions` + `MainMenu.vue` | Tambah `menuKey = attendance` |
| File storage | disk `local`/`public`/`s3` | Foto selfie → disk **privat** (bukan public) |
| Lokasi toko | `stores.address` (tanpa lat/lng) | Perlu tambah `latitude`, `longitude` |
| Shift kasir | `cash_shifts` (kasir saja, GrowUp) | Bukan absensi umum — dipakai sebagai **salah satu sumber** data |

---

## 🏛️ Keputusan Arsitektur: "Addon Terpisah"

### Rekomendasi: Modular Monolith (Package/Module dalam aplikasi yang sama)

Modul absensi dibuat sebagai **modul swakelola (self-contained)** dengan namespace sendiri, provider sendiri, route/migrasi/Vue sendiri — namun tetap berjalan dalam satu proses Laravel yang sama. Aktivasi dikontrol oleh feature flag.

**Mengapa ini paling pas:**
- Skala UMKM 1–100 karyawan tidak butuh biaya/kompleksitas microservice.
- Bisa memakai ulang auth, `stores`, `users`, `store_user`, `subscription` yang sudah ada.
- Sinkronisasi ke payroll menjadi transaksi DB biasa (konsisten, tanpa API/event bus antar layanan).
- Konsisten dengan pola feature-module yang sudah ada (Loyalty, Laundry, FnB).

### Alternatif (untuk pertimbangan, belum direkomendasikan sekarang)

| Pendekatan | Kelebihan | Kekurangan |
|-----------|-----------|-----------|
| **Microservice terpisah** (API + DB sendiri) | Isolasi penuh, bisa di-scale mandiri | Overkill untuk UMKM; perlu sync/event bus, auth lintas layanan, biaya infra ganda |
| **Composer package** (vendor/package) | Versi & distribusi terpusat | Repo ini belum memakai mekanisme package; lebih lambat iterasi |

> **Keputusan:** Pakai **Modular Monolith**. Struktur "terpisah" diwujudkan lewat namespace, direktori, dan ServiceProvider sendiri — bukan deployment terpisah.

---

## 🧭 Prinsip Desain (Best Practice)

1. **Isolasi modul** — semua kode absensi berada di namespace & direktori sendiri; tidak menyentuh kode kasir.
2. **Aktivasi on-demand** — saat nonaktif: tidak ada route, menu, migrasi aktif, maupun overhead query.
3. **Data mentah = sumber kebenaran** — simpan clock in/out mentah + metadata (foto, koordinat, akurasi); field turunan (jam kerja, telat, lembur) *recomputed* & disimpan sebagai snapshot.
4. **Validasi di server** — geolokasi & foto diperiksa ulang di backend; jangan percaya input klien.
5. **Idempotensi & dedup** — konversi `cash_shifts → attendances` aman dijalankan ulang (`updateOrCreate` + `cash_shift_id`).
6. **Berbasis jadwal** — telat/lembur dihitung dari `work_schedules`, bukan asumsi jam seragam.
7. **Lembur wajib approval** (default) — sesuai PP 35/2021 & kebutuhan audit.
8. **Snapshot payroll** — saat `runPeriod()`, agregat absensi dibekukan ke `payroll_runs`, sehingga ralat absensi tidak mengubah gaji yang sudah dihitung.
9. **Perlindungan data pribadi (UU PDP)** — foto selfie & geolokasi adalah data pribadi; disimpan di disk privat, diakses via URL bertanda tangan/route terautentikasi.

---

## 📁 Struktur Modul (Direktori yang Diusulkan)

```
modules/Attendance/
├── src/
│   ├── AttendanceServiceProvider.php      # registrasi route, migrasi, konfigurasi
│   ├── Models/
│   │   ├── Attendance.php
│   │   ├── AttendancePhoto.php
│   │   ├── WorkSchedule.php
│   │   ├── LeaveRequest.php
│   │   └── OvertimeRequest.php
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── AttendanceController.php
│   │   │   ├── AttendancePhotoController.php
│   │   │   ├── AttendanceScheduleController.php
│   │   │   ├── LeaveRequestController.php
│   │   │   └── OvertimeRequestController.php
│   │   ├── Middleware/
│   │   │   └── EnsureAttendanceEnabled.php
│   │   └── Requests/
│   │       ├── ClockInRequest.php
│   │       └── ClockOutRequest.php
│   ├── Services/
│   │   ├── AttendanceService.php          # clock in/out, applySchedule, aggregate
│   │   ├── GeolocationService.php         # haversine + radius
│   │   └── AttendancePhotoService.php     # upload, validasi, penyimpanan privat
│   ├── Support/
│   │   └── AttendanceSettings.php         # stores.settings['attendance']
│   ├── Console/
│   │   └── GenerateAttendanceFromCashShifts.php
│   └── database/
│       └── migrations/
├── routes/
│   └── attendance.php
├── resources/
│   └── js/pages/Attendance/              # Index, Clock, Schedules, Leaves, Overtimes
└── tests/
```

Registrasi modul (contoh):

```php
// modules/Attendance/src/AttendanceServiceProvider.php
public function boot(): void
{
    if (! AttendanceSettings::isEnabled($this->activeStore())) {
        return; // addon nonaktif → tidak ada route/menu/migrasi
    }

    $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    $this->loadRoutesFrom(__DIR__.'/../../routes/attendance.php');
}
```

> **Catatan:** Autoload namespace `Modules\Attendance\` ditambahkan ke `composer.json` (`psr-4`). Jika ingin tetap sederhana, direktori `modules/Attendance` bisa dipindah ke `app/Modules/Attendance` — keputusan final disesuaikan preferensi tim.

---

## 🎚️ Mekanisme Aktivasi (On-Demand)

### Sumber flag aktivasi

| Flag | Lokasi | Makna |
|------|--------|-------|
| `attendance_enabled` | `stores.settings['attendance']['enabled']` | Addon absensi aktif per outlet |
| `payroll_enabled` | `stores.settings['payroll']['enabled']` | Modul payroll aktif (dari rencana payroll) |

### Aturan aktivasi

- Absensi **hanya tersedia** jika `payroll_enabled = true` (sesuai kebutuhan: "diaktifkan hanya jika pemilik butuh payroll & absensi").
- Bisa di-gating lebih lanjut ke paket berbayar: `StorePlanLimits::allowsAdvancedRetailFeatures($store)` atau mekanisme addon berbayar terpisah.

### Efek saat nonaktif

- `AttendanceServiceProvider` tidak mendaftarkan route/migrasi.
- Menu "Absensi" tidak muncul (filter di `MainMenu.vue` + `allowed_menus`).
- `HandleInertiaRequests` tidak mengirim data absensi ke frontend.
- Tidak ada query absensi dijalankan di dashboard.

### Konfigurasi (dibagi ke frontend)

```php
// HandleInertiaRequests::share()
'attendance_enabled' => $user?->store
    ? AttendanceSettings::enabled($user->store)
    : false,
'attendance_settings' => $user?->store
    ? AttendanceSettings::forFrontend($user->store)  // radius, toggles (tanpa data sensitif)
    : null,
```

---

## 🗄️ Model Data

### 1. Lokasi & Radius (lokasi kantor/outlet)

Tambahkan kolom lokasi ke `stores` (lokasi fisik outlet adalah properti toko):

```sql
ALTER TABLE stores
    ADD COLUMN latitude DECIMAL(10, 7) NULL,
    ADD COLUMN longitude DECIMAL(10, 7) NULL;
```

Konfigurasi radius & kebijakan absensi disimpan di `stores.settings['attendance']` (mengikuti pola `LoyaltySettings`):

```php
// Modules\Attendance\src\Support\AttendanceSettings.php
public const DEFAULTS = [
    'enabled'                     => false,
    'radius_meters'               => 100,   // radius kantor
    'require_selfie'              => true,  // wajib foto
    'require_geolocation'         => true,  // wajib lokasi
    'grace_minutes'               => 15,
    'overtime_requires_approval'  => true,
    'late_deduction_method'       => 'none', // none | per_minute | per_day
    'late_deduction_rate'         => 0,
    'auto_generate_from_cash_shift' => true,
];
```

### 2. `work_schedules` — Jadwal Kerja

```sql
CREATE TABLE work_schedules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id BIGINT UNSIGNED NOT NULL,
    employee_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    work_days JSON NOT NULL,                  -- [1,2,3,4,5] (1=Senin)
    shift_start TIME NOT NULL,
    shift_end TIME NOT NULL,
    break_minutes INT NOT NULL DEFAULT 60,
    valid_from DATE NULL,
    valid_to DATE NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    INDEX idx_employee (employee_id),
    INDEX idx_store (store_id)
);
```

### 3. `attendances` — Kehadiran + Geolokasi + Foto

```sql
CREATE TABLE attendances (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id BIGINT UNSIGNED NOT NULL,
    employee_id BIGINT UNSIGNED NOT NULL,
    work_date DATE NOT NULL,
    schedule_id BIGINT UNSIGNED NULL,

    clock_in DATETIME NULL,
    clock_in_latitude DECIMAL(10,7) NULL,
    clock_in_longitude DECIMAL(10,7) NULL,
    clock_in_accuracy DECIMAL(8,2) NULL,      -- meter (dari GPS)
    clock_in_photo_id BIGINT UNSIGNED NULL,

    clock_out DATETIME NULL,
    clock_out_latitude DECIMAL(10,7) NULL,
    clock_out_longitude DECIMAL(10,7) NULL,
    clock_out_accuracy DECIMAL(8,2) NULL,
    clock_out_photo_id BIGINT UNSIGNED NULL,

    status VARCHAR(20) DEFAULT 'present',      -- present, late, absent, leave, sick, holiday, day_off
    source VARCHAR(20) DEFAULT 'manual',       -- manual, cash_shift, device, imported
    cash_shift_id BIGINT UNSIGNED NULL,
    late_minutes INT NOT NULL DEFAULT 0,
    early_leave_minutes INT NOT NULL DEFAULT 0,
    hours_worked DECIMAL(6,2) NULL,
    overtime_hours DECIMAL(6,2) NULL,
    notes VARCHAR(255) NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    UNIQUE KEY unique_employee_date (employee_id, work_date),
    INDEX idx_store_date (store_id, work_date),
    INDEX idx_shift (cash_shift_id)
);
```

### 4. `attendance_photos` — Bukti Foto (terpisah, disk privat)

```sql
CREATE TABLE attendance_photos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id BIGINT UNSIGNED NOT NULL,
    attendance_id BIGINT UNSIGNED NOT NULL,
    employee_id BIGINT UNSIGNED NOT NULL,
    type VARCHAR(10) NOT NULL,                 -- in | out
    path VARCHAR(500) NOT NULL,                -- path disk privat
    mime VARCHAR(50) NOT NULL,
    size_bytes INT NOT NULL,
    captured_at TIMESTAMP NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    INDEX idx_attendance (attendance_id),
    INDEX idx_employee_date (employee_id, captured_at)
);
```

### 5. `leave_requests` & `overtime_requests`

```sql
CREATE TABLE leave_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id BIGINT UNSIGNED NOT NULL,
    employee_id BIGINT UNSIGNED NOT NULL,
    leave_type VARCHAR(30) NOT NULL,           -- annual, sick, maternity, menstruation, unpaid, other
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    status VARCHAR(20) DEFAULT 'pending',      -- pending, approved, rejected, cancelled
    reason TEXT NULL,
    approved_by BIGINT UNSIGNED NULL,
    approved_at TIMESTAMP NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    INDEX idx_employee_dates (employee_id, start_date, end_date)
);

CREATE TABLE overtime_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id BIGINT UNSIGNED NOT NULL,
    employee_id BIGINT UNSIGNED NOT NULL,
    work_date DATE NOT NULL,
    hours DECIMAL(5,2) NOT NULL,
    status VARCHAR(20) DEFAULT 'pending',
    reason TEXT NULL,
    approved_by BIGINT UNSIGNED NULL,
    approved_at TIMESTAMP NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    INDEX idx_employee_date (employee_id, work_date)
);
```

---

## 📸 Fitur 1: Selfie sebagai Bukti Kehadiran

### Alur

```
Karyawan tap "Check-in"
  → buka kamera (getUserMedia / input capture)
  → ambil foto + koordinat + akurasi
  → kirim ke AttendanceController
  → AttendancePhotoService: validasi + simpan ke disk PRIVAT
  → AttendanceService: catat clock_in (photo_id, koordinat)
```

### Best Practice

1. **Penyimpanan privat** — foto ke disk `local` (atau `s3` private), **bukan** `public`. Data biometrik/pribadi tidak boleh diakses publik.
2. **Akses foto** — lewat route terautentikasi + policy (hanya admin/manajer/owner & karyawan bersangkutan), atau URL bertanda tangan (temporary signed URL) untuk `s3`.
3. **Validasi file** — mime `jpeg/png/webp`, maks 2–5 MB, dan batasi dimensi (misal maks 2000px) untuk hemat penyimpanan.
4. **Metadata foto** — simpan `captured_at` & kaitkan ke `attendance_id`, sehingga satu foto tidak bisa dipakai ulang di hari lain.
5. **Anti-spoofing (opsional, fase lanjutan)** — liveness detection / timestamp server / hash file untuk mencegah foto lama dipakai ulang. Jangan diterapkan di MVP bila memberatkan.

---

## 📍 Fitur 2: Validasi Geolokasi (Radius Kantor)

### Alur

```
Admin set lokasi outlet (lat/lng) + radius_meters
  → frontend minta navigator.geolocation.getCurrentPosition()
  → kirim lat/lng/accuracy ke backend
  → GeolocationService::isWithinRadius()  (Haversine)
  → jika di luar radius → tolak (422) + pesan jelas
  → jika di dalam → lanjutkan absensi
```

### Perhitungan (server-side, Haversine)

```php
// Modules\Attendance\src\Services\GeolocationService.php
public function distanceMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earthRadius = 6371000; // meter
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

    return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

public function isWithinRadius(Store $store, float $lat, float $lon): bool
{
    $settings = AttendanceSettings::all($store);
    $distance = $this->distanceMeters(
        (float) $store->latitude,
        (float) $store->longitude,
        $lat,
        $lon,
    );

    return $distance <= (float) $settings['radius_meters'];
}
```

### Best Practice

1. **Validasi di server** — koordinat klien bisa dipalsukan; server selalu menghitung ulang radius.
2. **Catat `accuracy`** — GPS dengan akurasi buruk (misal > 100 m) sebaiknya dipertimbangkan/ditolak atau diberi peringatan.
3. **Jangan blokir total tanpa jalur darurat** — sediakan fitur **admin override** (absensi manual dengan alasan + audit trail) untuk kondisi GPS gagal/offline.
4. **Lokasi outlet** — isi `stores.latitude/longitude` saat setup; tampilkan peta pratinjau (Leaflet/Mapbox) di halaman pengaturan.
5. **Privasi** — hanya simpan koordinat saat clock in/out; jangan tracking lokasi terus-menerus.

---

## 🔗 Fitur 3: Struktur Terpisah & Sinkronisasi Payroll

### Alur data end-to-end

```
[Sumber: manual selfie+GPS | cash_shift | admin]
            │  idempotent, unique (employee, work_date)
            ▼
      attendances (+ foto + koordinat)
            │  schedule-driven + approval lembur + cuti
            ▼
   AttendanceService::aggregate(employee, period)
            │
            ▼
   PayrollService::calculateOvertime() / calculateDeductions()
            │
            ▼
   payroll_runs.attendance_summary (snapshot JSON)
   payroll_items (reference_type = attendance/overtime)
            │
            ▼
   Payroll period → Locked
```

### Kontrak data (Attendance → Payroll)

```php
// AttendanceService::aggregate()
[
    'work_days'      => int,
    'present_days'   => int,
    'late_days'      => int,
    'absent_days'    => int,
    'leave_days'     => int,
    'total_hours'    => float,
    'total_overtime' => float,   // hanya yang disetujui
    'total_late_min' => int,
]
```

- `PayrollService` membaca agregat ini, **bukan** query tabel absensi langsung, sehingga batas modul tetap jelas.
- Saat `runPeriod()`, hasil dibekukan ke `attendance_summary` + `payroll_items`; periode terkunci tidak berubah meski absensi di-ralat kemudian.

---

## 🎨 Arsitektur Frontend

### Halaman (Vue, lazy-load)

| Halaman | Path | Fungsi |
|---------|------|--------|
| `Attendance/Index.vue` | `/attendance` | Kalender & ringkasan kehadiran |
| `Attendance/Clock.vue` | `/attendance/clock` | Self check-in/out (selfie + GPS) |
| `Attendance/Schedules.vue` | `/attendance/schedules` | Kelola jadwal kerja |
| `Attendance/Leaves.vue` | `/attendance/leaves` | Ajukan/approve cuti |
| `Attendance/Overtimes.vue` | `/attendance/overtimes` | Ajukan/approve lembur |
| `Attendance/Settings.vue` | `/attendance/settings` | Lokasi outlet, radius, toggles |

### Menu Sidebar

```javascript
{
    name: 'Absensi',
    href: '/attendance',
    icon: CalendarCheck,
    menuKey: 'attendance',
    section: 'laporan_keuangan',
    backofficeOnly: true,
    // hanya tampil bila addon aktif:
    attendanceOnly: true
}
```

Tambahkan `attendance` ke `RoleMenuPermissionSeeder` untuk `admin`, `manager`, `owner`. Kasir tetap clock-in lewat alur shift kasir (bukan menu Absensi).

---

## 🚀 Fase Implementasi

### Fase 0: Prasyarat
- [ ] `employees` (dari Fase 1 Payroll) sudah ada.
- [ ] Kolom `stores.latitude/longitude`.

### Fase A: Kerangka Addon & Aktivasi
- [ ] Struktur `modules/Attendance` + `AttendanceServiceProvider`.
- [ ] `AttendanceSettings` (flag `enabled`, radius, toggles) + integrasi `HandleInertiaRequests`.
- [ ] Middleware `EnsureAttendanceEnabled`.
- [ ] Menu + `role_menu_permissions` (`attendance`).

### Fase B: Absensi Dasar + Geolokasi
- [ ] Migration: `work_schedules`, `attendances`, `attendance_photos`.
- [ ] `GeolocationService` (Haversine + radius).
- [ ] `AttendanceService` (clock in/out, applySchedule, aggregate).
- [ ] `AttendancePhotoService` (upload privat + validasi).
- [ ] Halaman Clock (selfie + GPS) & Index (kalender).

### Fase C: Cuti, Lembur & Otomatisasi
- [ ] Migration: `leave_requests`, `overtime_requests`.
- [ ] Approval cuti/lembur.
- [ ] Command `attendance:from-cash-shifts` (idempotent).
- [ ] Normalisasi timezone toko.

### Fase D: Integrasi Payroll
- [ ] `PayrollService` memakai `AttendanceService::aggregate()`.
- [ ] Snapshot `attendance_summary` + `payroll_items` saat `runPeriod()`.
- [ ] Lock period → data absensi dibekukan.

---

## 🛡️ Keamanan & Kepatuhan

1. **UU PDP** — foto selfie & geolokasi = data pribadi; simpan privat, akses terbatas, beri kebijakan retensi.
2. **Akses** — hanya admin/manajer/owner kelola absensi; kasir tidak melihat absensi orang lain.
3. **Validasi server** — radius & foto diverifikasi backend, bukan hanya frontend.
4. **Audit trail** — `created_by/updated_by`, approval cuti/lembur, catatan admin override.
5. **Lembur wajib approval** (default).
6. **Snapshot payroll** — periode terkunci tidak berubah.
7. **Timezone konsisten** — `work_date` memakai `stores.timezone`.
8. **Anti-penyalahgunaan foto** — foto terikat `attendance_id` + `captured_at`; (opsional) deteksi liveness di fase lanjut.

---

## 📝 Testing Plan

### Unit Test
- `GeolocationService::distanceMeters()` & `isWithinRadius()` (dalam/luar radius, batas).
- `AttendanceService::applySchedule()` — late, early leave, overtime.
- `AttendanceService::aggregate()` — ringkasan periode.
- `generateFromCashShifts()` — idempotent (2× jalan tidak dobel).

### Feature Test
- Clock-in di luar radius → ditolak (422).
- Clock-in tanpa foto (jika `require_selfie=true`) → ditolak.
- Kasir tidak bisa akses menu Absensi.
- Shift kasir otomatis jadi absensi (tanpa dobel).
- Payroll run membekukan `attendance_summary`.

### Manual Test Checklist
- [ ] Selfie tersimpan di disk privat & tidak bisa diakses publik.
- [ ] Radius memblokir absensi di luar lokasi.
- [ ] Admin override bekerja & tercatat di audit.
- [ ] Cuti disetujui mengubah status absensi.
- [ ] Slip gaji menampilkan lembur & potongan sesuai agregat.

---

## ⚠️ Risiko & Catatan

1. **Spoofing GPS/foto** — mitigasi: validasi server + akurasi + (opsional) liveness; seimbangkan dengan UX UMKM.
2. **GPS gagal/offline** — sediakan admin override dengan alasan wajib.
3. **Shift lintas tengah malam** — wajib timezone toko; `work_date` = tanggal mulai shift.
4. **`cash_shifts` hanya kasir** — jangan jadikan satu-satunya sumber absensi.
5. **Foto besar** — batasi ukuran/dimensi + kompresi, pakai disk objek (`s3`) bila foto menumpuk.

---

**Dokumen ini bersifat living document.**  
**Last Updated:** 2026-08-15  
**Version:** 2.0  
**Status:** Analisis & Rencana (belum ada kode diubah)
