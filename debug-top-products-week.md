# Debug Session: top-products-week
- **Status**: [OPEN]
- **Issue**: Permintaan produk terlaris minggu ini hanya menampilkan judul tanpa daftar produk; stok rendah malah menerima respons konteks sebelumnya.

## Evidence
| ID | Hypothesis | Evidence |
| --- | --- | --- |
| A | Query tidak menemukan penjualan pada rentang minggu ini. | Dikonfirmasi: 21–27 September 2026 tidak punya data penjualan, sehingga respons yang benar adalah tidak ada data. |
| B | Filter relasi atau status transaksi tidak sesuai data. | Ditolak: periode September mengembalikan tiga produk dari tool. |
| C | Formatter respons tidak merender daftar hasil tool. | Dikonfirmasi: log menunjukkan tiga produk pada `data.products`, tetapi chat hanya menyimpan `result->message`. |
| D | Rentang minggu ini tidak sesuai ekspektasi. | Dikonfirmasi: parser memetakan “minggu ini” ke `this_month`, karena pola umum diperiksa lebih dahulu. |
| E | Pesan stok rendah tidak terdeteksi. | Dikonfirmasi: parser mengembalikan `null` untuk “Tampilkan produk yang stoknya rendah”, lalu model mengulang konteks jawaban sebelumnya. |

## Fix
- Parser mengenali `stoknya rendah` dan variasi hingga tiga kata di antara stok dan status.
- Parser memilih `this_week` sebelum pola umum produk terlaris.
- Hasil langsung `get_top_products` dan `check_stock` kini mencetak daftar item pada pesan chat.

## Verification
Parser diuji lewat Tinker:
- `Tampilkan produk yang stoknya rendah` => `check_stock`
- `Apa saja produk terlaris minggu ini?` => `get_top_products` dengan periode `this_week`

Pint lulus. Test Pest tidak dapat dijalankan karena aplikasi memiliki config cache aktif yang secara sengaja mencegah test agar database aplikasi tidak tersentuh.
