# Rencana Peningkatan Modul Payroll

Dokumen ini berisi rencana implementasi bertahap untuk meningkatkan modul payroll agar sesuai standar Indonesia.

## Status Implementasi

| Fase | Fitur | Status |
|------|-------|--------|
| A | Potongan Keterlambatan | ✅ Selesai |
| B | Settings Payroll per Toko (Lembur + BPJS) | ✅ Selesai |
| C | Struktur Gaji Penuh (PPh 21) | ✅ Selesai |

---

## Fase A: Potongan Keterlambatan

**Tujuan**: Otomatis memotong gaji karyawan yang terlambat berdasarkan data absensi.

### Konfigurasi
- `late_penalty_per_day`: Denda per hari telat (default: Rp 10.000)
- `late_penalty_per_minute`: Denda per menit telat (default: Rp 100)

### Integrasi Data
- Sumber data: `AttendanceService::aggregate()` → `late_days`, `total_late_min`
- Perhitungan: `(late_days × penalty_per_day) + (total_late_min × penalty_per_minute)`

### File yang Dibuat/Dimodifikasi
1. **Migration**: `add_late_penalty_to_stores_table.php`
2. **Model**: Update `Store` model untuk field baru
3. **Service**: Update `PayrollService::runPeriod()` untuk hitung potongan keterlambatan
4. **Controller**: `PayrollSettingsController` untuk CRUD settings
5. **Vue**: `Payroll/Settings.vue` untuk halaman konfigurasi

### Struktur Data
```php
// Tabel stores (tambah kolom)
$table->decimal('late_penalty_per_day', 10, 2)->default(10000);
$table->decimal('late_penalty_per_minute', 10, 2)->default(100);

// PayrollService
$latePenalty = ($summary['late_days'] * $store->late_penalty_per_day) 
             + ($summary['total_late_min'] * $store->late_penalty_per_minute);
$deduction += $latePenalty;
```

---

## Fase B: Settings Payroll per Toko

**Tujuan**: Halaman konfigurasi payroll lengkap per toko.

### Fitur
1. **Potongan Keterlambatan** (dari Fase A)
2. **Tarif Lembur**
   - Jam pertama: 1.5x upah/jam
   - Jam berikutnya: 2x upah/jam
   - Hari libur: 2x upah/jam (jam pertama), 3x (jam berikutnya)
3. **BPJS (Opsional)**
   - BPJS Kesehatan: 1% karyawan, 4% perusahaan
   - BPJS Ketenagakerjaan: JHT, JP, JKK, JKM
4. **PPh 21 (Opsional)**
   - PTKP (Penghasilan Tidak Kena Pajak)
   - Tarif progresif

### Halaman Settings
- Route: `/payroll/settings`
- Tab: Keterlambatan | Lembur | BPJS | PPh 21
- Simpan konfigurasi per toko

---

## Fase C: Struktur Gaji Penuh (PPh 21) ✅ SELESAI

**Tujuan**: Implementasi perhitungan PPh 21 dengan tarif progresif sesuai standar Indonesia.

### Implementasi
1. **Migration**: Kolom `tax_status` (PTKP) dan `dependents` di tabel `employees`
2. **Service**: `TaxService::calculateAnnual()` untuk hitung PPh 21 progresif
3. **Model**: Update `Employee` untuk field `tax_status` dan `dependents`
4. **Controller**: `PayrollSalaryController::updateTax()` untuk update status pajak karyawan
5. **Vue**: Tab PPh 21 di Settings + dropdown status pajak di Salaries
6. **Report**: Detail PPh 21 di slip gaji (Show.vue)

### Tarif PPh 21 (2024)
- 0 - 60 juta: 5%
- 60 - 250 juta: 15%
- 250 - 500 juta: 25%
- 500 juta - 5 miliar: 30%
- > 5 miliar: 35%

### PTKP (Penghasilan Tidak Kena Pajak)
- TK/0: 54 juta/tahun
- TK/1: 58,5 juta/tahun
- K/0: 58,5 juta/tahun
- K/1: 63 juta/tahun
- K/2: 67,5 juta/tahun
- K/3: 72 juta/tahun

---

## Catatan Implementasi

### Prioritas
1. **Fase A**: Paling sederhana, langsung bermanfaat, integrasi absensi
2. **Fase B**: Fleksibilitas konfigurasi, persiapan untuk Fase C
3. **Fase C**: Kompleks, butuh validasi regulasi, tapi paling lengkap

### Testing
- Unit test untuk perhitungan potongan keterlambatan
- Unit test untuk perhitungan lembur
- Unit test untuk perhitungan BPJS + PPh 21
- Integration test dengan data absensi

### Dokumentasi
- Update README.md dengan fitur baru
- User guide untuk konfigurasi payroll
- Contoh perhitungan manual untuk verifikasi
