# Rencana Implementasi Fitur AI Auditor (Super Admin)

## 1. Ringkasan Fitur
Fitur AI Auditor dirancang untuk bertindak sebagai asisten analitik bagi Super Admin. AI akan meninjau data transaksi, laporan kas, dan pergerakan stok untuk menemukan anomali seperti:
- Harga Jual di bawah HPP (Harga Pokok Penjualan).
- Ketidaksinkronan antara laporan kas dan total penjualan.
- Kesalahan input data atau kuantitas oleh kasir/user tertentu.

**Aturan Ketat (Sistem):**
- **Read-Only:** AI murni hanya melakukan analisa dan memberikan laporan teks. AI TIDAK MEMILIKI akses untuk mengeksekusi perintah SQL (Insert, Update, Delete).
- **Otorisasi:** Hanya dapat diakses oleh akun dengan *role* Super Admin.
- **Filter Spesifik:** Super Admin dapat memilih spesifik akun user (pemilik bisnis/penjual) yang akan dianalisa transaksinya.
- **Ekspansi Masa Depan (Tenant Access):** Fitur ini dirancang agar nantinya Super Admin dapat memberikan akses fitur AI Auditor kepada akun Pemilik Bisnis tertentu, sehingga pemilik bisnis bisa mengaudit data usahanya sendiri.

---

## 2. Arsitektur & Alur Kerja

1. **Super Admin (Frontend Vue.js):** 
   - Membuka halaman AI Auditor.
   - Memilih rentang waktu (tanggal) dan **memilih akun user penjual** dari *dropdown*.
   - Menekan tombol "Mulai Analisa".
2. **Backend (Laravel):**
   - Menerima request (mengandung `user_id` dan rentang tanggal).
   - Memvalidasi bahwa yang request benar-benar Super Admin.
   - Mengambil data dari database (tabel `transactions`, `transaction_items`, `cash_flows`, dll) sesuai filter.
   - Memformat data menjadi JSON yang ringkas (membuang kolom yang tidak perlu untuk menghemat *token* AI).
3. **Integrasi AI (LLM Provider seperti OpenAI / Claude):**
   - Backend mengirimkan JSON beserta *System Prompt* ke API AI.
4. **Respon:**
   - AI mengembalikan analisa berbasis teks.
   - Backend meneruskan teks ke Frontend untuk ditampilkan.

---

## 3. Rencana Fase Pengerjaan

### Fase 1: Persiapan Backend & Integrasi AI (Laravel AI SDK)
- [ ] Menginstal Laravel AI SDK menggunakan `composer require laravel/ai`.
- [ ] Mempublikasikan konfigurasi dengan `php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider"`.
- [ ] Menjalankan migrasi database (`php artisan migrate`) untuk menyiapkan tabel percakapan agen bawaan dari SDK.
- [ ] Menambahkan Kunci API (API Key) dari LLM Provider (misal: `OPENAI_API_KEY` atau `ANTHROPIC_API_KEY`) ke dalam file `.env`.
- [ ] Membuat class Agen khusus menggunakan `php artisan make:agent AuditorAgent --structured`.
- [ ] Menyiapkan Instruksi (System Prompt) di dalam method `instructions()` pada `AuditorAgent`. (Contoh: *"Anda adalah auditor..."*).
- [ ] Menyiapkan format hasil output yang terstruktur (JSON Schema) di method `schema()` pada `AuditorAgent` untuk memastikan respon selalu berisi array `anomalies` dan `summary`.
- [ ] Membuat Route API khusus (contoh: `POST /api/ai-auditor/analyze`) yang dibungkus *middleware* akses admin/tenant.
- [ ] Membuat `AiAuditorController` yang akan menarik data transaksi dan memanggil agen: `AuditorAgent::make()->prompt(json_encode($data))`.

### Fase 2: Pembuatan Tampilan Frontend (Vue.js)
- [ ] Membuat halaman baru `resources/js/pages/AiAuditor/Index.vue`.
- [ ] Membuat komponen UI Form Filter:
  - *Dropdown* Pemilihan User (mengambil data dari tabel `users`).
  - *Date Range Picker*.
  - Tombol "Mulai Analisis" dengan *loading state*.
- [ ] Membuat area hasil analisa (biasanya menggunakan *Markdown Parser* karena AI merespon dengan format Markdown).
- [ ] Menambahkan *error handling* jika koneksi API terputus atau token limit tercapai.

### Fase 3: Optimasi & Keamanan (Data Sanitization)
- [ ] **Data Minimization:** Memastikan hanya data yang relevan dikirim ke AI (menghapus data sensitif seperti password hash, alamat pribadi pelanggan, dll).
- [ ] **Token Management:** Jika data terlalu besar (misal analisa 1 bulan dengan 10.000 transaksi), buat sistem *chunking* (analisa per minggu) atau batasi rentang waktu maksimal.

### Fase 4: Pengujian (Testing)
- [ ] **Skenario Normal:** Menguji dengan data yang benar (AI seharusnya merespon "Semua data sinkron dan normal").
- [ ] **Skenario Anomali:** Sengaja mengubah satu data di database (misal HPP > Harga Jual) lalu melihat apakah AI berhasil mendeteksinya.
- [ ] **Skenario Pembatasan Akses:** Memastikan user penjual/pemilik bisnis gagal mengakses rute AI Auditor (hanya untuk Super Admin).

### Fase 5: Persiapan Ekspansi (Akses untuk Pemilik Bisnis) - Rencana Kedepan
- [ ] Menambahkan kolom flag/pengaturan pada tabel `users` atau `subscriptions` (misal: `can_access_ai_auditor` boolean).
- [ ] Menyesuaikan *Middleware* agar selain Super Admin, Pemilik Bisnis yang memiliki akses juga dapat membuka halaman AI Auditor.
- [ ] Menyesuaikan Form Filter di Frontend: Jika yang login adalah Pemilik Bisnis, *dropdown* pemilihan user disembunyikan dan data transaksi otomatis difilter berdasarkan akun mereka sendiri.

---

## 5. Kebutuhan Teknis Lanjutan
- **Kunci API (API Key):** Membutuhkan *account* dan API Key berbayar dari penyedia AI (OpenAI, Anthropic, atau Gemini) yang akan diletakkan di file `.env`.
- **Markdown Renderer:** Di Vue, membutuhkan library seperti `marked` atau `markdown-it` agar respon AI yang memiliki format *bold* atau *list* terlihat rapi.
