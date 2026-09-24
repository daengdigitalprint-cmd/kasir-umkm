# Prompt Implementasi: Fitur Program Afiliasi — Kasir UMKM

## Konteks Proyek

- **Model bisnis fitur**: Program afiliasi dengan skema dual-incentive
  - Pelanggan yang dibawa affiliate → dapat **diskon**
  - Affiliate → dapat **komisi persentase** dari transaksi yang terjadi lewat link/kode referralnya
- **Akses affiliate**: tidak install aplikasi, cukup punya link referral unik
- **Fitur tambahan**: affiliate punya dashboard sendiri untuk memantau performa dan **mengajukan pencairan komisi** dengan memasukkan nomor rekening

---

## Tahap 1: Desain Database

Buat migration Laravel untuk tabel-tabel berikut:

- [ ] **`affiliates`**
  - `id`, `user_id` (nullable, jika affiliate juga user terdaftar) atau kolom identitas mandiri (`name`, `email`, `phone`)
  - `referral_code` (unique, string, misal `HADI10`)
  - `commission_percentage` (decimal, default global atau custom per affiliate)
  - `status` (enum: `pending`, `active`, `suspended`)
  - `bank_name`, `bank_account_number`, `bank_account_holder` (nullable, diisi saat pengajuan pencairan pertama)
  - timestamps

- [ ] **`affiliate_clicks`**
  - `id`, `affiliate_id`, `ip_address`, `user_agent`, `clicked_at`
  - Untuk tracking jumlah klik link referral

- [ ] **`affiliate_referrals`**
  - `id`, `affiliate_id`, `referred_customer_id` (atau email/identifier toko yang daftar)
  - `discount_applied` (nominal/persentase diskon yang diberikan)
  - `converted_at`

- [ ] **`affiliate_transactions`**
  - `id`, `affiliate_id`, `referral_id` (FK ke `affiliate_referrals`), `order_id`/`subscription_id`
  - `transaction_amount`, `commission_amount`
  - `status` (enum: `pending`, `approved`, `paid`, `cancelled`) — pending saat transaksi baru masuk, approved setelah lewat masa refund, cancelled jika ada refund

- [ ] **`affiliate_withdrawals`**
  - `id`, `affiliate_id`, `amount_requested`
  - `bank_name`, `bank_account_number`, `bank_account_holder` (snapshot saat pengajuan)
  - `status` (enum: `requested`, `processing`, `completed`, `rejected`)
  - `requested_at`, `processed_at`, `admin_note`

---

## Tahap 2: Logika Tracking Referral

- [ ] Buat middleware/handler untuk menangkap parameter `?ref=KODE` di URL landing page
- [ ] Simpan `referral_code` ke cookie/session dengan masa berlaku (tentukan: 30 hari standar)
- [ ] Catat setiap kunjungan unik ke tabel `affiliate_clicks` (hindari duplikat dari refresh berulang, misal throttle per IP per jam)
- [ ] Saat proses pendaftaran/checkout toko baru:
  - Cek apakah ada `referral_code` tersimpan di cookie/session
  - Jika ada dan valid → terapkan diskon otomatis, catat ke `affiliate_referrals`
- [ ] Saat transaksi/pembayaran subscription toko tersebut terjadi (termasuk transaksi berulang jika komisinya recurring):
  - Hitung komisi berdasarkan `commission_percentage` affiliate
  - Insert ke `affiliate_transactions` dengan status `pending`

**Keputusan yang perlu dikonfirmasi sebelum lanjut coding:**
- [ ] Komisi dihitung dari harga sebelum atau sesudah diskon diterapkan?
- [ ] Komisi berlaku sekali (saat pendaftaran pertama) atau recurring (tiap kali toko bayar subscription)?
- [ ] Berapa lama masa tunggu `pending` → `approved` (misal 7-14 hari untuk antisipasi refund)?

---

## Tahap 3: Dashboard Affiliate (Frontend Vue 3)

- [ ] Halaman login/akses affiliate terpisah dari dashboard merchant utama (bisa pakai auth guard sendiri atau reuse sistem user dengan role `affiliate`)
- [ ] **Halaman Overview**:
  - Link referral unik (dengan tombol copy)
  - Statistik: total klik, total konversi (toko yang daftar), conversion rate
  - Total komisi: pending, approved, sudah dicairkan
- [ ] **Halaman Riwayat Transaksi**:
  - List transaksi dari referral, dengan status komisi masing-masing
- [ ] **Halaman Pencairan (Withdrawal)**:
  - Form input/edit nomor rekening (`bank_name`, `bank_account_number`, `bank_account_holder`)
  - Tombol "Ajukan Pencairan" — hanya aktif jika saldo komisi `approved` ≥ minimal payout (tentukan nominalnya)
  - List riwayat pengajuan pencairan beserta statusnya

---

## Tahap 4: Panel Admin (untuk Kasir UMKM)

- [ ] List semua affiliate + statistik performa masing-masing
- [ ] Approve/reject affiliate baru (jika pendaftaran affiliate perlu moderasi)
- [ ] List pengajuan pencairan yang masuk (`affiliate_withdrawals` status `requested`)
  - Admin bisa ubah status ke `processing` → `completed` (setelah transfer manual dilakukan)
  - Atau `rejected` dengan catatan alasan
- [ ] Setting global: default `commission_percentage`, minimal nominal payout, masa cookie referral

---

## Tahap 5: Notifikasi

- [ ] Notifikasi ke affiliate (email/WA) saat:
  - Referral baru berhasil konversi
  - Komisi berubah status jadi `approved`
  - Pengajuan pencairan diproses/selesai
- [ ] Notifikasi ke admin saat ada pengajuan pencairan baru

---

## Tahap 6: Testing & Edge Cases

- [ ] Test skenario refund → pastikan komisi terkait otomatis jadi `cancelled`
- [ ] Test klik ganda dari affiliate yang sama (tidak boleh double count di beberapa jam yang sama)
- [ ] Test toko yang sudah pernah daftar lalu klik link referral baru (tidak boleh re-attribute)
- [ ] Test pengajuan pencairan melebihi saldo `approved` (harus ditolak sistem)
- [ ] Validasi format nomor rekening (angka saja, panjang sesuai bank)

---

## Catatan untuk AI Coding Tool (Cursor/dsb.)
Kerjakan bertahap sesuai urutan di atas. Setelah Tahap 1 (migration) selesai, konfirmasi struktur tabel dulu sebelum lanjut ke logika tracking di Tahap 2. Ikuti konvensi Laravel yang sudah ada di project (naming, service/repository pattern jika dipakai).
