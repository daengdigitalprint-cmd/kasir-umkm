# Progres Implementasi Modul Absensi (Addon Terpisah) + Integrasi Payroll

> Dokumen ini melacak progres implementasi modul absensi secara bertahap (incremental).
> Rencana detail & best practice: [ATTENDANCE_PAYROLL_INTEGRATION_PLAN.md](./ATTENDANCE_PAYROLL_INTEGRATION_PLAN.md)

## Ringkasan Progres Keseluruhan

**Terakhir diperbarui:** 2026-08-15 18:45 WITA

| Tahap | Status | PIC | Tenggat Awal |
|-------|--------|-----|--------------|
| Fase 0 — Persiapan & Dokumen Tracking | ✅ Selesai | Asisten AI | 2026-08-15 |
| Fase 1 — Fondasi Addon | ⬜ Belum dimulai | Asisten AI | 2026-08-15 |
| Fase 2 — Model Data | ⬜ Belum dimulai | Asisten AI | 2026-08-16 |
| Fase 3 — Layanan Inti | ⬜ Belum dimulai | Asisten AI | 2026-08-16 |
| Fase 4 — Kontroller & API | ⬜ Belum dimulai | Asisten AI | 2026-08-17 |
| Fase 5 — Frontend & Menu | ⬜ Belum dimulai | Asisten AI | 2026-08-18 |
| Fase 6 — Integrasi Payroll & Command | ⬜ Belum dimulai | Asisten AI | 2026-08-19 |

**Kemajuan:** 1 dari 7 tahap selesai.

---

## Daftar Tahapan

1. **Fase 0 — Persiapan & Dokumen Tracking**: struktur dokumen progres + daftar tahapan + status/PIC/tenggat.
2. **Fase 1 — Fondasi Addon**: struktur modul `app/Modules/Attendance`, `AttendanceSettings`, middleware aktivasi, alias middleware, `routes/attendance.php`, flag di `HandleInertiaRequests`.
3. **Fase 2 — Model Data**: migration + model + relasi untuk `employees`, `work_schedules`, `attendances`, `attendance_photos`, `leave_requests`, `overtime_requests`.
4. **Fase 3 — Layanan Inti**: `GeolocationService` (Haversine + radius), `AttendancePhotoService` (upload privat + validasi), `AttendanceService` (clock in/out, applySchedule, aggregate).
5. **Fase 4 — Kontroller & API**: `AttendanceController` (clock in/out, index), `FormRequest`, registrasi route.
6. **Fase 5 — Frontend & Menu**: halaman Vue (Clock + Index), menu sidebar `attendance`, seeder `role_menu_permissions`, share data.
7. **Fase 6 — Integrasi Payroll & Command**: kontrak `AttendanceService::aggregate()` ke payroll + command `attendance:from-cash-shifts`.

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

- **Status:** ⬜ Belum dimulai
- **Penanggung jawab:** Asisten AI (TraeCode)
- **Tenggat awal:** 2026-08-15

### Fase 2 — Model Data

- **Status:** ⬜ Belum dimulai
- **Penanggung jawab:** Asisten AI (TraeCode)
- **Tenggat awal:** 2026-08-16

### Fase 3 — Layanan Inti

- **Status:** ⬜ Belum dimulai
- **Penanggung jawab:** Asisten AI (TraeCode)
- **Tenggat awal:** 2026-08-16

### Fase 4 — Kontroller & API

- **Status:** ⬜ Belum dimulai
- **Penanggung jawab:** Asisten AI (TraeCode)
- **Tenggat awal:** 2026-08-17

### Fase 5 — Frontend & Menu

- **Status:** ⬜ Belum dimulai
- **Penanggung jawab:** Asisten AI (TraeCode)
- **Tenggat awal:** 2026-08-18

### Fase 6 — Integrasi Payroll & Command

- **Status:** ⬜ Belum dimulai
- **Penanggung jawab:** Asisten AI (TraeCode)
- **Tenggat awal:** 2026-08-19
