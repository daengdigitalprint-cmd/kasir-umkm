# Rencana Implementasi Order Status System (Open Bills) — F&B/Restoran

**Modul**: Order Status (OPEN / PAID / CANCEL)  
**Direksi**: Untuk bisnis F&B / Bakery yang sudah mengaktifkan modul Meja / Dapur.  
**Pendekatan**: *Additive layer* — tidak mengubah struktur transaksi yang ada, hanya menambah kolom `order_status` dan workflow hold-to-pay.

---

## 1. Konsep Inti Status Order

| Status   | Kondisi Penggunaan                                                                 | Pemicu                          |
|---------|------------------------------------------------------------------------------------|---------------------------------|
| OPEN    | Meja sudah dipesan, KOT dicetak, dapur sedang memasak, belum ada pembayaran        | Kasir klik "Simpan & Cetak KOT" |
| PAID    | Pelanggan/biller (bisa grup meja) sudah melunasi tagihannya                        | Kasir pilih meja → bayar        |
| CANCEL  | Order dibatalkan sebelum pembayaran (misalnya stok habis / pelanggan batal)         | Kasir klik tombol "Batal"       |

> **Catatan**: Kolom `order_status` sudah ada di migration
> `2026_08_22_210000_add_order_and_payment_status_to_transactions_table.php`.

---

## 2. Spesifikasi Teknis

### 2.1 Backend — Controller & Route Baru

| Aksi                | Endpoint                                      | Method | Auth role | Payload / Return                                        |
|---------------------|-----------------------------------------------|--------|-----------|---------------------------------------------------------|
| Hold order (simpan OPEN) | POST `/pos/order-hold`                       | POST   | kasir     | items, table_ref, waiter, order_type → transaction id   |
| Daftar open bills   | GET  `/pos/order-holds`                       | GET    | kasir     | list transaction + table + total + created_at           |
| Load open bill      | GET  `/pos/order-holds/{id}`                  | GET    | kasir     | full transaction + items + cart                         |
| Pay from open bill  | POST `/pos/order-holds/{id}/pay`              | POST   | kasir     | amount_paid, payment_method → receipt / change          |
| Cancel open bill    | POST `/pos/order-holds/{id}/cancel`           | POST   | kasir     | reason (string) → sets order_status = cancelled           |
| Update status queue | PATCH `/pos/order-holds/{id}/status`          | PATCH  | kasir     | hanya untuk internal sync dapur                         |

> **Integrasi KOT**: reuse method `kitchenTicketPrint()` yang sudah ada di
> [POSController.php](app/Http/Controllers/POSController.php).

### 2.2 Frontend — Vue Composants (Inertia)

**`resources/js/pages/POS/Index.vue`**

1. **Tombol "Simpan & Cetak KOT"** → side drawer cart baru:
   - Input nomor meja / waiter / order_type
   - Checkbox print KOT otomatis (reuse Bluetooth/usb printer)
   - Save → API `POST /pos/order-hold` → clear cart + notifikasi sukses
2. **Tombol Open Bills (top bar)** → badge hitung open bills:
   - Badge hijau 1-dot jika ada order OPEN
   - Klik → modal daftar open bills terbaru (drawer kanan)
3. **Modal open-bill list** — kolom: meja, pelayan, total, dibuat, aksi:
   - Lihat / lanjut bayar
   - History transaksi open-bill (PAID/CANCEL)
4. **Load open bill ke cart** — reuse `addItem() / removeItem()` yang sudah ada di POSController:
   - GET `/pos/order-holds/{id}` → populate cart
   - Toggle status tombol bayar → `POST /pos/order-holds/{id}/pay`
5. **Badge status per item cart** — kecil di bawah nama produk:
   - Hijau OPEN
   - Biru PAID (sudah bayar bagian)
   - Abu-abu CANCEL

> **Scan Meja Integration** (lihat `TableOrderController`): order meja scan
> sudah memakai `order_status` = `pending/accepted/completed`. Workflow open-bill
> ini **hanya untuk transaksi POS kasir langsung**, tidak mengganggu alur scan meja.

### 2.3 Database Migration

```sql
ALTER TABLE transactions
  ADD COLUMN order_status ENUM('open','paid','cancelled')
       DEFAULT 'open'
       AFTER payment_status;

CREATE INDEX idx_txn_order_status ON transactions (order_status);
CREATE INDEX idx_txn_date_status  ON transactions (store_id, order_status, created_at);
```

> Field ini sudah otomatis terisi `'open'` untuk transaksi lama karena `DEFAULT 'open'`.

### 2.4 Akuntansi

| Order Status | Jurnal Umum                                   | Timing                                   |
|-------------:|----------------------------------------------|------------------------------------------|
| OPEN         | *(tidak ada)*                                | saat order masuk                         |
| PAID         | Kas/Kartu  + Penjualan  + PPN  + PPN Payable | **setelah payment sukses**               |
| CANCEL       | *(tidak ada)*                                | saat order dibatalkan                     |

> Jurnal kas hanya dibuat saat `order_status` berubah `→ PAID`.

---

## 3. Alur Semi-Modern (Workflow Kasir)

```mermaid
flowchart LR
  subgraph Pelayan
    A[Pesan pelanggan] --> B[Catat order di POS]
    B --> C[Klik 'Simpan & Cetak KOT']
  end

  subgraph Kasir_POS
    C --> D{Auto print KOT?}
    D -- Ya --> E[Cetak KOT → dapur]
    D -- Tidak --> G[Order tersimpan OPEN]
    E --> G
  end

  subgraph Dapur
    G --> H{Selesai masak?}
    H -- Ya --> I[Cascade: mark items ready]
  end

  subgraph Pelanggan
    I --> J[Pelanggan minta bill]
  end

  subgraph Kasir_Bayar
    J --> K[Buka Open Bill]
    K --> L[Pilih meja / scan barcode meja]
    L --> M[Input pembayaran]
    M --> N[POST /pos/order-holds/{id}/pay]
    N --> O[order_status = PAID]
  end

  subgraph Akuntansi
    O --> P[Jurnal kas + penjualan tercatat]
    G -.->|Order batal| Q[order_status = CANCEL → tidak ada jurnal]
  end
```

---

## 4. Langkah Implementasi

### Fase 1 — Backend (2–3 hari)

| # | Tugas                               | Estimasi | Output              |
|---|-------------------------------------|----------|---------------------|
| 1 | Migration kolom `order_status`      | 2 jam    | SQL schema          |
| 2 | Endpoint `/pos/order-hold` (CRUD)   | 1 hari   | API resource          |
| 3 | Reuse KOT print di `processPayment` | 1 hari   | KOT dicetak otomatis  |
| 4 | Akuntansi jurnal delay sampai PAID  | 1 hari   | Jurnal sesuai status   |

### Fase 2 — Frontend POS (2–3 hari)

| # | Tugas                                      | Estimasi | Output                  |
|---|--------------------------------------------|----------|-------------------------|
| 1 | Tombol hold + drawer simpan meja           | 1 hari   | `SaveOrderDrawer.vue`   |
| 2 | Topbar badge open-bills + drawer list      | 1 hari   | `OpenBillList.vue`      |
| 3 | Load open-bill ke cart + bayar             | 1 hari   | reuse `processPayment`  |
| 4 | Badge status per item cart                 | ½ hari   | inline badge di item    |
| 5 | Filter open-bill (meja / tanggal / pelayan) | ½ hari   | search box di drawer   |

### Fase 3 — Testing (2 hari)

| Skenario Uji                 | Langkah                                   | Expected Outcome                           |
|------------------------------|-------------------------------------------|---------------------------------------------|
| Simpan order tanpa bayar      | Kasir pilih produk → Simpan & Cetak KOT    | Order masuk daftar OPEN, KOT tercetak        |
| Bayar dari open bill          | Kasir pilih meja → bayar                    | Status → PAID, jurnal kas terbuat            |
| Batal 1 item di open bill     | Kasir batalkan item → simpan                | Order tetap OPEN, item dihapus, tidak ada jurnal |
| Multiple meja → 1 payment     | Pilih 2 open bill → merge ke 1 payment      | Semua order → PAID, jurnal gabungan          |
| Scan meja unaffected          | Pelanggan scan meja urut normal            | Alur scan meja tetap pakai order_status internal |

---

## 5. Kriteria Keberhasilan

| KPI                          | Target                              |
|------------------------------|-------------------------------------|
| Waktu dari order → KOT print  | ≤ 5 detik                            |
| Waktu dari bayar → jurnal     | ≤ 3 detik                            |
| % open-bill yang dibayar 100% | 90% (sisa 10% batal/retur)           |
| Tidak double-print KOT        | 0 duplicate KOT                      |
| Akuntansi konsisten           | Selisih buku besar = 0 (test closing)|

---

## 6. Best Practice Direkomendasikan

1. **Order number tetap unik & terus-menerus** — jangan reset tiap shift.
2. **Kitchen sync via WebSocket** (opsional Fase 2): dapur otomatis refresh saat status order berubah.
3. **Auto-expire open bill** — open bill lebih dari 4 jam → *warning merah* di list (opsional).
4. **Merge multiple tables to one bill** — kasir pilih 2-3 open bill → 1 pembayaran.
5. **Audit trail** — log setiap transisi status (ORDER_STATUS_LOG table).

---

## 7. Risiko & Mitigasi

| Risiko                        | Dampak                     | Mitigasi                        |
|-------------------------------|----------------------------|----------------------------------|
| Kasir lupa bayar open bill   | Open bill menumpuk         | Auto-flag > 1 jam, alarm beep   |
| Double payment                | Overcharge pelanggan       | Guard: cek order_status PAID    |
| KOT ke dua meja sama waktu    | Dapur bingung               | KOT print meja number + order#  |
| Shift tutup belum bayar       | Saldo shift tidak akurat    | Force close all open bills      |

---

Dokumen ini siap direview → lanjutan ke task list / migration SQL / Vue component.
