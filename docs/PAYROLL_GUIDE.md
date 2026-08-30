# Panduan Modul Payroll

Dokumen lengkap modul payroll terintegrasi absensi untuk aplikasi UMKM Kasir.

## Daftar Isi

- [Arsitektur](#arsitektur)
- [Struktur File](#struktur-file)
- [Konsep Utama](#konsep-utama)
- [Alur Kerja](#alur-kerja)
- [Konfigurasi](#konfigurasi)
- [Perhitungan Gaji](#perhitungan-gaji)
- [Integrasi Absensi](#integrasi-absensi)
- [Best Practices](#best-practices)

---

## Arsitektur

```
┌─────────────────────────────────────────────────────────────┐
│                     Modul Payroll                            │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────┐  │
│  │  PayrollNav   │    │  PayrollNav  │    │  PayrollNav  │  │
│  │  (Periode)    │    │  (Komponen)  │    │  (Settings)  │  │
│  └──────┬───────┘    └──────┬───────┘    └──────┬───────┘  │
│         │                    │                    │          │
│  ┌──────▼────────────────────▼────────────────────▼──────┐  │
│  │              Controllers (Inertia)                     │  │
│  │  PayrollController, PayrollSalaryController,          │  │
│  │  PayrollSettingsController                            │  │
│  └──────────────────────┬────────────────────────────────┘  │
│                         │                                    │
│  ┌──────────────────────▼────────────────────────────────┐  │
│  │                    Services                            │  │
│  │  PayrollService (hitung gaji)                         │  │
│  │  TaxService (hitung PPh 21)                           │  │
│  └──────────────────────┬────────────────────────────────┘  │
│                         │                                    │
│  ┌──────────────────────▼────────────────────────────────┐  │
│  │              Integrasi Data                            │  │
│  │  AttendanceService::aggregate() → data absensi         │  │
│  │  SalaryComponent → komponen gaji per karyawan          │  │
│  │  Store settings → konfigurasi per toko                 │  │
│  └───────────────────────────────────────────────────────┘  │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

---

## Struktur File

### Backend (Laravel)

```
app/Modules/Payroll/
├── Http/
│   ├── Controllers/
│   │   ├── AdminPayrollController.php      # Base controller (auth + store)
│   │   ├── PayrollController.php           # CRUD periode + run payroll
│   │   ├── PayrollSalaryController.php     # CRUD komponen gaji + status pajak
│   │   └── PayrollSettingsController.php   # Konfigurasi per toko
│   └── Middleware/
│       └── EnsurePayrollEnabled.php        # Cek add-on aktif
├── Models/
│   ├── PayrollPeriod.php                   # Periode penggajian
│   ├── PayrollRun.php                      # Slip gaji per karyawan
│   └── SalaryComponent.php                 # Komponen gaji (earning/deduction)
└── Services/
    ├── PayrollService.php                  # Logika perhitungan gaji
    └── TaxService.php                      # Perhitungan PPh 21 progresif
```

### Frontend (Vue)

```
resources/js/
├── components/
│   └── PayrollNav.vue                      # Navigasi tab payroll
└── pages/Payroll/
    ├── Index.vue                           # Daftar periode gaji
    ├── Show.vue                            # Detail slip gaji karyawan
    ├── Salaries.vue                        # Komponen gaji + status pajak
    └── Settings.vue                        # Konfigurasi (4 tab)
```

### Database

```
database/migrations/
├── 2026_08_15_000003_create_payroll_tables.php
├── 2026_08_16_000001_add_late_penalty_to_stores_table.php
├── 2026_08_16_000002_add_payroll_settings_to_stores_table.php
├── 2026_08_16_000003_add_tax_status_to_employees_table.php
└── 2026_08_16_000004_add_pph21_enabled_to_stores_table.php
```

---

## Konsep Utama

### 1. Periode Gaji (PayrollPeriod)

Waktu penggajian (bulanan/mingguan). Satu periode = satu siklus hitung gaji.

| Field | Tipe | Keterangan |
|-------|------|------------|
| `name` | string | Nama periode (contoh: "Gaji Januari 2026") |
| `period_start` | date | Tanggal mulai |
| `period_end` | date | Tanggal akhir |
| `status` | enum | `draft` / `locked` |

### 2. Komponen Gaji (SalaryComponent)

Elemen penghasilan/potongan per karyawan.

| Field | Tipe | Keterangan |
|-------|------|------------|
| `name` | string | Nama komponen (contoh: "Gaji Pokok") |
| `type` | enum | `earning` (penghasilan) / `deduction` (potongan) |
| `amount` | decimal | Nominal tetap |

**Contoh:**
- Earning: Gaji Pokok, Tunjangan Transport, Tunjangan Makan
- Deduction: Potongan Kas, Cicilan, Iuran

### 3. Slip Gaji (PayrollRun)

Hasil perhitungan gaji per karyawan per periode.

| Field | Tipe | Keterangan |
|-------|------|------------|
| `basic_salary` | decimal | Total earning |
| `deductions` | decimal | Total deduction manual |
| `overtime_hours` | decimal | Jam lembur dari absensi |
| `overtime_pay` | decimal | Upah lembur |
| `gross_salary` | decimal | Gaji kotor |
| `net_salary` | decimal | Gaji bersih (take home pay) |
| `attendance_summary` | json | Ringkasan absensi |
| `component_breakdown` | json | Detail breakdown lengkap |

### 4. Konfigurasi per Toko (Store)

Setiap toko bisa punya konfigurasi payroll berbeda.

| Field | Default | Keterangan |
|-------|---------|------------|
| `late_penalty_per_day` | 10.000 | Denda per hari telat |
| `late_penalty_per_minute` | 100 | Denda per menit telat |
| `overtime_rate_first_hour` | 1.50 | Multiplier lembur jam pertama |
| `overtime_rate_next_hour` | 2.00 | Multiplier lembur jam berikutnya |
| `overtime_rate_holiday` | 2.00 | Multiplier lembur hari libur |
| `bpjs_enabled` | false | Aktifkan perhitungan BPJS |
| `pph21_enabled` | false | Aktifkan perhitungan PPh 21 |

---

## Alur Kerja

### 1. Setup Awal

```
1. Aktifkan add-on Absensi + Payroll di halaman Subscription
2. Konfigurasi settings di /payroll/settings:
   - Atur denda keterlambatan
   - Atur tarif lembur
   - Aktifkan BPJS (jika perlu)
   - Aktifkan PPh 21 (jika perlu)
3. Tambahkan komponen gaji per karyawan di /payroll/salaries
4. Atur status pajak (PTKP) per karyawan
```

### 2. Hitung Gaji Bulanan

```
1. Buat periode gaji di /payroll (contoh: "Gaji Januari 2026")
   - Set tanggal mulai dan akhir
2. Klik "Hitung" untuk menjalankan perhitungan
3. Sistem akan:
   a. Ambil semua karyawan aktif
   b. Ambil data absensi (AttendanceService::aggregate)
   c. Hitung upah lembur dari jam lembur
   d. Hitung potongan keterlambatan
   e. Hitung BPJS (jika diaktifkan)
   f. Hitung PPh 21 (jika diaktifkan)
   g. Generate slip gaji per karyawan
4. Review hasil di /payroll/{period}
5. Kunci periode jika sudah final (status: locked)
```

### 3. Review Slip Gaji

```
1. Buka detail periode di /payroll/{period}
2. Klik icon expand untuk lihat breakdown:
   - Penghasilan (earning + lembur)
   - Potongan (deduction + keterlambatan)
   - BPJS (jika aktif)
   - PPh 21 (jika aktif)
3. Export atau cetak slip gaji (fitur tambahan)
```

---

## Konfigurasi

### Tab 1: Keterlambatan

| Setting | Default | Keterangan |
|---------|---------|------------|
| Denda per Hari Telat | Rp 10.000 | Potongan otomatis per hari tidak hadir tepat waktu |
| Denda per Menit Telat | Rp 100 | Potongan otomatis per menit keterlambatan |

**Contoh Perhitungan:**
```
Karyawan terlambat 2 hari, total 30 menit:
Potongan = (2 × 10.000) + (30 × 100) = 20.000 + 3.000 = Rp 23.000
```

### Tab 2: Lembur

| Setting | Default | Keterangan |
|---------|---------|------------|
| Jam Pertama | 1.50× | 150% dari upah/jam |
| Jam Berikutnya | 2.00× | 200% dari upah/jam |
| Hari Libur | 2.00× | 200% dari upah/jam |

**Contoh Perhitungan:**
```
Gaji pokok: Rp 5.000.000
Upah/jam: 5.000.000 / 173 = Rp 28.902
Lembur 10 jam (tarif 1.5×): 10 × 28.902 × 1.5 = Rp 433.530
```

### Tab 3: BPJS

| Komponen | Karyawan | Perusahaan | Keterangan |
|----------|----------|------------|------------|
| Kesehatan | 1% | 4% | Jaminan kesehatan |
| JHT | 2% | 3.7% | Jaminan hari tua |
| JP | 1% | 2% | Jaminan pensiun |
| JKK | - | 0.24% | Jaminan kecelakaan kerja |
| JKM | - | 0.3% | Jaminan kematian |

**Contoh Perhitungan (Gaji Rp 5.000.000):**
```
BPJS Kesehatan: 5.000.000 × 1% = Rp 50.000
BPJS JHT: 5.000.000 × 2% = Rp 100.000
BPJS JP: 5.000.000 × 1% = Rp 50.000
Total BPJS Karyawan = Rp 200.000
```

### Tab 4: PPh 21

| Tarif | Penghasilan Kena Pajak |
|-------|------------------------|
| 5% | 0 - 60 juta/tahun |
| 15% | 60 - 250 juta/tahun |
| 25% | 250 - 500 juta/tahun |
| 30% | 500 juta - 5 miliar/tahun |
| 35% | > 5 miliar/tahun |

**PTKP (Penghasilan Tidak Kena Pajak):**

| Status | PTKP/Tahun |
|--------|------------|
| TK/0 | Rp 54.000.000 |
| TK/1 | Rp 58.500.000 |
| TK/2 | Rp 63.000.000 |
| TK/3 | Rp 67.500.000 |
| K/0 | Rp 58.500.000 |
| K/1 | Rp 63.000.000 |
| K/2 | Rp 67.500.000 |
| K/3 | Rp 72.000.000 |

**Contoh Perhitungan PPh 21:**
```
Gaji bruto/bulan: Rp 10.000.000
Penghasilan bruto/tahun: 10.000.000 × 12 = Rp 120.000.000
PTKP (TK/1): Rp 58.500.000
PKP: 120.000.000 - 58.500.000 = Rp 61.500.000

PPh 21:
- 60.000.000 × 5% = Rp 3.000.000
- 1.500.000 × 15% = Rp 225.000
Total PPh 21/tahun: Rp 3.225.000
PPh 21/bulan: Rp 268.750
```

---

## Perhitungan Gaji

### Formula Lengkap

```
1. Gaji Pokok = SUM(komponen earning)

2. Upah Lembur = jam_lembur × (gaji_pokok / 173) × tarif_lembur

3. Potongan Keterlambatan = (hari_telat × denda_hari) + (menit_telat × denda_menit)

4. BPJS Karyawan (jika aktif):
   = (gaji_pokok × %kesehatan) + (gaji_pokok × %JHT) + (gaji_pokok × %JP)

5. Penghasilan Bruto = Gaji Pokok + Upah Lembur

6. Penghasilan Neto = Penghasilan Bruto - Potongan Keterlambatan - BPJS Karyawan

7. PPh 21 (jika aktif):
   - Penghasilan tahunan = Penghasilan Neto × 12
   - PKP = Penghasilan tahunan - PTKP
   - PPh 21/tahun = hitung progresif dari PKP
   - PPh 21/bulan = PPh 21/tahun / 12

8. Gaji Bersih = Penghasilan Bruto - Potongan Manual - Potongan Keterlambatan - BPJS Karyawan - PPh 21
```

### Contoh Lengkap

```
Karyawan: Budi
Status: K/1 (Kawin, 1 tanggungan)
Gaji Pokok: Rp 8.000.000
Tunjangan Transport: Rp 500.000
Potongan Kas: Rp 200.000

Data Absensi:
- Hadir: 20 hari
- Terlambat: 1 hari, 15 menit
- Lembur: 10 jam

Konfigurasi Toko:
- Denda/hari: Rp 10.000
- Denda/menit: Rp 100
- Tarif lembur: 1.5×
- BPJS: Aktif
- PPh 21: Aktif

Perhitungan:
1. Gaji Pokok = 8.000.000 + 500.000 = Rp 8.500.000
2. Upah/jam = 8.500.000 / 173 = Rp 49.133
3. Upah Lembur = 10 × 49.133 × 1.5 = Rp 737.000
4. Potongan Keterlambatan = (1 × 10.000) + (15 × 100) = Rp 11.500
5. BPJS Karyawan:
   - Kesehatan: 8.500.000 × 1% = Rp 85.000
   - JHT: 8.500.000 × 2% = Rp 170.000
   - JP: 8.500.000 × 1% = Rp 85.000
   - Total BPJS = Rp 340.000
6. Penghasilan Bruto = 8.500.000 + 737.000 = Rp 9.237.000
7. Penghasilan Neto = 9.237.000 - 11.500 - 340.000 = Rp 8.885.500
8. PPh 21:
   - Penghasilan tahunan = 8.885.500 × 12 = Rp 106.626.000
   - PTKP (K/1) = Rp 63.000.000
   - PKP = 106.626.000 - 63.000.000 = Rp 43.626.000
   - PPh 21/tahun = 43.626.000 × 5% = Rp 2.181.300
   - PPh 21/bulan = Rp 181.775
9. Gaji Bersih = 9.237.000 - 200.000 - 11.500 - 340.000 - 181.775 = Rp 8.503.725
```

---

## Integrasi Absensi

Modul payroll mengambil data dari `AttendanceService::aggregate()`:

```php
$summary = AttendanceService::aggregate($employee, $from, $to);

// Return:
[
    'work_days' => 22,        // Hari kerja dalam periode
    'present_days' => 20,     // Hari hadir
    'late_days' => 1,         // Hari terlambat
    'absent_days' => 2,       // Hari tidak hadir
    'leave_days' => 0,        // Hari cuti
    'total_hours' => 176,     // Total jam kerja
    'total_overtime' => 10,   // Total jam lembur
    'total_late_min' => 15,   // Total menit terlambat
]
```

Data ini digunakan untuk:
- Hitung potongan keterlambatan
- Hitung upah lembur
- Tampilkan ringkasan absensi di slip gaji

---

## Best Practices

### 1. Setup Komponen Gaji

**Lakukan:**
- Pisahkan komponen earning dan deduction dengan jelas
- Gunakan nama yang deskriptif (contoh: "Gaji Pokok", "Tunjangan Transport")
- Urutkan komponen berdasarkan prioritas (sort_order)

**Hindari:**
- Mencampur semua tunjangan dalam satu komponen
- Menggunakan komponen untuk perhitungan dinamis (gunakan settings)

### 2. Konfigurasi Settings

**Lakukan:**
- Sesuaikan denda keterlambatan dengan kebijakan perusahaan
- Review tarif lembur sesuai regulasi (minimal 1.5× upah/jam)
- Aktifkan BPJS hanya jika perusahaan terdaftar di BPJS
- Update PTKP sesuai regulasi pajak terbaru

**Hindari:**
- Mengubah settings saat periode gaji sedang berjalan
- Menggunakan tarif lembur di bawah standar regulasi

### 3. Perhitungan Gaji

**Lakukan:**
- Buat periode gaji sebelum akhir bulan
- Review slip gaji sebelum mengunci periode
- Simpan backup data absensi sebelum hitung gaji
- Kunci periode setelah gaji dibayarkan

**Hindari:**
- Mengubah data absensi setelah hitung gaji
- Menghitung ulang periode yang sudah locked
- Menghapus periode yang sudah dibayarkan

### 4. Status Pajak (PTKP)

**Lakukan:**
- Update status pajak karyawan saat ada perubahan (menikah, anak)
- Review PTKP setiap awal tahun pajak
- Konsultasikan dengan akuntan untuk kasus kompleks

**Hindari:**
- Menggunakan status pajak yang tidak sesuai
- Mengabaikan perubahan status karyawan

### 5. Keamanan & Audit

**Lakukan:**
- Batasi akses payroll ke role admin/manager/owner
- Log semua perubahan settings
- Backup database sebelum hitung gaji massal
- Verifikasi total gaji sebelum transfer

**Hindari:**
- Memberikan akses payroll ke kasir biasa
- Menghapus data payroll yang sudah dibayarkan
- Menghitung gaji tanpa review

### 6. Performa

**Lakukan:**
- Hitung gaji per toko secara terpisah
- Gunakan periode bulanan (bukan harian)
- Index kolom yang sering di-query

**Hindari:**
- Menghitung semua toko sekaligus (jika banyak)
- Menggunakan periode terlalu panjang (> 1 bulan)

### 7. Regulasi Indonesia

**Referensi:**
- **UU Cipta Kerja**: Tarif lembur minimal 1.5× upah/jam
- **PP 36/2021**: Upah minimum dan perhitungan lembur
- **UU PPh 2024**: Tarif progresif dan PTKP terbaru
- **BPJS 2024**: Persentase iuran kesehatan, JHT, JP, JKK, JKM

**Checklist Tahunan:**
- [ ] Update PTKP sesuai regulasi terbaru
- [ ] Review tarif BPJS
- [ ] Verifikasi upah minimum per daerah
- [ ] Audit komponen gaji karyawan

### 8. Testing

**Sebelum Go-Live:**
- [ ] Test hitung gaji dengan data dummy
- [ ] Verifikasi perhitungan BPJS manual vs sistem
- [ ] Verifikasi perhitungan PPh 21 manual vs sistem
- [ ] Test potongan keterlambatan
- [ ] Test upah lembur

**Setelah Go-Live:**
- [ ] Monitor error log
- [ ] Review slip gaji pertama
- [ ] Feedback dari karyawan

---

## Troubleshooting

### Masalah: Gaji bersih negatif

**Penyebab:** Potongan lebih besar dari penghasilan

**Solusi:**
- Review komponen deduction
- Cek apakah ada potongan ganda
- Pastikan gaji pokok cukup untuk menutupi potongan

### Masalah: PPh 21 tidak dihitung

**Penyebab:** PPh 21 tidak diaktifkan di settings

**Solusi:**
- Aktifkan toggle PPh 21 di `/payroll/settings`
- Pastikan status pajak (PTKP) sudah diatur per karyawan

### Masalah: BPJS tidak muncul di slip

**Penyebab:** BPJS tidak diaktifkan di settings

**Solusi:**
- Aktifkan toggle BPJS di `/payroll/settings`
- Hitung ulang periode gaji

### Masalah: Potongan keterlambatan terlalu besar

**Penyebab:** Denda per menit terlalu tinggi

**Solusi:**
- Review settings denda keterlambatan
- Sesuaikan dengan kebijakan perusahaan
- Hitung ulang periode gaji

---

## Changelog

### v1.0.0 (2026-08-16)
- ✅ Fase A: Potongan Keterlambatan
- ✅ Fase B: Settings Payroll (Lembur + BPJS)
- ✅ Fase C: PPh 21 dengan Tarif Progresif

### Fitur Mendatang
- [ ] Tarif lembur bertingkat (jam pertama vs berikutnya)
- [ ] Export slip gaji ke PDF
- [ ] Cetak slip gaji massal
- [ ] Integrasi dengan sistem akuntansi
- [ ] Laporan payroll bulanan
- [ ] Notifikasi email slip gaji ke karyawan

---

## Kontak & Support

Untuk pertanyaan atau issue terkait modul payroll:
- Review dokumentasi di `docs/PAYROLL_ENHANCEMENT_PLAN.md`
- Cek source code di `app/Modules/Payroll/`
- Test dengan data dummy sebelum produksi

---

**Terakhir diupdate:** 16 Agustus 2026
