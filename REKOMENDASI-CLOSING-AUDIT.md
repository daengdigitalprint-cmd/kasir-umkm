# Analisis & Rekomendasi Fitur Closing & Audit Trail POS

Berdasarkan analisis struktur kode (Laravel + Vue/Inertia) pada proyek ini, berikut adalah pemetaan antara rekomendasi yang Anda berikan dengan kondisi sistem saat ini, serta **rekomendasi best practice** yang dapat diterapkan untuk menjadikan aplikasi POS ini sekelas _enterprise_.

---

## 1. Implementasi 3 Layer Closing

### A. Closing Harian (Day-End / Shift Closing)
**Fokus:** Operasional kasir.
*   **Kondisi Saat Ini:** 
    Aplikasi sudah memiliki fitur ini melalui `CashShiftController` dan `CashShift` model. 
    *   Terdapat input modal awal (`opening_cash`).
    *   Sistem menghitung expected cash dan membandingkan dengan kas fisik yang dihitung (`closing_cash_counted`).
    *   Ada pencatatan selisih (`difference_amount`) dan status shift.
    *   Hanya satu shift yang aktif per outlet dalam satu waktu.
*   **Rekomendasi Best Practice:**
    1.  **Approval Selisih (Opsional tapi disarankan):** Tambahkan mekanisme di mana jika selisih kas (+/-) melebihi batas toleransi tertentu (misal > Rp 50.000), penutupan shift memerlukan PIN/Approval dari Supervisor/Manajer Toko. (Tunda Implementasi)
    2.  **Integrasi Log yang Kuat:** Saat shift ditutup, catat event ini secara eksplisit ke dalam `ActivityLogger` agar mudah dilacak di log aktivitas (saat ini sistem merekam di database, tapi belum spesifik ter-log di `activity_logs` layaknya `auth.login`).
    3.  **Blind Close:** Kasir sebaiknya **tidak melihat** `expected cash` dari sistem sebelum mereka menginput nominal uang fisik di laci. Ini mencegah kasir memanipulasi input agar selalu "pas" dengan sistem. (Tunda Implementasi)

### B. Closing Bulanan (Accounting Period Closing)
**Fokus:** Laporan & akuntansi.
*   **Kondisi Saat Ini:**
    Infrastruktur untuk modul ini **sudah tersedia**. Terdapat tabel `accounting_periods`, penguncian operasi, serta pelaporan keuangan (Laba Rugi, dsb). Namun, integrasi penguncian (lock) pada modul POS mungkin masih bergantung pada apakah modul akuntansi diaktifkan per toko atau tidak.
*   **Rekomendasi Best Practice (Pola UX Software Akuntansi - Jurnal.id, Accurate, Xero):**
    1.  **Default Otomatis & Real-time:** Periode default = bulan kalender berjalan (1–akhir bulan). User tidak perlu pilih tanggal manual. Selama periode masih berjalan, laporan tetap bisa dilihat _real-time_ (running number) tanpa perlu "buka buku" dulu.
    2.  **Aksi "Tutup Buku" Eksplisit & Checklist:** Penutupan adalah aksi eksplisit (misal tombol "Tutup Periode Agustus 2026"). Sebelum tutup, berikan _reminder_ atau checklist: "Masih ada X transaksi belum direkonsiliasi", "Stok belum di-adjust" — supaya user tidak asal klik tutup.
    3.  **Hard Lock Transaksi:** Setelah ditutup → dikunci (lock). Transaksi di periode itu tidak bisa diedit/dihapus langsung. Ini menjamin integritas _audit trail_.
    4.  **Koreksi via Jurnal Penyesuaian/Reversal:** Jika ada kesalahan di periode lalu, dibuatkan jurnal penyesuaian atau jurnal pembalik (_reversal_) di periode berjalan — bukan mengedit transaksi di periode lama. 
    5.  **Reopen dengan Permission + Log:** Fitur buka kembali periode yang sudah ditutup harus dibatasi ke role tertentu (misal Admin/Owner). Wajib ada _log_ yang mencatat siapa yang membuka dan apa alasannya.

### C. Closing Tahunan
**Fokus:** Pajak & laporan owner.
*   **Kondisi Saat Ini:** Belum diimplementasikan secara spesifik sebagai sebuah entitas terpisah, namun dapat dihasilkan melalui laporan yang menarik data dalam rentang waktu 1 tahun.
*   **Rekomendasi Best Practice:**
    *   Jadikan ini sebagai target _roadmap_ di masa depan. Fokuskan pada pembuatan ringkasan Laba Rugi Komprehensif tahunan dan Neraca akhir tahun yang bisa langsung diekspor sebagai PDF/Excel untuk keperluan pelaporan pajak.

---

## 2. Filter Tanggal & Periode Laporan

*   **Kondisi Saat Ini:** Mayoritas halaman laporan menggunakan parameter rentang tanggal dari request.
*   **Rekomendasi Best Practice:**
    1.  **Default Action:** Set nilai _default_ filter pada frontend (Vue/Inertia) ke "Bulan Ini" atau "Hari Ini" (tergantung konteks laporan, misal dashboard POS = Hari ini, Laporan Penjualan = Bulan Ini).
    2.  **Quick Filters:** Gunakan komponen _Dropdown_ atau _Button Group_ dengan pilihan instan: `Hari Ini`, `Kemarin`, `Minggu Ini`, `Bulan Ini`.
    3.  Gunakan "Custom Date Range" hanya sebagai opsi tambahan jika user memilih "Lainnya/Custom".

---

## 3. Flow UX yang Ideal


*   **Tutup Buku Bulanan:** Gunakan notifikasi sistem (banner di atas layar Backoffice) yang mengingatkan "Bulan lalu (Juli 2026) belum ditutup. Segera lakukan penutupan buku."

---

## 4. Sistem Lock & Audit Trail

Ini adalah bagian terpenting untuk menaikkan kelas aplikasi Anda menjadi standar _Enterprise / Accounting-ready_.

*   **Kondisi Saat Ini:**
    *   Sistem sudah memiliki `ActivityLogger`.
    *   Terdapat log khusus jika transaksi dihapus (`TransactionDeletionLog`).
    *   Namun, transaksi **masih bisa dihapus secara fisik** dari database.
*   **Rekomendasi Best Practice (SANGAT KRITIKAL):**
    1.  **Hentikan Penghapusan Permanen (No Hard Delete):** Transaksi keuangan tidak boleh dihapus (`DELETE`). Jika ada kesalahan (misal kasir salah input), gunakan fitur **VOID**. Transaksi ditandai sebagai `is_void = true`, stok dikembalikan, dan nominalnya tidak dihitung di laporan penjualan, tetapi *record* aslinya tetap ada di database.
    2.  **Transaksi Pengganti (Replacement / Revision):** Jika transaksi salah dan perlu diubah, buat fitur "Revisi". Alurnya: Transaksi lama di-VOID, sistem membuat Transaksi BARU dengan nomor referensi ke transaksi lama (misal: TRX-001 menjadi TRX-001-REV). 
    3.  **Standarisasi Audit Trail:** Pastikan `ActivityLogger` mencatat payload "Sebelum" (Before) dan "Sesudah" (After) dalam format JSON setiap kali ada perubahan data master (seperti diskon, harga produk, atau pelanggan).
    4.  **Kaitkan dengan Shift & Periode:** Transaksi tidak boleh bisa diubah atau di-void jika Shift Kasir sudah ditutup, ATAU Periode Akuntansi sudah ditutup. Tampilkan pesan _error_ yang jelas kepada user.

---

### Kesimpulan Tindakan Lanjut (Action Plan)

1.  **Tahap 1 (Mudah):** Perbaiki UI filter tanggal di Vue agar menggunakan default "Bulan Ini" dan "Hari Ini".

2.  **Tahap 3 (Lanjutan - Pembeda Utama):** Hapus logika _Hard Delete_ pada transaksi, ganti dengan sistem VOID. Terapkan penguncian ketat (_Lock_) jika shift atau periode akuntansi sudah berstatus tertutup.

---

## 5. Fitur Lanjutan (Enterprise Standard Roadmap)

Berdasarkan analisis struktur yang sudah ada, berikut adalah rancangan fitur lanjutan untuk membawa sistem ini menuju standar _Enterprise_:

1.  **Manajemen Void vs Refund (Retur) Penjualan yang Dipisah**
    *   **Void:** Membatalkan transaksi di hari yang sama (sebelum tutup shift). Transaksi tidak dihapus fisik, melainkan ditandai `is_void = true`, stok dikembalikan, dan jurnal dibatalkan.
    *   **Refund (Retur):** Pengembalian barang di hari/periode berbeda. Menerbitkan dokumen **Retur Penjualan** (Credit Note), mengembalikan barang ke gudang (atau gudang cacat), dan memunculkan jurnal pembalik di periode berjalan tanpa menyentuh histori transaksi lama.
2.  **Sistem "Maker - Checker" & Supervisor PIN Override**
    *   Mengimplementasikan Fase 3 dari `CASHIER_PIN_SHIFT_PLAN.md`.
    *   Jika Kasir (Maker) memicu _Void_, Diskon Manual (misal > 10%), atau _Refund_, sistem akan terkunci otomatis dan mewajibkan Supervisor (Checker) untuk memasukkan PIN persetujuan.
3.  **Overan Shift (Shift Handover) Berkelanjutan**
    *   Mengembangkan modul `CashShiftController` yang ada.
    *   Saat Shift 1 _closing_, "Kas Fisik" yang dihitung akan otomatis diteruskan sebagai _Draft Opening Cash_ untuk Shift 2. Kasir Shift 2 wajib memverifikasi ulang uang tersebut sebelum shift-nya dinyatakan terbuka.
4.  **Pencegahan Backdate Lanjutan (Backdating Prevention)**
    *   Sistem saat ini sudah terlindungi oleh `AccountingPeriodGuard` (pencegahan pada periode _Closed_).
    *   **Peningkatan:** Menambahkan batasan input transaksi mundur maksimal H-3 atau H-7 pada periode yang masih terbuka. Jika lebih dari batas tersebut, sistem membutuhkan persetujuan/otorisasi tambahan guna menjaga kestabilan penghitungan HPP (FIFO/Average).
