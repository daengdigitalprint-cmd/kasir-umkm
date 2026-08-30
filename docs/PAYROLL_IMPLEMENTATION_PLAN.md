# Rencana Implementasi Modul Payroll (Gaji Karyawan)

## 📋 Overview

Modul Payroll dirancang untuk membantu UMKM mengelola penggajian karyawan secara terstruktur dan sesuai dengan ketentuan ketenagakerjaan Indonesia. Modul ini mengelola komponen gaji, absensi, lembur, potongan (PPh 21, BPJS, selisih kasir), hingga slip gaji digital.

**Target Pengguna:** Admin outlet, Owner (lintas outlet), Super Admin  
**Sasaran Bisnis:** UMKM dengan 1–100 karyawan, multi-outlet  
**Integrasi:** Memanfaatkan data `cash_shifts` (jam kerja kasir), `transactions` (komisi/insentif), `store_user` (keanggotaan tim)

---

## 🔍 Analisis Kondisi Saat Ini

### Data yang Sudah Ada (Bisa Dimanfaatkan)

| Data | Sumber | Manfaat untuk Payroll |
|------|--------|------------------------|
| Karyawan & role per outlet | Tabel `store_user` + `users` | Daftar karyawan yang digaji |
| Jam kerja kasir | `cash_shifts` (`opened_at`, `closed_at`) | Menghitung jam kerja & lembur kasir |
| Penjualan per karyawan | `transactions.user_id` | Menghitung komisi/insentif penjualan |
| Multi-outlet | `organizations`, `stores` | Payroll per outlet + laporan lintas outlet |
| Struktur tim | `store_user.role` | Pemetaan karyawan ke komponen gaji |
| Menu permission | `role_menu_permissions` | Menambahkan menu Payroll ke role admin/owner |

### Data yang Belum Ada (Harus Dibuat)

1. **Profil karyawan lengkap** — NIK KTP, NPWP, BPJS, bank, tanggal masuk, status PTKP
2. **Kebijakan gaji** — komponen gaji, tarif lembur, potongan
3. **Absensi/kehadiran** — jam masuk-keluar harian (di luar cash shift)
4. **Periode gaji & slip gaji** — history penggajian
5. **Master komponen gaji** — pokok, tunjangan tetap/tidak tetap, potongan

### Kesimpulan Analisis

- Aplikasi **belum siap** untuk payroll otomatis penuh karena belum ada modul absensi
- **Fase 1** bisa langsung berjalan dengan data existing (komponen gaji tetap + potongan manual)
- **Fase 2** menambahkan absensi & lembur otomatis
- **Fase 3** integrasi komisi dari transaksi POS

---

## 🇮🇩 Standar Payroll Indonesia (Best Practice)

### 1. Komponen Gaji (UU 13/2003 jo. UU Cipta Kerja / PP 35/2021)

| Komponen | Keterangan | Wajib? |
|----------|-----------|--------|
| **Gaji Pokok** | Upah dasar karyawan | ✅ Wajib |
| **Tunjangan Tetap** | Tunjangan jabatan, transport, makan (dibayar tetap tiap bulan) | Optional |
| **Tunjangan Tidak Tetap** | Insentif, komisi, bonus (berdasarkan performa) | Optional |
| **Lembur** | Minimal 1,5x upah/jam (jam pertama) dan 2x (jam berikutnya) | ✅ Wajib (jika ada lembur) |
| **THR** | 1 bulan gaji (≥12 bulan kerja) atau prorata (<12 bulan) | ✅ Wajib (PP 36/2021) |
| **Potongan** | PPh 21, BPJS Kesehatan & Ketenagakerjaan, kasbon, selisih kasir | Sesuai kondisi |

### 2. Upah Minimum (UU 11/2020, PP 36/2021)

- **UMP** (Provinsi), **UMK** (Kota/Kabupaten), **UMSP** (Sektoral)
- Gaji pokok + tunjangan tetap **tidak boleh kurang dari upah minimum**
- Tunjangan tidak tetap **tidak dihitung** dalam pemenuhan upah minimum
- Nilai ditetapkan 1 tahun sekali (umumnya efektif Januari)

### 3. Upah Lembur (PP 35/2021)

**Aturan jam kerja normal:**
- 7 jam/hari × 6 hari kerja (total 40 jam/minggu)
- 8 jam/hari × 5 hari kerja (total 40 jam/minggu)

**Tarif lembur:**
| Waktu | Tarif |
|-------|-------|
| Jam ke-1 (hari kerja) | 1,5 × upah/jam |
| Jam ke-2 s.d. berikutnya | 2 × upah/jam |
| Hari libur resmi/istirahat mingguan (8 jam pertama) | 2 × upah/jam |
| Jam ke-9+ (libur, 5 hari kerja) / jam ke-8+ (libur, 6 hari kerja) | 3 × upah/jam |
| Jam ke-10+ (libur, 5 hari) / jam ke-9+ (libur, 6 hari) | 4 × upah/jam |

**Cara hitung upah/jam:**
```
Upah/jam = Gaji pokok + tunjangan tetap (1 bulan) ÷ 173
```

### 4. Jaminan Sosial (BPJS)

| Jaminan | Iuran Karyawan | Iuran Perusahaan | Total |
|---------|---------------|------------------|-------|
| **BPJS Kesehatan** (5%) | 1% | 4% | 5% dari gaji |
| **JHT** (Jaminan Hari Tua) | 2% | 3,7% | 5,7% dari gaji |
| **JKK** (Kecelakaan Kerja) | 0% | 0,24%–1,74% | Sesuai kelas |
| **JKM** (Jaminan Kematian) | 0% | 0,3% | 0,3% |
| **JP** (Jaminan Pensiun) | 1% | 2% | 3% |

> 💡 **Catatan UMKM:** Banyak UMKM baru mengikuti JKK, JKM, dan JHT saja. Konfigurasi dibuat fleksibel per karyawan.

### 5. Pajak Penghasilan (PPh 21) — PMK 168/2023

Sistem **TER (Tarif Efektif Rata-rata)** sejak Januari 2024:

- **Tarif Efektif Bulanan (TER A/B/C)** berdasarkan status PTKP dan kategori:
  - TER A: lajang / menikah tanpa tanggungan
  - TER B: menikah 1 tanggungan
  - TER C: menikah 2–3 tanggungan
- **PTKP 2024:**
  - Wajib Pajak: Rp54.000.000/tahun
  - Kawin: +Rp4.500.000
  - Tanggungan: +Rp4.500.000/maks 3 orang
- Karyawan dengan penghasilan < Rp10 juta/bulan dan kategori A tidak perlu perhitungan kompleks (TER langsung)
- Wajib pajak yang tidak punya NPWP dikenakan tarif 20% lebih tinggi

### 6. Cuti & Ketidakhadiran (PP 35/2021)

| Jenis | Ketentuan |
|-------|-----------|
| **Cuti tahunan** | Minimal 12 hari/tahun (setelah 12 bulan kerja) |
| **Cuti sakit** | Dibayar penuh sesuai aturan, butuh surat dokter |
| **Cuti haid** | 2 hari/bulan untuk karyawan perempuan |
| **Cuti melahirkan** | 3 bulan (1,5 sebelum & 1,5 sesudah) — dibayar penuh |
| **Cuti keguguran** | 1,5 bulan |
| **Cuti agama** | Sesuai ketentuan |

### 7. THR Keagamaan (PP 36/2021)

| Masa Kerja | THR |
|------------|-----|
| ≥ 12 bulan | 1 bulan gaji (pokok + tunjangan tetap) |
| 1–12 bulan | Prorata: (masa kerja ÷ 12) × 1 bulan gaji |
| Tidak tetap | Sesuai kesepakatan, dibayar paling lambat H-7 hari raya |

### 8. Pesangon (PP 35/2021) — Referensi Saat PHK

| Komponen | Masa Kerja | Ketentuan |
|----------|-----------|-----------|
| Pesangon | < 1 tahun | 1 bulan upah |
| Pesangon | 1–2 tahun | 2 bulan upah |
| Pesangon | 2–3 tahun | 3 bulan upah (dst., maks 9 bulan) |
| Uang penghargaan | ≥ 3 tahun | 1 bulan upah per 3 tahun (maks 10 bulan) |

---

## 🏗️ Arsitektur Database

### Tabel Baru

#### 1. `employees` — Profil Karyawan (detail payroll)
```sql
CREATE TABLE employees (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,           -- hubungkan ke akun login (opsional)
    employee_code VARCHAR(50) NULL,          -- NIK karyawan internal
    id_card_number VARCHAR(20) NULL,         -- NIK KTP
    npwp VARCHAR(20) NULL,                   -- NPWP
    bpjs_kesehatan_no VARCHAR(20) NULL,
    bpjs_ketenagakerjaan_no VARCHAR(20) NULL,
    tax_status VARCHAR(20) DEFAULT 'TK0',    -- PTKP: TK0, K0, K1, K2, K3
    bank_name VARCHAR(100) NULL,
    bank_account_number VARCHAR(50) NULL,
    bank_account_name VARCHAR(150) NULL,
    join_date DATE NULL,                     -- tanggal masuk
    resignation_date DATE NULL,
    employment_status VARCHAR(20) DEFAULT 'permanent', -- permanent, contract, probation
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    UNIQUE KEY unique_store_employee (store_id, employee_code),
    UNIQUE KEY unique_store_user (store_id, user_id),
    INDEX idx_store_active (store_id, is_active)
);
```

#### 2. `payroll_settings` — Kebijakan Gaji per Outlet
```sql
CREATE TABLE payroll_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id BIGINT UNSIGNED NOT NULL UNIQUE,
    minimum_wage DECIMAL(15,2) NULL,          -- UMK/UMP untuk cek kepatuhan
    tax_period_type VARCHAR(10) DEFAULT 'monthly',
    overtime_enabled BOOLEAN DEFAULT TRUE,
    late_deduction_enabled BOOLEAN DEFAULT FALSE,
    bpjs_kesehatan_enabled BOOLEAN DEFAULT FALSE,
    bpjs_ketenagakerjaan_enabled BOOLEAN DEFAULT FALSE,
    pph21_enabled BOOLEAN DEFAULT FALSE,
    rounding_method VARCHAR(20) DEFAULT 'none', -- none, up, down, nearest_1000
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

#### 3. `salary_components` — Master Komponen Gaji per Karyawan
```sql
CREATE TABLE salary_components (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id BIGINT UNSIGNED NOT NULL,
    component_type VARCHAR(30) NOT NULL,      -- basic, fixed_allowance, variable_allowance, deduction
    name VARCHAR(100) NOT NULL,               -- Gaji Pokok, Tunjangan Transport, dll
    amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    is_taxable BOOLEAN DEFAULT TRUE,          -- termasuk objek pajak?
    is_active BOOLEAN DEFAULT TRUE,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    INDEX idx_employee (employee_id)
);
```

#### 4. `attendances` — Kehadiran Harian
```sql
CREATE TABLE attendances (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id BIGINT UNSIGNED NOT NULL,
    employee_id BIGINT UNSIGNED NOT NULL,
    work_date DATE NOT NULL,
    clock_in DATETIME NULL,
    clock_out DATETIME NULL,
    status VARCHAR(20) DEFAULT 'present',     -- present, late, absent, leave, sick
    leave_type VARCHAR(30) NULL,              -- annual, sick, maternity, unpaid
    hours_worked DECIMAL(6,2) NULL,
    overtime_hours DECIMAL(6,2) NULL,
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    UNIQUE KEY unique_employee_date (employee_id, work_date),
    INDEX idx_store_date (store_id, work_date)
);
```

#### 5. `payroll_periods` — Periode Gaji
```sql
CREATE TABLE payroll_periods (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id BIGINT UNSIGNED NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    name VARCHAR(50) NOT NULL,                -- Gaji Juli 2026
    status VARCHAR(20) DEFAULT 'draft',       -- draft, processing, approved, paid, locked
    processed_at TIMESTAMP NULL,
    processed_by BIGINT UNSIGNED NULL,
    approved_at TIMESTAMP NULL,
    approved_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    UNIQUE KEY unique_store_period (store_id, period_start, period_end)
);
```

#### 6. `payroll_runs` — Slip Gaji per Karyawan
```sql
CREATE TABLE payroll_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payroll_period_id BIGINT UNSIGNED NOT NULL,
    employee_id BIGINT UNSIGNED NOT NULL,
    store_id BIGINT UNSIGNED NOT NULL,
    -- Rincian perhitungan
    basic_salary DECIMAL(15,2) NOT NULL DEFAULT 0,
    fixed_allowances DECIMAL(15,2) NOT NULL DEFAULT 0,
    variable_allowances DECIMAL(15,2) NOT NULL DEFAULT 0,
    overtime_pay DECIMAL(15,2) NOT NULL DEFAULT 0,
    commission DECIMAL(15,2) NOT NULL DEFAULT 0,   -- komisi dari transaksi
    gross_salary DECIMAL(15,2) NOT NULL DEFAULT 0,
    deductions DECIMAL(15,2) NOT NULL DEFAULT 0,   -- kasbon, selisih kasir, dll
    bpjs_kesehatan_employee DECIMAL(15,2) NOT NULL DEFAULT 0,
    bpjs_ketenagakerjaan_employee DECIMAL(15,2) NOT NULL DEFAULT 0,
    pph21 DECIMAL(15,2) NOT NULL DEFAULT 0,
    net_salary DECIMAL(15,2) NOT NULL DEFAULT 0,
    component_breakdown JSON NULL,            -- detail per komponen
    attendance_summary JSON NULL,             -- hadir, lembur, cuti, absen
    notes VARCHAR(255) NULL,
    status VARCHAR(20) DEFAULT 'draft',
    paid_at TIMESTAMP NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    UNIQUE KEY unique_period_employee (payroll_period_id, employee_id),
    INDEX idx_store_period (store_id, payroll_period_id)
);
```

#### 7. `payroll_items` — Transaksi Perhitungan Detail (opsional, jika butuh audit)
```sql
CREATE TABLE payroll_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payroll_run_id BIGINT UNSIGNED NOT NULL,
    item_type VARCHAR(30) NOT NULL,           -- earning, deduction
    name VARCHAR(100) NOT NULL,
    amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    reference_type VARCHAR(50) NULL,          -- attendance, overtime, commission, manual
    reference_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    INDEX idx_payroll_run (payroll_run_id)
);
```

---

## 🧮 Rumus Perhitungan Gaji

### Rumus Dasar

```
Gaji Bruto = Gaji Pokok + Tunjangan Tetap + Tunjangan Tidak Tetap + Lembur + Komisi

Potongan   = PPh 21 + BPJS (bagian karyawan) + Kasbon + Selisih Kasir + Absensi

Gaji Bersih (Take Home Pay) = Gaji Bruto − Potongan
```

### Rumus Lembur (PP 35/2021)

```
Upah/Jam = (Gaji Pokok + Tunjangan Tetap) ÷ 173

Lembur hari kerja:
  Jam ke-1   = 1,5 × Upah/Jam
  Jam ke-2+  = 2 × Upah/Jam

Lembur hari libur (contoh 6 hari kerja):
  Jam ke-1 s.d. 7  = 2 × Upah/Jam
  Jam ke-8+       = 3 × Upah/Jam
  Jam ke-9+       = 4 × Upah/Jam
```

### Rumus THR (PP 36/2021)

```
THR = (Masa Kerja ÷ 12) × (Gaji Pokok + Tunjangan Tetap)
   untuk masa kerja < 12 bulan
THR = 1 × (Gaji Pokok + Tunjangan Tetap)
   untuk masa kerja ≥ 12 bulan
```

### Rumus PPh 21 (TER — PMK 168/2023)

```
Penghasilan Bruto Bulanan = Gaji + Tunjangan Tetap (termasuk tunjangan kena pajak)

Pengurang:
  Biaya Jabatan     = 5% × Bruto (maks Rp500.000/bulan)
  Iuran BPJS        = bagian karyawan (maks diakui)
  PTKP Bulanan      = sesuai status (TK0: Rp4.500.000)

PKP Bulanan = Bruto − Pengurang

PPh 21 = PKP Bulanan × Tarif Efektif Bulanan (TER A/B/C sesuai status)
```

> ⚠️ **Disclaimer:** Perhitungan pajak di sistem bersifat estimasi. Untuk kepatuhan penuh, disarankan konsultasi dengan konsultan pajak/tax agent. Sistem menyediakan mode "PPh 21 manual" agar admin bisa input hasil perhitungan resmi.

---

## 🔧 Arsitektur Backend

### Models

| Model | Lokasi | Keterangan |
|-------|--------|-----------|
| `Employee` | `app/Models/Employee.php` | Profil karyawan, relasi ke User & Store |
| `PayrollSetting` | `app/Models/PayrollSetting.php` | Kebijakan gaji per outlet |
| `SalaryComponent` | `app/Models/SalaryComponent.php` | Komponen gaji per karyawan |
| `Attendance` | `app/Models/Attendance.php` | Kehadiran harian |
| `PayrollPeriod` | `app/Models/PayrollPeriod.php` | Periode gaji |
| `PayrollRun` | `app/Models/PayrollRun.php` | Slip gaji per karyawan |
| `PayrollItem` | `app/Models/PayrollItem.php` | Rincian transaksi perhitungan |

### Service Layer

**`app/Services/PayrollService.php`** — inti perhitungan:

| Method | Fungsi |
|--------|--------|
| `calculateGrossSalary(Employee, Period)` | Hitung gaji bruto (pokok + tunjangan + lembur + komisi) |
| `calculateOvertime(Employee, Period)` | Hitung upah lembur sesuai PP 35/2021 |
| `calculateCommission(Employee, Period)` | Komisi dari transaksi POS per periode |
| `calculateDeductions(Employee, Period)` | Kasbon, selisih kasir, absensi |
| `calculateBpjs(Employee, Gross)` | Iuran BPJS Kesehatan & Ketenagakerjaan |
| `calculatePph21(Employee, Gross, Deductions)` | PPh 21 metode TER |
| `generatePayslip(Employee, Period)` | Generate slip gaji lengkap |
| `runPeriod(Store, Period)` | Proses seluruh karyawan satu periode |
| `approvePeriod(PayrollPeriod)` | Kunci & setujui periode |
| `exportPayslip(PayrollRun)` | Generate PDF/Excel slip gaji |

### Controller

**`app/Http/Controllers/PayrollController.php`**

| Method | Route | Fungsi |
|--------|-------|--------|
| `index()` | GET `/payroll` | Dashboard payroll outlet |
| `employees()` | GET `/payroll/employees` | Daftar & kelola karyawan |
| `storeEmployee()` | POST `/payroll/employees` | Tambah karyawan |
| `components()` | GET/POST `/payroll/employees/{e}/components` | Kelola komponen gaji |
| `settings()` | GET/POST `/payroll/settings` | Kebijakan gaji outlet |
| `periods()` | GET `/payroll/periods` | Daftar periode |
| `run()` | POST `/payroll/periods/{p}/run` | Proses payroll |
| `approve()` | POST `/payroll/periods/{p}/approve` | Setujui periode |
| `payslip()` | GET `/payroll/periods/{p}/runs/{r}` | Detail slip gaji |
| `export()` | GET `/payroll/periods/{p}/export` | Export semua slip (Excel) |
| `attendance()` | GET/POST `/payroll/attendance` | Kelola absensi |

### Artisan Command

**`app/Console/Commands/GenerateAttendanceFromCashShifts.php`**
```
php artisan payroll:attendance-from-shifts --store=5 --date=2026-07-31
```
- Mengonversi `cash_shifts` (opened_at/closed_at) menjadi baris `attendances`
- Menandai karyawan yang tidak ada cash shift sebagai perlu verifikasi

**`app/Console/Commands/GeneratePayroll.php`**
```
php artisan payroll:generate --store=5 --period=2026-07
```
- Menjalankan payroll untuk periode tertentu
- Output ringkasan per karyawan

**`app/Console/Commands/RemindPayrollCommand.php`**
```
php artisan payroll:remind
```
- Mengirim notifikasi jika periode gaji belum diproses (misal tanggal 25)

---

## 🎨 Arsitektur Frontend

### Halaman (Pages)

| Halaman | Path | Fungsi |
|---------|------|--------|
| `Payroll/Dashboard.vue` | `/payroll` | Ringkasan: total gaji bulan ini, jumlah karyawan, status periode, grafik komponen |
| `Payroll/Employees.vue` | `/payroll/employees` | Daftar karyawan + CRUD profil & komponen gaji |
| `Payroll/EmployeeForm.vue` | `/payroll/employees/create` | Form karyawan |
| `Payroll/EmployeeComponents.vue` | `/payroll/employees/{id}/components` | Set komponen gaji (pokok, tunjangan, potongan) |
| `Payroll/Periods.vue` | `/payroll/periods` | Daftar periode & status |
| `Payroll/PeriodDetail.vue` | `/payroll/periods/{id}` | Detail periode: semua karyawan, run/approve |
| `Payroll/Payslip.vue` | `/payroll/periods/{id}/runs/{rid}` | Slip gaji individu (print/PDF) |
| `Payroll/Settings.vue` | `/payroll/settings` | Kebijakan outlet (UMK, BPJS, PPh, lembur) |
| `Payroll/Attendance.vue` | `/payroll/attendance` | Kalender absensi karyawan |

### Menu Sidebar (`MainMenu.vue`)

```javascript
{ 
    name: 'Gaji (Payroll)', 
    href: '/payroll', 
    icon: Wallet, 
    menuKey: 'payroll', 
    section: 'laporan_keuangan',
    storeAdminOnly: true
}
```

Tambahkan `payroll` ke `RoleMenuPermissionSeeder` untuk role `admin` dan `owner`.

### Slip Gaji Digital

Desain slip gaji menampilkan:
- Header: nama outlet, periode, nama karyawan, kode, status
- Komponen pendapatan (bruto): pokok, tunjangan, lembur, komisi
- Komponen potongan: PPh 21, BPJS, kasbon, selisih kasir
- Total bersih (take home pay)
- QR/signature digital & tombol download PDF

---

## 🔄 Alur Proses Payroll

```
┌──────────────┐   ┌──────────────────┐   ┌─────────────────┐
│ 1. SETUP     │ → │ 2. INPUT DATA    │ → │ 3. PROSES       │
│  Kebijakan   │   │  Karyawan +      │   │  Generate gaji  │
│  outlet      │   │  komponen gaji   │   │  per karyawan   │
└──────────────┘   └──────────────────┘   └────────┬────────┘
                                                   ▼
┌──────────────┐   ┌──────────────────┐   ┌─────────────────┐
│ 6. LAPORAN   │ ← │ 5. PEMBAYARAN    │ ← │ 4. REVIEU &     │
│  Export &    │   │  Tandai paid     │   │  APPROVE periode│
│  slip gaji   │   │  (bank/transfer) │   │  (review slip)  │
└──────────────┘   └──────────────────┘   └─────────────────┘
```

### Status Lifecycle Periode

```
Draft → Processing → Approved → Paid → Locked
  │          │           │         │       │
  └─ diedit  └─ proses    └─ review └─ bayar └─ terkunci (audit)
```

---

## 🚀 Fase Implementasi

### Fase 1: Fondasi & Gaji Tetap (MVP)
**Tujuan:** Admin bisa menggaji karyawan dengan komponen tetap + potongan manual

- [ ] Migration: `employees`, `payroll_settings`, `salary_components`, `payroll_periods`, `payroll_runs`
- [ ] Model + relasi Employee, SalaryComponent, PayrollPeriod, PayrollRun
- [ ] `PayrollService`: hitung bruto (pokok + tunjangan) & netto (minus potongan manual)
- [ ] Controller + halaman: Employees, Components, Periods, Run, Payslip
- [ ] Menu sidebar + RoleMenuPermission (`payroll` untuk admin & owner)
- [ ] Print slip gaji sederhana

**Deliverable:** Penggajian bulanan dengan komponen tetap berjalan

---

### Fase 2: Absensi, Lembur & Otomatisasi
**Tujuan:** Perhitungan berbasis kehadiran

- [ ] Migration: `attendances`, `payroll_items`
- [ ] Modul absensi (check-in/out) — terintegrasi dengan CashShift
- [ ] Command `payroll:attendance-from-shifts` (konversi cash shifts → absensi)
- [ ] Perhitungan lembur otomatis (PP 35/2021) & potongan absensi
- [ ] Halaman Attendance (kalender harian per karyawan)
- [ ] Config: `overtime_enabled`, `late_deduction_enabled` di settings

**Deliverable:** Gaji mencerminkan kehadiran & lembur aktual

---

### Fase 3: BPJS, PPh 21 & Komisi
**Tujuan:** Kepatuhan regulasi & integrasi penjualan

- [ ] Perhitungan BPJS Kesehatan (1%/4%) & Ketenagakerjaan (JHT/JKK/JKM/JP)
- [ ] PPh 21 metode TER (PMK 168/2023) + tabel TER A/B/C
- [ ] Komisi otomatis dari `transactions.user_id` per periode
- [ ] Konfigurasi per karyawan: NPWP, PTKP, status BPJS
- [ ] Settings BPJS & PPh per outlet (aktif/nonaktif)
- [ ] Pengecekan kepatuhan upah minimum (UMP/UMK)

**Deliverable:** Payroll sesuai regulasi pajak & jamsostek

---

### Fase 4: THR, Pesangon & Enhancement
- [ ] Perhitungan THR otomatis (PP 36/2021, prorata)
- [ ] Modul cuti (tahunan, sakit, haid, melahirkan) & pengurang
- [ ] Export slip gaji PDF/Excel per periode
- [ ] Dashboard owner lintas outlet (total gaji per outlet)
- [ ] Notifikasi pengingat periode gaji (`payroll:remind`)
- [ ] Integrasi jurnal akuntansi (Posting gaji ke Chart of Accounts)
- [ ] Laporan biaya gaji per bulan (untuk laporan laba-rugi)

---

## 🛡️ Keamanan & Kepatuhan

1. **Akses terbatas**: hanya admin & owner yang bisa akses data gaji
2. **Data sensitif** (NPWP, BPJS, bank): pastikan tidak terekspos di API publik
3. **Audit trail**: riwayat perubahan komponen gaji & status periode
4. **Locked period**: periode yang sudah dibayar tidak bisa diubah tanpa approval
5. **Redaksi data**: kasir tidak bisa melihat gaji karyawan lain
6. **Disclaimer PPh 21**: sistem menampilkan catatan bahwa perhitungan bersifat estimasi
7. **Kepatuhan UMK**: validasi gaji pokok + tunjangan tetap ≥ upah minimum

---

## 📝 Testing Plan

### Unit Test
- `PayrollService::calculateGrossSalary()` — kombinasi komponen
- `PayrollService::calculateOvertime()` — validasi tarif 1,5x/2x/3x/4x
- `PayrollService::calculateBpjs()` — proporsi 1%/4%, 2%/3,7%, dsb
- `PayrollService::calculatePph21()` — TER A/B/C & batas PTKP
- `PayrollService::prorateThr()` — masa kerja < 12 bulan

### Feature Test
- Flow: setup → run → approve → paid → locked
- Proteksi akses: cashier tidak bisa akses payroll
- Konversi CashShift → Attendance

### Manual Test Checklist
- [ ] Tambah karyawan + komponen gaji tersimpan benar
- [ ] Run periode menghasilkan angka bruto/netto sesuai rumus
- [ ] Slip gaji menampilkan semua komponen & potongan
- [ ] Lembur dihitung benar dari data absensi
- [ ] Komisi sesuai transaksi karyawan
- [ ] Export Excel/PDF berjalan
- [ ] Karyawan non-aktif tidak muncul di periode berikutnya

---

## 📚 Dokumentasi & Referensi Regulasi

| Regulasi | Isi |
|----------|-----|
| UU 13/2003 jo. UU 11/2020 (Cipta Kerja) | Ketentuan umum ketenagakerjaan |
| PP 35/2021 | Perjanjian kerja, upah, lembur, cuti, pesangon |
| PP 36/2021 | THR keagamaan |
| PMK 168/2023 | PPh 21 metode TER |
| Perpres 60/2024 | Upah minimum 2025 |
| UU 40/2004 jo. UU 24/2011 | BPJS Kesehatan & Ketenagakerjaan |

---

## ✅ Checklist Go-Live

- [ ] Semua test lulus
- [ ] Migrasi database berhasil dijalankan
- [ ] Uji perhitungan dengan studi kasus (contoh: gaji 4jt, 5 lembur, 1 potongan kasbon)
- [ ] User acceptance testing dengan admin/owner
- [ ] Slip gaji & export diverifikasi
- [ ] Backup data payroll
- [ ] Panduan penggunaan untuk admin disiapkan
- [ ] Keputusan BPJS & PPh dikonfirmasi pemilik usaha

---

## 💡 Rekomendasi Tambahan

1. **Mulai dari Fase 1 dulu** — jangan langsung implementasi PPh/BPJS yang kompleks
2. **Siapkan "Template Komponen"** — agar karyawan baru cepat di-setup (misal: kasir = pokok 2jt + transport 200rb)
3. **Buat mode "Gaji Manual"** — admin boleh input nilai langsung jika bisnis masih sederhana
4. **Integrasi CashShift sangat bernilai** — karena kasir sudah rutin buka/tutup shift, data jam kerja hampir gratis didapat
5. **Disclaimer untuk pemilik usaha** — sistem membantu hitung, tapi keputusan kepatuhan pajak/BPJS tetap di tangan pengguna
6. **Pertimbangkan arsip jurnal** — posting biaya gaji ke modul akuntansi untuk laporan laba-rugi yang akurat

---

**Dokumen ini bersifat living document.**  
**Last Updated:** 2026-08-14  
**Version:** 1.0  
**Status:** Analisis & Rencana (belum ada kode diubah)
