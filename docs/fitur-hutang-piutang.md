# Rencana Implementasi — Fitur Piutang Pelanggan (Penjualan Kredit)

> Dokumen rencana. **Belum ada kode yang diubah** sampai rencana disetujui.
> Status kesulitan keseluruhan: **Tinggi**, karena mencakup integritas jurnal dan arus kas.

---

## 1. Konteks & Tujuan

Aplikasi POS saat ini menganggap transaksi selesai sebagai pembayaran lunas. UMKM ritel juga membutuhkan penjualan kepada pelanggan langganan dengan pembayaran sebagian atau dibayar belakangan.

Tujuan implementasi:

1. Menyelesaikan penjualan walaupun pelanggan baru membayar sebagian atau belum membayar.
2. Mengakui pendapatan, PPN, HPP, dan pengurangan persediaan penuh pada tanggal penjualan.
3. Mencatat saldo yang belum dibayar sebagai **Piutang Usaha** pelanggan.
4. Menerima pelunasan berikutnya tanpa mengakui pendapatan atau penjualan kedua kali.
5. Menjaga saldo kas shift, cash-flow, jurnal, neraca, dan laporan laba rugi tetap akurat.

### Batasan MVP

- Satu penerimaan pembayaran hanya dialokasikan ke **satu transaksi**.
- Penjualan kredit dan pelunasan piutang **diblokir saat POS offline**.
- Credit limit, tenor default pelanggan, dan satu pembayaran untuk banyak transaksi ditunda ke tahap berikutnya.
- Piutang adalah **status pelunasan**, bukan sekadar metode pembayaran. Pembayaran awal tetap dapat memakai `cash`, `card`, `ewallet`, `transfer`, atau `other`.

---

## 2. Fondasi yang Sudah Ada

| Fondasi | Lokasi | Pemanfaatan |
|---|---|---|
| Akun Piutang Usaha `1.3.01` | `ChartOfAccountsBootstrap.php` | Debit piutang saat penjualan belum lunas; kredit saat pelunasan. |
| Field transaksi pelanggan dan pembayaran | `Transaction.php` | `customer_id`, `amount_paid`, `payment_status`, dan `payment_details` sudah tersedia. |
| Pola jurnal pembelian kredit | `JournalPoster::postPurchase` | Referensi split nilai dibayar dan sisa hutang. |
| Guard periode akuntansi | `AccountingPeriodGuard::assertDateOpen` | Wajib pada jurnal penjualan kredit dan pelunasan. |
| Penerimaan pembayaran pembelian | `PurchasePaymentController` | Acuan struktur endpoint, transaksi database, dan pencatatan pembayaran. |
| Aging hutang | `AgingPayablesController` dan `AgingPayables.vue` | Template query dan UI laporan aging piutang. |
| Detail pelanggan | `CustomerController::show` dan `Customers/Show.vue` | Lokasi natural untuk invoice terbuka serta histori pelunasan. |
| Menu aplikasi | `MainMenu.vue` | Tambahkan menu Piutang di bagian Pelanggan & Promo. |

---

## 3. Masalah yang Wajib Diselesaikan

### 3.1 Checkout POS saat ini memblokir piutang

`POSController::processPayment` hanya menerima metode pembayaran reguler dan menolak `amount_paid` yang kurang dari sisa tagihan. Frontend POS juga menonaktifkan proses bila pembayaran kurang dari total.

Perubahan yang dibutuhkan:

- Terima `customer_id` dan verifikasi pelanggan milik toko aktif.
- Jika ada saldo tersisa, pelanggan wajib dipilih.
- Tambahkan `debt_due_at` yang nullable.
- Tetapkan `payment_status` dengan nilai `paid`, `partial`, atau `unpaid`.
- Jangan menggunakan pencarian pelanggan hanya berdasarkan nama untuk transaksi kredit; gunakan customer selector berbasis ID.

### 3.2 Jurnal saat ini selalu menganggap seluruh invoice sebagai kas/bank

`JournalPoster::postSaleTransaction` mendebit total invoice ke Kas/Bank. Ini tidak benar untuk pembayaran parsial atau kredit karena kas/bank harus hanya sebesar nominal yang benar-benar diterima.

Jurnal wajib saat penjualan selesai:

```text
Debit  Kas/Bank             = uang yang diterima saat penjualan
Debit  Piutang Usaha        = saldo invoice belum diterima
Kredit Pendapatan           = nilai penjualan sebelum pajak
Kredit Utang PPN Keluaran   = PPN, jika ada
Kredit Pendapatan Tip       = tip, jika ada

Debit  HPP
Kredit Persediaan
```

Jurnal wajib saat pelunasan:

```text
Debit  Kas/Bank             = nominal pelunasan
Kredit Piutang Usaha        = nominal pelunasan
```

Pelunasan tidak boleh memposting pendapatan, PPN, HPP, ataupun persediaan kembali.

### 3.3 Kas shift dan cash-flow saat ini memakai total invoice

Cash shift menghitung penjualan tunai dengan `SUM(total_amount)`. Setelah piutang aktif, perhitungan harus memakai pembayaran aktual yang masuk ke laci kas. Pelunasan piutang tunai juga harus masuk ke shift tempat pelunasan diterima.

### 3.4 Belum ada ledger pembayaran piutang

`amount_paid` hanya bersifat agregat dan tidak cukup sebagai audit trail. Diperlukan tabel pembayaran terpisah untuk menyimpan setiap penerimaan, metode bayar, penerima, waktu terima, shift kas, serta referensi jurnal.

---

## 4. Desain Data

### 4.1 Perubahan tabel `transactions`

Tambahkan kolom:

- `debt_due_at` (`timestamp`, nullable).

Tambahkan indeks untuk daftar dan aging:

- `store_id`, `customer_id`, `payment_status`;
- `store_id`, `payment_status`, `debt_due_at`.

Saldo transaksi dibaca secara dinamis:

```text
outstanding_amount = max(0, total_amount - amount_paid)
```

Tambahkan pada model `Transaction`:

- `debt_due_at` dalam `$fillable` dan `$casts` sebagai `datetime`;
- relasi `receivablePayments()`;
- accessor `outstanding_amount`.

### 4.2 Tabel baru `receivable_payments`

| Kolom | Keterangan |
|---|---|
| `id` | Primary key |
| `transaction_id` | FK invoice/transaksi yang dilunasi |
| `store_id` | FK toko untuk isolasi data antar-outlet |
| `customer_id` | FK pelanggan untuk query riwayat cepat |
| `user_id` | Pengguna yang menerima pembayaran |
| `cash_shift_id` | Nullable; wajib terisi untuk pembayaran tunai oleh kasir aktif |
| `amount` | Decimal `15,2`, nominal penerimaan |
| `payment_method` | `cash`, `card`, `ewallet`, `transfer`, atau `other` |
| `received_at` | Waktu pembayaran diterima |
| `journal_entry_id` | Nullable, FK jurnal pelunasan |
| `reference_number` | Nullable, nomor referensi transfer/kartu |
| `notes` | Nullable |
| `idempotency_key` | Nullable dan unik per toko untuk mencegah penerimaan ganda |
| `created_by` | FK user pembuat record |
| `timestamps` | Audit bawaan |

Indeks minimum:

- `store_id`, `transaction_id`;
- `store_id`, `customer_id`;
- unique `store_id`, `idempotency_key` bila key tidak null.

> Pembayaran dibuat dalam `DB::transaction()` dan transaksi target dikunci dengan `lockForUpdate()` agar dua pengguna tidak dapat melunasi saldo yang sama secara bersamaan.

---

## 5. Tahapan Implementasi

### Tahap 1 — Fondasi piutang dan jurnal penjualan (wajib, risiko tinggi)

**Migrasi dan model**

1. Tambahkan `debt_due_at` dan indeks ke `transactions`.
2. Buat migrasi, model, relasi, dan foreign key untuk `ReceivablePayment`.
3. Tambahkan accessor saldo outstanding pada `Transaction`.

**POS backend**

1. Terima `customer_id` dan `debt_due_at` pada checkout.
2. Validasi customer berada pada `store_id` aktif.
3. Tentukan status:
   - `paid` bila saldo outstanding nol;
   - `partial` bila pembayaran awal lebih dari nol dan masih ada saldo;
   - `unpaid` bila pembayaran awal nol.
4. Wajibkan `customer_id` bila `outstanding_amount > 0`.
5. Pertahankan alur stok, loyalty, aktivitas, HPP, dan penyelesaian transaksi pada saat penjualan selesai.
6. Blokir checkout kredit ketika request berasal dari antrean offline.

**Jurnal penjualan**

1. Ubah `JournalPoster::postSaleTransaction` untuk memuat akun Piutang Usaha.
2. Debit Kas/Bank hanya sebesar pembayaran aktual, setelah memperhitungkan uang kembalian.
3. Debit Piutang Usaha sebesar saldo outstanding.
4. Pertahankan kredit pendapatan, PPN, tip, serta jurnal HPP/persediaan berdasarkan total penjualan.
5. Tetap gunakan `AccountingPeriodGuard::assertDateOpen`.

**Kriteria selesai**

- Jurnal transaksi partial/unpaid seimbang.
- Neraca menampilkan piutang sebesar saldo tagihan.
- Laba rugi menampilkan pendapatan penuh pada tanggal penjualan.
- Penjualan kredit tidak dapat dibuat secara offline.

### Tahap 2 — Penerimaan pelunasan dan pembaruan kas (wajib, risiko tinggi)

**Backend penerimaan pembayaran**

1. Buat `ReceivablePaymentController::store` dan route `POST /receivable-payments`.
2. Validasi transaksi target: milik toko aktif, berstatus selesai, punya pelanggan, dan outstanding lebih dari nol.
3. Kunci transaksi target, tolak nominal nol atau nominal melebihi outstanding.
4. Buat `receivable_payments`, kemudian tambahkan nominal ke `transactions.amount_paid`.
5. Ubah `payment_status` menjadi `partial` atau `paid` sesuai sisa akhir.
6. Hubungkan pembayaran tunai ke `cash_shift_id` shift aktif.
7. Gunakan idempotency key untuk mencegah penerimaan terduplikasi.

**Jurnal pelunasan**

1. Tambahkan `JournalPoster::postReceivableCollection`.
2. Debit Kas/Bank sesuai metode pembayaran penerimaan.
3. Kredit Piutang Usaha sebesar nominal penerimaan.
4. Jadikan `ReceivablePayment` sebagai referensi jurnal agar satu penerimaan hanya menghasilkan satu jurnal.
5. Jalankan guard periode akuntansi sebelum posting.

**Cash shift dan cash-flow**

1. Ubah expected cash shift dari total invoice menjadi pembayaran tunai aktual.
2. Sertakan pembayaran tunai awal pada penjualan dan pelunasan piutang tunai pada shift yang bersangkutan.
3. Pisahkan laporan: penjualan terinvoice, penerimaan penjualan baru, penerimaan pelunasan piutang, dan uang masuk laci kas.

**Kriteria selesai**

- Pelunasan tidak menciptakan pendapatan baru.
- Expected cash shift sama dengan kas nyata dari pembayaran tunai yang diterima.
- Pembayaran duplikat ditolak.

### Tahap 3 — UI POS untuk pembayaran parsial (risiko sedang)

**`resources/js/pages/POS/Index.vue`**

1. Pisahkan mode pelunasan dari metode bayar:
   - Lunas;
   - Bayar sebagian;
   - Bayar nanti.
2. Gunakan pemilih pelanggan berbasis `customer_id`; input nama hanya boleh sebagai alur buat pelanggan baru.
3. Jika ada saldo tersisa:
   - pelanggan wajib dipilih;
   - tampilkan nominal dibayar sekarang;
   - tampilkan sisa piutang;
   - tampilkan dan kirim tanggal jatuh tempo;
   - label aksi menjadi “Selesaikan Penjualan” atau “Simpan sebagai Piutang”.
4. Hentikan auto-fill nominal penuh ketika pengguna memilih mode pembayaran parsial.
5. Aktifkan tombol submit untuk nominal kurang dari total hanya jika mode piutang serta pelanggan valid.
6. Tampilkan alasan yang jelas bila pengguna mencoba transaksi kredit ketika offline.

**Kriteria selesai**

- POS hanya mengizinkan saldo tersisa bila pelanggan valid.
- Payload mengirim `customer_id`, nominal, status settlement, dan `debt_due_at`.
- UX pembayaran tunai lunas yang sudah ada tetap berjalan.

### Tahap 4 — Manajemen piutang pelanggan (risiko sedang)

**Rute dan controller**

1. Buat `ReceivableController` untuk daftar dan detail piutang.
2. Tambahkan route minimum:

```text
GET  /receivables
GET  /receivables/{transaction}
POST /receivable-payments
GET  /reports/aging-receivables
```

3. Batasi semua query per `store_id` aktif.
4. Terapkan kebijakan akses sesuai keputusan role.

**Halaman dan data pelanggan**

1. Tambahkan daftar Piutang: pencarian pelanggan, status, jatuh tempo, outstanding, dan overdue.
2. Tambahkan detail invoice: total, nominal dibayar, saldo, due date, serta riwayat `receivable_payments`.
3. Tambahkan form “Terima Pembayaran” pada detail invoice atau detail pelanggan.
4. Perluas `CustomerController::show` dan `Customers/Show.vue` dengan ringkasan saldo, invoice terbuka, serta histori pelunasan.
5. Tambahkan menu “Piutang” ke `MainMenu.vue` pada bagian Pelanggan & Promo, lengkap dengan `menuKey` dan konfigurasi izin.

**Kriteria selesai**

- Backoffice dapat menemukan setiap invoice bersaldo.
- Riwayat penerimaan dapat ditelusuri sampai jurnalnya.
- Kasir hanya dapat menerima pembayaran bila role yang dipilih mengizinkan.

### Tahap 5 — Aging, retur, pembatalan, dan audit lintas modul (risiko tinggi)

**Aging**

1. Adaptasi `AgingPayablesController` dan `AgingPayables.vue` untuk aging receivables.
2. Gunakan transaksi selesai, pelanggan terisi, outstanding lebih dari nol, dan `debt_due_at`.
3. Gunakan bucket yang konsisten: belum jatuh tempo, 1–30, 31–60, 61–90, dan lebih dari 90 hari.

**Retur dan pembatalan**

1. Tetapkan kebijakan sebelum implementasi: transaksi dengan outstanding tidak dapat dibatalkan langsung tanpa reversal piutang dan jurnal yang sesuai.
2. Retur mengurangi piutang terlebih dahulu; kelebihan setelah piutang lunas diproses sebagai pengembalian kas/bank.
3. Hindari perubahan langsung pada `amount_paid` tanpa record reversal yang dapat diaudit.

**Audit laporan**

1. Pastikan laporan penjualan memakai total invoice sebagai omzet, bukan collection piutang.
2. Pastikan cash-flow membedakan pendapatan akrual dan arus kas.
3. Verifikasi neraca memindahkan saldo dari Kas/Bank ke Piutang ketika pembayaran belum diterima.
4. Verifikasi loyalty/reward tetap dihitung dari total penjualan saat transaksi selesai.
5. Tambahkan pengujian untuk transaksi lunas, partial, unpaid, satu pelunasan, beberapa pelunasan, overpayment, retur, dan periode akuntansi tertutup.

---

## 6. Keputusan Produk yang Harus Disetujui Sebelum Eksekusi

1. **Penerima pelunasan:** hanya backoffice, hanya kasir, atau keduanya.
   - Rekomendasi: kasir dan backoffice, dengan pembayaran tunai kasir wajib masuk ke shift terbuka.
2. **Jatuh tempo:** wajib pada piutang atau opsional.
   - Rekomendasi: opsional untuk MVP, tetapi tersedia pada form agar aging berguna.
3. **Metode pembayaran awal:** apakah partial hanya tunai atau semua metode.
   - Rekomendasi: semua metode yang sudah didukung, karena piutang adalah status settlement.
4. **Hak akses menu Piutang:** role `backoffice`, role `pos`, atau permission terpisah.
   - Rekomendasi: daftar/report untuk backoffice; penerimaan pembayaran dapat diberikan ke kasir dengan permission khusus.
5. **Retur untuk transaksi berpiutang:** apakah diizinkan pada MVP.
   - Rekomendasi: tangguhkan UI retur piutang sampai reversal jurnal dan alokasi pembayaran diuji.

---

## 7. Urutan Rollout

1. **Tahap 1 dan Tahap 2 sebagai satu rilis akuntansi.** Jangan aktifkan opsi kredit di POS sebelum jurnal, ledger penerimaan, serta expected cash shift akurat.
2. Aktifkan UI POS pada Tahap 3 setelah rilis akuntansi lolos pengujian skenario lunas, partial, unpaid, dan pelunasan.
3. Rilis manajemen piutang pada Tahap 4 untuk backoffice terlebih dahulu.
4. Selesaikan aging serta audit retur/laporan pada Tahap 5 sebelum fitur diperluas ke offline, multi-invoice payment, atau credit limit.

---

## 8. File yang Diperkirakan Disentuh

**Backend dan domain**

- `app/Http/Controllers/POSController.php`
- `app/Http/Controllers/ReceivableController.php` (baru)
- `app/Http/Controllers/ReceivablePaymentController.php` (baru)
- `app/Http/Controllers/CashShiftController.php`
- `app/Http/Controllers/CashFlowController.php`
- `app/Http/Controllers/CustomerController.php`
- `app/Support/Accounting/JournalPoster.php`
- `app/Models/Transaction.php`
- `app/Models/ReceivablePayment.php` (baru)
- `routes/web.php`

**Migrasi**

- `add_debt_due_at_and_receivable_indexes_to_transactions_table` (baru)
- `create_receivable_payments_table` (baru)

**Frontend**

- `resources/js/pages/POS/Index.vue`
- `resources/js/pages/Customers/Show.vue`
- `resources/js/pages/Customers/Index.vue`
- `resources/js/pages/POS/Receivables/Index.vue` (baru)
- `resources/js/pages/POS/Receivables/Show.vue` (baru)
- `resources/js/pages/Reports/AgingReceivables.vue` (baru)
- `resources/js/components/MainMenu.vue`

---

## 9. Persetujuan

Rencana ini memasukkan temuan struktur aplikasi yang ada, terutama kebutuhan ledger pembayaran, split jurnal Kas/Bank dan Piutang Usaha, serta perhitungan kas shift berbasis pembayaran aktual.

Silakan:

- **Menyetujui seluruh rencana** untuk mulai implementasi;
- **Menyetujui tahap tertentu**; atau
- **Menentukan keputusan produk** pada bagian 6 sebelum eksekusi.
