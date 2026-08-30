# Rencana Implementasi: PIN-Based Shift System (Add-On)

## Status: Planning

Modul ini bersifat **add-on opsional**. Sistem login email/password yang sudah ada tetap berjalan normal. Admin bisa memilih:
- **Mode A**: Login email/password (default, seperti sekarang)
- **Mode B**: PIN-based shift (kasir pakai PIN, ganti shift tanpa logout)

---

## Arsitektur Toggle

```
┌─────────────────────────────────────────────────┐
│              Store Settings                      │
│  ┌─────────────────────────────────────────┐    │
│  │  Mode Login Kasir:                       │    │
│  │  ○ Email/Password (default)              │    │
│  │  ○ PIN-Based Shift                       │    │
│  └─────────────────────────────────────────┘    │
└─────────────────────────────────────────────────┘
```

### Flow Keputusan

```
User buka POS
    │
    ├─ Jika mode = "email/password" (default)
    │   └─ Login seperti biasa → email + password
    │
    └─ Jika mode = "pin_shift"
        └─ Tampilkan layar pilih kasir → input PIN
```

### Database Design

```sql
-- 1. Tambah kolom di tabel stores (toggle per toko)
ALTER TABLE stores ADD COLUMN cashier_login_mode ENUM('email', 'pin') DEFAULT 'email';

-- 2. Tambah kolom di tabel employees (untuk PIN)
ALTER TABLE employees ADD COLUMN pin_hash VARCHAR(255) NULL;
ALTER TABLE employees ADD COLUMN pin_set_at TIMESTAMP NULL;
ALTER TABLE employees ADD COLUMN can_login_pos BOOLEAN DEFAULT FALSE;

-- 3. Tabel cashier_shifts (baru, untuk mode PIN)
CREATE TABLE cashier_shifts (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    store_id BIGINT NOT NULL,
    employee_id BIGINT NOT NULL,
    cash_drawer_id BIGINT NULL,
    started_at TIMESTAMP NOT NULL,
    ended_at TIMESTAMP NULL,
    opening_balance DECIMAL(15,2) DEFAULT 0,
    closing_balance DECIMAL(15,2) NULL,
    status ENUM('open', 'closed') DEFAULT 'open',
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    INDEX idx_store_status (store_id, status),
    INDEX idx_employee (employee_id)
);

-- 4. Tabel pin_attempts (untuk rate limiting)
CREATE TABLE pin_attempts (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    employee_id BIGINT NOT NULL,
    ip_address VARCHAR(45),
    device_fingerprint VARCHAR(255),
    success BOOLEAN DEFAULT FALSE,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    INDEX idx_employee_time (employee_id, attempted_at)
);
```

---

## Implementasi Bertahap

### Fase 1: Toggle + PIN Setup (MVP)

**Goal**: Admin bisa pilih mode login, karyawan bisa setup PIN.

**File yang dibuat:**

1. **Migration**
   - `add_cashier_login_mode_to_stores_table.php`
   - `add_pin_fields_to_employees_table.php`
   - `create_cashier_shifts_table.php`
   - `create_pin_attempts_table.php`

2. **Model**
   - `Employee` → tambah field `pin_hash`, `can_login_pos`
   - `Store` → tambah field `cashier_login_mode`
   - `CashierShift` (baru)

3. **Controller**
   - `CashierPinController` → setup/reset PIN per karyawan
   - `CashierShiftController` → buka/tutup shift
   - `PosLoginController` → login via PIN (jika mode = pin)

4. **Vue Pages**
   - `Settings/CashierLogin.vue` → toggle mode + setup PIN
   - `Pos/PinLogin.vue` → layar login PIN
   - `Pos/SelectCashier.vue` → pilih kasir dari daftar

5. **Service**
   - `CashierAuthService` → validasi PIN, rate limiting

**Estimasi**: 1-2 hari

---

### Fase 2: Shift Management

**Goal**: Flow buka/tutup shift, cash count.

**File yang dibuat:**

1. **Controller**
   - `ShiftController` → buka/tutup shift, cash count

2. **Vue Pages**
   - `Pos/OpenShift.vue` → input opening balance
   - `Pos/CloseShift.vue` → input closing balance + cash count
   - `Pos/ShiftReport.vue` → laporan shift

3. **Service**
   - `ShiftService` → hitung total transaksi, selisih uang

**Estimasi**: 2-3 hari

---

### Fase 3: Supervisor Override

**Goal**: Void/refund/diskon butuh PIN supervisor.

**File yang dibuat:**

1. **Model**
   - `Employee` → tambah field `is_supervisor`, `supervisor_pin_hash`

2. **Controller**
   - `SupervisorOverrideController` → validasi PIN supervisor

3. **Vue Components**
   - `SupervisorPinModal.vue` → popup input PIN supervisor

**Estimasi**: 2-3 hari

---

### Fase 4: Advanced (Opsional)

**Fitur:**
- Multi-device session (1 kasir login di beberapa device)
- Offline mode (PIN di-cache, sync saat online)
- Biometric login (fingerprint/face ID untuk device tertentu)
- Shift rotation scheduler

**Estimasi**: 3-5 hari

---

## Best Practices

### 1. Keamanan PIN

**Wajib:**
- Hash PIN dengan bcrypt (cost factor minimal 10)
- PIN 4-6 digit angka saja
- Rate limit: 5x salah PIN → lock 15 menit
- Log semua percobaan login (sukses/gagal)
- PIN tidak boleh sama dengan 3 PIN terakhir
- Admin bisa force reset PIN

**Hindari:**
- PIN mudah (1234, 0000, 1111, 1212)
- PIN sama dengan tanggal lahir
- Menyimpan PIN plain text
- PIN sama untuk beberapa karyawan

**Validasi PIN:**
```php
public function validatePin(string $pin): bool
{
    // Minimal 4 digit
    if (strlen($pin) < 4) return false;
    
    // Hanya angka
    if (!ctype_digit($pin)) return false;
    
    // Bukan pola mudah
    $easyPins = ['1234', '0000', '1111', '1212', '4321'];
    if (in_array($pin, $easyPins)) return false;
    
    return true;
}
```

### 2. UX Flow

**Layar Pilih Kasir (Mode PIN):**
```
┌─────────────────────────────────┐
│   [Logo Toko]                   │
│                                 │
│   Pilih Kasir:                  │
│   ┌───────┐  ┌───────┐         │
│   │  🧑   │  │  🧑   │         │
│   │ Budi  │  │ Siti  │         │
│   └───────┘  └───────┘         │
│   ┌───────┐  ┌───────┐         │
│   │  🧑   │  │  🧑   │         │
│   │ Andi  │  │ Dewi  │         │
│   └───────┘  └───────┘         │
│                                 │
│   Atau input PIN manual:        │
│   [________________]            │
│                                 │
│   [Kembali ke Login Email]      │
└─────────────────────────────────┘
```

**Layar Input PIN:**
```
┌─────────────────────────────────┐
│   Kasir: Budi                   │
│                                 │
│   Input PIN:                    │
│   ┌───┐ ┌───┐ ┌───┐ ┌───┐     │
│   │ * │ │ * │ │ * │ │ * │     │
│   └───┘ └───┘ └───┘ └───┘     │
│                                 │
│   ┌───┐ ┌───┐ ┌───┐            │
│   │ 1 │ │ 2 │ │ 3 │            │
│   └───┘ └───┘ └───┘            │
│   ┌───┐ ┌───┐ ┌───┐            │
│   │ 4 │ │ 5 │ │ 6 │            │
│   └───┘ └───┘ └───┘            │
│   ┌───┐ ┌───┐ ┌───┐            │
│   │ 7 │ │ 8 │ │ 9 │            │
│   └───┘ └───┘ └───┘            │
│         ┌───┐ ┌───┐            │
│         │ 0 │ │ ← │            │
│         └───┘ └───┘            │
│                                 │
│   [Batal]                       │
└─────────────────────────────────┘
```

### 3. Audit Trail

**Setiap transaksi harus record:**
```php
$order = Order::create([
    'store_id' => $store->id,
    'employee_id' => $activeCashier->id, // ← WAJIB
    'cashier_shift_id' => $shift->id,    // ← WAJIB jika mode PIN
    'cash_drawer_id' => $drawer->id,
    'total' => $total,
]);
```

**Log yang di-track:**
- Login/logout kasir
- Ganti shift (A → B)
- Opening/closing balance
- Void/refund (butuh supervisor PIN)
- Diskon manual (butuh supervisor PIN)
- Buka laci tanpa transaksi

### 4. Supervisor Override

**Aksi yang butuh PIN supervisor:**

| Aksi | Butuh PIN | Alasan |
|------|-----------|--------|
| Void transaksi | Ya | Cegah fraud |
| Refund | Ya | Cegah fraud |
| Diskon > 10% | Ya | Approval limit |
| Buka laci manual | Ya | Security |
| Print ulang struk | Tidak | Convenience |
| Ganti kasir | Ya (kasir itu sendiri) | Security |
| Hapus item dari cart | Tidak | Convenience |

### 5. Shift Management

**Best Practice:**
- 1 kasir bisa punya beberapa shift dalam 1 hari
- Shift otomatis close jika tidak ada transaksi > 12 jam
- Closing shift wajib cash count (hitung uang di laci)
- Selisih uang = shortage/overage → log ke laporan
- Shift yang masih open tidak bisa dihapus

**Flow Tutup Shift:**
```
1. Kasir tap "Tutup Shift"
2. Sistem hitung total transaksi shift ini
3. Kasir hitung uang di laci
4. Input closing balance
5. Sistem hitung selisih:
   - Expected: opening + total penjualan
   - Actual: input dari kasir
   - Difference: actual - expected
6. Jika selisih ≠ 0 → minta alasan
7. Shift di-close → tidak bisa transaksi lagi
```

### 6. Multi-Device (Opsional)

**Skenario**: 1 kasir login di tablet + kasir utama

**Implementasi:**
- Session disimpan di database (bukan local storage)
- Setiap device punya `device_id` unik
- Transaksi dari device mana pun masuk ke shift yang sama
- Conflict resolution: transaksi terakhir yang menang

### 7. Offline Mode (Opsional)

**Skenario**: Internet mati, kasir tetap bisa transaksi

**Implementasi:**
- PIN di-cache di device (encrypted)
- Transaksi di-queue di local storage
- Saat online kembali → sync ke server
- Validasi PIN offline (hash PIN di-cache)

### 8. Regulasi & Compliance

**Untuk Audit Pajak:**
- Setiap struk harus ada nama kasir
- Log transaksi tidak boleh dihapus
- Void/refund harus ada alasan + supervisor approval
- Backup database harian

**Untuk Keamanan:**
- CCTV di area kasir
- Dual control untuk buka brankas
- Rotation shift (jangan 1 kasir terus di laci yang sama)

---

## Migration Strategy

### Untuk Toko yang Sudah Ada

**Opsi 1: Default ke Mode Email (Aman)**
```php
// Migration
$table->enum('cashier_login_mode', ['email', 'pin'])->default('email');
```

**Opsi 2: Setup Wizard**
```
1. Admin buka Settings → Cashier Login
2. Pilih mode: Email atau PIN
3. Jika pilih PIN:
   a. Setup PIN untuk setiap kasir
   b. Test login PIN
   c. Konfirmasi switch mode
```

### Backward Compatibility

**Mode Email (default):**
- Login seperti biasa → email + password
- Session browser → seperti sekarang
- Tidak ada perubahan untuk user yang sudah ada

**Mode PIN:**
- Login via PIN → session device
- Ganti shift tanpa logout
- Audit trail lebih detail (employee_id + shift_id)

---

## Testing Checklist

### Fase 1: PIN Login

- [ ] Admin bisa toggle mode login per toko
- [ ] Karyawan bisa setup PIN (4-6 digit)
- [ ] Validasi PIN kuat (tolak 1234, 0000, dll)
- [ ] Login via PIN berhasil
- [ ] Rate limiting: 5x salah → lock 15 menit
- [ ] Admin bisa reset PIN karyawan
- [ ] Mode email tetap berfungsi normal

### Fase 2: Shift Management

- [ ] Kasir bisa buka shift (input opening balance)
- [ ] Kasir bisa tutup shift (input closing balance)
- [ ] Cash count: expected vs actual
- [ ] Selisih uang → minta alasan
- [ ] Shift yang open tidak bisa dihapus
- [ ] Laporan shift detail

### Fase 3: Supervisor Override

- [ ] Void transaksi butuh PIN supervisor
- [ ] Refund butuh PIN supervisor
- [ ] Diskon > 10% butuh PIN supervisor
- [ ] Log semua supervisor override

### Fase 4: Advanced

- [ ] Multi-device session
- [ ] Offline mode
- [ ] Sync transaksi offline

---

## Rollback Plan

Jika ada masalah setelah deploy:

1. **Toggle kembali ke mode email**
   - Admin buka Settings → Cashier Login
   - Pilih "Email/Password"
   - Save → sistem kembali ke mode lama

2. **Data tetap aman**
   - PIN tidak dihapus (hanya tidak dipakai)
   - Shift history tetap ada
   - Audit trail tidak hilang

---

## Estimasi Total

| Fase | Effort | Priority |
|------|--------|----------|
| Fase 1: PIN Login | 1-2 hari | High |
| Fase 2: Shift Management | 2-3 hari | Medium |
| Fase 3: Supervisor Override | 2-3 hari | Medium |
| Fase 4: Advanced | 3-5 hari | Low |
| **Total** | **8-13 hari** | |

---

## Rekomendasi

**Mulai dari Fase 1** karena:
- Paling sederhana (tambah toggle + setup PIN)
- Tidak破坏 sistem yang sudah ada
- Langsung bisa dipakai (kasir tidak perlu logout)
- Fondasi untuk fase selanjutnya

**Tidak perlu implement semua fase sekaligus.** Fase 1 saja sudah cukup untuk kebutuhan dasar.

---

## Catatan Implementasi

### File yang Akan Dibuat

```
database/migrations/
├── add_cashier_login_mode_to_stores_table.php
├── add_pin_fields_to_employees_table.php
├── create_cashier_shifts_table.php
└── create_pin_attempts_table.php

app/Modules/Pos/
├── Models/
│   ├── CashierShift.php
│   └── PinAttempt.php
├── Http/
│   ├── Controllers/
│   │   ├── CashierPinController.php
│   │   ├── CashierShiftController.php
│   │   └── PosLoginController.php
│   └── Middleware/
│       └── EnsureCashierLoggedIn.php
└── Services/
    └── CashierAuthService.php

resources/js/pages/
├── Settings/
│   └── CashierLogin.vue
└── Pos/
    ├── PinLogin.vue
    ├── SelectCashier.vue
    ├── OpenShift.vue
    └── CloseShift.vue
```

### Konfigurasi

```php
// config/cashier.php
return [
    'pin_min_length' => 4,
    'pin_max_length' => 6,
    'max_attempts' => 5,
    'lockout_duration' => 15, // menit
    'easy_pins' => ['1234', '0000', '1111', '1212', '4321'],
];
```

---

**Terakhir diupdate:** 16 Agustus 2026
**Status:** Planning - Menunggu approval untuk mulai implementasi
