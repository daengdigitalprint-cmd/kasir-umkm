<?php

namespace App\Support;

/**
 * Konten halaman SEO "Aplikasi Kasir untuk [jenis usaha]".
 *
 * Setiap halaman ditulis manual (masalah, fitur, alur, FAQ berbeda) agar tidak
 * menjadi halaman tipis yang hanya mengganti nama usaha.
 *
 * ATURAN: setiap klaim fitur harus benar-benar ada di aplikasi. Fitur yang
 * hanya tersedia di paket berbayar diberi 'paid' => true (tampil label paket
 * GrowUp). Jangan menulis fitur yang belum ada (mis. notifikasi WhatsApp
 * laundry, integrasi ojol, QRIS dinamis).
 *
 * Halaman ditampilkan oleh JenisUsahaController + resources/views/jenis-usaha/show.blade.php,
 * didaftarkan di routes/web.php dan sitemap.xml lewat slugs().
 */
final class JenisUsaha
{
    /** @return list<string> */
    public static function slugs(): array
    {
        return array_keys(self::pages());
    }

    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        return array_values(self::pages());
    }

    /** @return array<string, mixed>|null */
    public static function find(string $slug): ?array
    {
        return self::pages()[$slug] ?? null;
    }

    /** @return array<string, array<string, mixed>> */
    private static function pages(): array
    {
        $pages = [
            'aplikasi-kasir-cafe' => [
                'name' => 'Cafe',
                'eyebrow' => 'Cafe & coffee shop',
                'title' => 'Aplikasi Kasir Cafe & Coffee Shop Gratis | Kasir UMKM',
                'description' => 'Aplikasi kasir cafe dan coffee shop: opsi menu (gula, es, topping), pesan lewat QR meja, split bill, dan HPP per gelas. Mulai gratis dari HP.',
                'h1' => 'Aplikasi Kasir Cafe & Coffee Shop',
                'lead' => 'Catat pesanan dengan pilihan level gula, ukuran, dan topping tanpa salah ketik. Pelanggan bisa pesan sendiri dari QR di meja, barista melihat antrean di layar, dan Anda tahu modal tiap gelas.',
                'audience' => 'pemilik cafe',
                'cocok' => [
                    'Coffee shop dan kedai kopi susu kekinian',
                    'Cafe dengan meja dine-in dan pesanan takeaway',
                    'Booth minuman (boba, es teh, jus) di mal atau pinggir jalan',
                    'Cafe yang ingin pelanggan pesan sendiri lewat QR',
                ],
                'problems_title' => 'Masalah yang sering terjadi di kasir cafe',
                'problems_sub' => 'Di jam ramai, kesalahan kecil di kasir langsung terasa: minuman salah racik, antrean mengular, dan modal tidak terhitung.',
                'problems' => [
                    [
                        'problem' => 'Pesanan custom sering salah',
                        'detail' => '"Less sugar, extra shot, oat milk" ditulis di kertas atau diteriakkan ke barista, lalu tertukar saat antrean panjang.',
                        'solution' => 'opsi produk (level gula, ukuran, topping) dipilih di layar kasir dan tercetak jelas di struk dapur atau bar.',
                    ],
                    [
                        'problem' => 'Antrean kasir menumpuk',
                        'detail' => 'Satu kasir melayani pesanan meja, takeaway, dan pembayaran sekaligus sehingga pelanggan menunggu lama.',
                        'solution' => 'pelanggan memesan sendiri lewat QR di meja, dan kasir cukup menerima lalu memproses pembayarannya.',
                    ],
                    [
                        'problem' => 'Tidak tahu untung per gelas',
                        'detail' => 'Harga kopi, susu, dan cup naik, tetapi harga jual tetap sama karena modal per gelas tidak pernah dihitung.',
                        'solution' => 'resep bahan per menu menghitung HPP per gelas dan stok bahan berkurang otomatis setiap ada penjualan.',
                    ],
                ],
                'features_sub' => 'Semua fitur berjalan di browser HP, tablet, atau laptop, juga lewat aplikasi Android.',
                'features' => [
                    ['title' => 'Opsi menu & topping', 'desc' => 'Atur grup pilihan seperti level gula, es, ukuran, dan topping berbayar. Harga tambahan langsung ikut terhitung.'],
                    ['title' => 'Dine-in, takeaway, delivery', 'desc' => 'Pilih jenis pesanan di kasir sehingga laporan bisa membedakan penjualan di tempat dan dibawa pulang.'],
                    ['title' => 'Pesan lewat QR meja', 'desc' => 'Setiap meja punya QR menu digital. Pesanan masuk ke kasir untuk diterima, dibayar, dan diselesaikan.', 'paid' => true],
                    ['title' => 'Layar antrean bar/dapur', 'desc' => 'Barista melihat pesanan yang masuk secara langsung, mencentang item yang selesai, dan menandai pesanan siap.', 'paid' => true],
                    ['title' => 'Resep & HPP per gelas', 'desc' => 'Masukkan takaran bahan per menu. Aplikasi menghitung modal per gelas dan memotong stok bahan otomatis.', 'paid' => true],
                    ['title' => 'Split bill & open bill', 'desc' => 'Tagihan satu meja bisa dibagi, dan pesanan bisa disimpan dulu lalu dibayar di akhir.'],
                    ['title' => 'Bayar tunai, QRIS, transfer', 'desc' => 'Catat pembayaran tunai, QRIS (gambar QRIS toko Anda), e-wallet, atau transfer, bahkan kombinasi beberapa metode.'],
                    ['title' => 'Poin & member', 'desc' => 'Beri poin untuk pelanggan setia dan tukarkan dengan hadiah atau voucher.', 'paid' => true],
                    ['title' => 'Struk ke printer Bluetooth', 'desc' => 'Cetak struk ke printer thermal Bluetooth. Printer kasir dan printer bar bisa dipisah.'],
                ],
                'flow_title' => 'Alur pesanan di cafe dengan Kasir UMKM',
                'flow_sub' => 'Contoh satu pesanan dari meja 4 di jam sibuk.',
                'flow' => [
                    ['title' => 'Pelanggan scan QR di meja', 'desc' => 'Memilih Kopi Susu Aren, less sugar, ukuran large, lalu mengirim pesanan.'],
                    ['title' => 'Kasir menerima pesanan', 'desc' => 'Pesanan meja 4 muncul di kasir dan diteruskan ke bar.'],
                    ['title' => 'Barista meracik', 'desc' => 'Layar antrean menampilkan detail opsi, barista menandai pesanan siap.'],
                    ['title' => 'Bayar dan stok terpotong', 'desc' => 'Pelanggan membayar dengan QRIS. Stok kopi, susu, gula aren, dan cup berkurang sesuai resep.'],
                ],
                'pricing_note' => 'Kasir, split bill, dan cetak struk bisa dipakai di paket gratis. Fitur seperti QR meja, layar antrean, resep & HPP, dan poin member ada di paket berbayar.',
                'faq' => [
                    ['q' => 'Apakah aplikasi kasir cafe ini gratis?', 'a' => 'Ya. Paket Starter gratis selamanya dengan transaksi tanpa batas untuk 1 outlet, dengan batas jumlah produk dan pelanggan. Fitur cafe lanjutan seperti pesan lewat QR meja, layar antrean dapur, resep dan HPP ada di paket GrowUp.'],
                    ['q' => 'Bisakah menu punya pilihan level gula dan topping?', 'a' => 'Bisa. Buat grup opsi seperti Level Gula, Ukuran, atau Topping, lalu pasangkan ke menu. Opsi berbayar menambah harga secara otomatis.'],
                    ['q' => 'Apakah pelanggan bisa memesan sendiri dari meja?', 'a' => 'Bisa, dengan fitur Scan Meja di paket GrowUp. Setiap meja punya QR menu digital, pesanan masuk ke kasir untuk diterima dan dibayar.'],
                    ['q' => 'Bagaimana kalau internet di cafe putus?', 'a' => 'Penjualan di kasir tetap bisa dicatat saat offline dan tersinkron otomatis begitu internet tersambung lagi.'],
                    ['q' => 'Printer apa yang bisa dipakai?', 'a' => 'Printer thermal Bluetooth (ESC/POS) dari browser yang mendukung Web Bluetooth atau lewat aplikasi Android Kasir UMKM. Printer kasir dan printer bar bisa dibedakan.'],
                ],
                'article_keywords' => ['cafe', 'kafe', 'kopi', 'minuman', 'HPP', 'menu digital'],
            ],

            'aplikasi-kasir-restoran' => [
                'name' => 'Restoran',
                'eyebrow' => 'Restoran & rumah makan',
                'title' => 'Aplikasi Kasir Restoran & Rumah Makan Gratis | Kasir UMKM',
                'description' => 'Aplikasi kasir restoran dan rumah makan: pesanan per meja, antrean dapur, split bill, service charge, pajak, dan HPP per porsi. Mulai gratis.',
                'h1' => 'Aplikasi Kasir Restoran & Rumah Makan',
                'lead' => 'Kelola pesanan per meja dari order sampai bayar. Dapur menerima pesanan tanpa kertas, tagihan bisa dibagi, service charge dan pajak terhitung otomatis, dan modal per porsi terpantau.',
                'audience' => 'pemilik restoran',
                'cocok' => [
                    'Restoran keluarga dan rumah makan dengan banyak meja',
                    'Rumah makan padang, seafood, dan ayam bakar',
                    'Restoran dengan dapur terpisah dari kasir',
                    'Usaha kuliner yang melayani antar sendiri',
                ],
                'problems_title' => 'Masalah yang sering terjadi di kasir restoran',
                'problems_sub' => 'Restoran punya alur yang lebih panjang dari toko biasa: pesanan meja, dapur, tambahan pesanan, lalu tagihan.',
                'problems' => [
                    [
                        'problem' => 'Pesanan ke dapur hilang atau terlambat',
                        'detail' => 'Kertas order terselip atau tulisan pelayan tidak terbaca, sehingga ada meja yang menunggu terlalu lama.',
                        'solution' => 'pesanan langsung masuk ke layar antrean dapur atau printer dapur, lengkap dengan nomor meja.',
                    ],
                    [
                        'problem' => 'Tagihan rombongan ribet',
                        'detail' => 'Satu meja ingin bayar sendiri-sendiri, ada yang tunai, ada yang transfer, dan kasir menghitung manual.',
                        'solution' => 'split bill membagi tagihan per orang, dan satu transaksi bisa dibayar dengan beberapa metode sekaligus.',
                    ],
                    [
                        'problem' => 'Omzet besar, laba tidak jelas',
                        'detail' => 'Bahan baku dibeli setiap hari tanpa catatan rapi sehingga sulit tahu menu mana yang benar-benar untung.',
                        'solution' => 'resep per menu menghitung HPP per porsi, dan laporan penjualan menunjukkan menu terlaris dan keuntungannya.',
                    ],
                ],
                'features_sub' => 'Fitur yang menyesuaikan alur restoran, dari meja sampai laporan.',
                'features' => [
                    ['title' => 'Simpan pesanan (open bill)', 'desc' => 'Pesanan meja disimpan dulu, ditambah kapan saja, lalu dibayar saat tamu selesai makan.'],
                    ['title' => 'Split bill', 'desc' => 'Bagi tagihan satu meja menjadi beberapa pembayaran tanpa hitung manual.'],
                    ['title' => 'Service charge & pajak', 'desc' => 'Atur persentase service charge dan pajak restoran. Nilainya otomatis muncul di struk dan laporan.'],
                    ['title' => 'Layar antrean dapur', 'desc' => 'Dapur melihat pesanan masuk secara langsung, mencentang menu yang selesai, dan menandai pesanan siap.', 'paid' => true],
                    ['title' => 'Pesan lewat QR meja', 'desc' => 'Tamu memesan dari QR menu digital di meja, pesanan masuk ke kasir untuk diterima.', 'paid' => true],
                    ['title' => 'Resep & HPP per porsi', 'desc' => 'Hitung modal tiap menu dari takaran bahan, dan stok bahan berkurang otomatis.', 'paid' => true],
                    ['title' => 'Antar dengan kurir sendiri', 'desc' => 'Catat ongkir, pilih kurir, dan pantau status pengantaran pesanan.', 'paid' => true],
                    ['title' => 'Shift kasir & setoran', 'desc' => 'Setiap kasir membuka dan menutup shift sehingga selisih kas mudah dilacak.', 'paid' => true],
                    ['title' => 'Laporan & ekspor', 'desc' => 'Laporan penjualan, menu terlaris, dan arus kas bisa diekspor ke Excel atau PDF.'],
                ],
                'flow_title' => 'Alur satu meja di restoran',
                'flow_sub' => 'Contoh rombongan 6 orang di meja 12.',
                'flow' => [
                    ['title' => 'Pelayan mencatat pesanan', 'desc' => 'Pesanan meja 12 disimpan sebagai open bill dan dikirim ke dapur.'],
                    ['title' => 'Dapur memasak', 'desc' => 'Layar dapur menampilkan menu meja 12. Koki menandai setiap menu yang selesai.'],
                    ['title' => 'Tambah pesanan', 'desc' => 'Tamu menambah es teh dan dessert. Pesanan ditambahkan ke tagihan yang sama.'],
                    ['title' => 'Bayar terpisah', 'desc' => 'Tagihan dibagi tiga. Service charge dan pajak ikut terhitung, ada yang bayar tunai dan ada yang QRIS.'],
                ],
                'pricing_note' => 'Open bill, split bill, service charge, dan pajak tersedia di paket gratis. Layar dapur, QR meja, resep & HPP, antar sendiri, dan shift kasir ada di paket berbayar.',
                'faq' => [
                    ['q' => 'Apakah bisa dipakai untuk restoran dengan banyak meja?', 'a' => 'Bisa. Pesanan disimpan per meja sebagai open bill, bisa ditambah kapan saja, dan dibayar di akhir. Dengan fitur Scan Meja, setiap meja juga punya QR menu digital.'],
                    ['q' => 'Apakah dapur bisa menerima pesanan tanpa kertas?', 'a' => 'Bisa, dengan layar antrean dapur di paket GrowUp. Anda juga bisa memakai printer dapur terpisah dari printer kasir.'],
                    ['q' => 'Bagaimana dengan service charge dan pajak restoran?', 'a' => 'Persentase service charge dan pajak bisa diatur di pengaturan, lalu otomatis dihitung di setiap transaksi dan tampil di struk.'],
                    ['q' => 'Apakah terhubung ke GoFood atau GrabFood?', 'a' => 'Belum terhubung otomatis. Penjualan GoFood, GrabFood, dan ShopeeFood bisa dicatat manual sebagai channel penjualan beserta potongan komisinya, dan pesanan antar sendiri bisa dicatat lengkap dengan ongkir dan status pengantaran.'],
                    ['q' => 'Bisakah melihat menu yang paling menguntungkan?', 'a' => 'Bisa. Dengan resep dan HPP per menu, Anda bisa membandingkan harga jual dengan modal, dan laporan penjualan menunjukkan menu terlaris.'],
                ],
                'article_keywords' => ['restoran', 'rumah makan', 'warung makan', 'HPP', 'menu digital'],
            ],

            'aplikasi-kasir-toko-kelontong' => [
                'name' => 'Toko Kelontong',
                'eyebrow' => 'Warung & toko kelontong',
                'title' => 'Aplikasi Kasir Toko Kelontong & Warung Gratis | Kasir UMKM',
                'description' => 'Aplikasi kasir toko kelontong dan warung sembako: scan barcode pakai kamera HP, catat utang pelanggan, cek stok menipis, dan laporan harian. Gratis.',
                'h1' => 'Aplikasi Kasir Toko Kelontong & Warung',
                'lead' => 'Ganti buku catatan dengan aplikasi kasir di HP. Scan barcode pakai kamera, catat utang pelanggan beserta cicilannya, dan lihat barang yang hampir habis sebelum pembeli kecewa.',
                'audience' => 'pemilik toko kelontong',
                'cocok' => [
                    'Warung sembako dan toko kelontong rumahan',
                    'Toko yang menjual eceran dan grosir sekaligus',
                    'Warung yang banyak pelanggan langganan bayar belakangan',
                    'Pemilik yang ingin kasir cukup dari HP Android',
                ],
                'problems_title' => 'Masalah yang sering terjadi di toko kelontong',
                'problems_sub' => 'Toko kelontong menjual ratusan barang dengan margin tipis. Sedikit kebocoran saja langsung menggerus untung.',
                'problems' => [
                    [
                        'problem' => 'Utang pelanggan lupa ditagih',
                        'detail' => 'Catatan utang di buku tercecer, nominalnya tidak jelas, dan pelanggan membantah jumlahnya.',
                        'solution' => 'utang tercatat per pelanggan dengan tanggal jatuh tempo, dan setiap cicilan mengurangi sisa utang secara otomatis.',
                    ],
                    [
                        'problem' => 'Barang habis tanpa disadari',
                        'detail' => 'Rokok, gas, atau minyak goreng kosong saat pembeli datang karena stok tidak dicek rutin.',
                        'solution' => 'atur stok minimum per barang. Barang yang menipis tampil di dashboard dan daftar belanja.',
                    ],
                    [
                        'problem' => 'Harga eceran dan grosir tertukar',
                        'detail' => 'Pembeli grosir dan eceran dilayani di kasir yang sama, dan harga ditentukan dari ingatan.',
                        'solution' => 'simpan harga grosir per barang. Harga grosir otomatis dipakai untuk pelanggan bertipe grosir.',
                    ],
                ],
                'features_sub' => 'Fitur yang dipakai sehari-hari di warung dan toko kelontong.',
                'features' => [
                    ['title' => 'Scan barcode pakai kamera', 'desc' => 'Tidak perlu alat scanner: arahkan kamera HP ke barcode dan barang langsung masuk ke keranjang.'],
                    ['title' => 'Catat utang pelanggan', 'desc' => 'Simpan transaksi bayar belakangan, catat cicilan, dan lihat utang yang jatuh tempo.', 'paid' => true],
                    ['title' => 'Harga grosir', 'desc' => 'Satu barang bisa punya harga eceran dan harga grosir untuk pelanggan grosir.'],
                    ['title' => 'Multi satuan', 'desc' => 'Jual per pcs, pak, atau dus dengan faktor konversi, misalnya 1 dus = 40 bungkus.'],
                    ['title' => 'Stok menipis & daftar belanja', 'desc' => 'Barang di bawah stok minimum tampil di dashboard. Daftar belanja menyarankan jumlah yang perlu dibeli.', 'paid' => true],
                    ['title' => 'Pembelian & supplier', 'desc' => 'Catat belanja dari supplier sehingga stok bertambah dan harga modal tercatat.', 'paid' => true],
                    ['title' => 'Shift & setoran harian', 'desc' => 'Kasir membuka dan menutup shift sehingga setoran harian bisa dicocokkan dengan penjualan.', 'paid' => true],
                    ['title' => 'Jalan saat sinyal putus', 'desc' => 'Penjualan tetap tercatat saat offline dan tersinkron otomatis ketika internet kembali.'],
                    ['title' => 'Laporan penjualan', 'desc' => 'Lihat omzet harian, barang terlaris, dan ekspor laporan ke Excel atau PDF.'],
                ],
                'flow_title' => 'Satu hari di toko kelontong',
                'flow_sub' => 'Contoh alur sederhana yang bisa dimulai hari ini.',
                'flow' => [
                    ['title' => 'Pagi: cek barang menipis', 'desc' => 'Dashboard menunjukkan gula dan minyak goreng di bawah stok minimum.'],
                    ['title' => 'Siang: layani pembeli', 'desc' => 'Scan barcode dengan kamera HP, terima tunai atau QRIS, dan cetak struk.'],
                    ['title' => 'Sore: pelanggan ambil dulu', 'desc' => 'Bu Siti belanja Rp85.000 dan bayar akhir bulan. Transaksi dicatat sebagai utang atas namanya.'],
                    ['title' => 'Malam: tutup kas', 'desc' => 'Laporan menunjukkan omzet hari ini dan barang terlaris untuk belanja besok.'],
                ],
                'pricing_note' => 'Untuk warung kecil, paket gratis sudah cukup untuk mulai: kasir, scan barcode, cetak struk, stok dasar, dan laporan penjualan. Catat utang, pembelian, dan shift kasir ada di paket berbayar.',
                'faq' => [
                    ['q' => 'Apakah perlu membeli alat scanner barcode?', 'a' => 'Tidak wajib. Kamera HP bisa dipakai untuk scan barcode langsung dari aplikasi.'],
                    ['q' => 'Bisakah mencatat utang pelanggan warung?', 'a' => 'Bisa, di paket GrowUp. Transaksi bayar belakangan tercatat per pelanggan, lengkap dengan cicilan dan tanggal jatuh tempo.'],
                    ['q' => 'Apakah paket gratis cukup untuk warung?', 'a' => 'Paket Starter gratis memuat transaksi tanpa batas, tetapi dibatasi jumlah produk dan pelanggannya. Untuk toko dengan ratusan barang, paket GrowUp tidak membatasi jumlah produk.'],
                    ['q' => 'Bisa menjual per bungkus dan per dus?', 'a' => 'Bisa. Satu barang dapat punya beberapa satuan dengan faktor konversi, misalnya per bungkus, per pak, dan per dus.'],
                ],
                'article_keywords' => ['stok', 'utang', 'piutang', 'kelontong', 'warung', 'pembukuan'],
            ],

            'aplikasi-kasir-toko-retail' => [
                'name' => 'Toko Retail',
                'eyebrow' => 'Minimarket, grosir & toko retail',
                'title' => 'Aplikasi Kasir Toko Retail & Minimarket | Kasir UMKM',
                'description' => 'Aplikasi kasir toko retail, minimarket, dan toko grosir: barcode, multi satuan, varian, stok opname, pembelian supplier, dan retur. Coba gratis.',
                'h1' => 'Aplikasi Kasir Toko Retail & Minimarket',
                'lead' => 'Untuk toko dengan ratusan sampai ribuan barang. Kelola barcode, satuan, varian, stok opname, dan pembelian dari supplier dalam satu aplikasi, dengan laporan yang bisa diekspor.',
                'audience' => 'pemilik toko retail',
                'cocok' => [
                    'Minimarket dan toko swalayan kecil',
                    'Toko grosir dan agen sembako',
                    'Toko bangunan, toko pertanian, dan toko alat tulis',
                    'Toko pakaian dan aksesori dengan varian ukuran/warna',
                ],
                'problems_title' => 'Masalah yang sering terjadi di toko retail',
                'problems_sub' => 'Semakin banyak barang, semakin besar risiko selisih stok dan salah harga.',
                'problems' => [
                    [
                        'problem' => 'Stok di sistem beda dengan di rak',
                        'detail' => 'Barang rusak, hilang, atau salah input membuat angka stok tidak bisa dipercaya.',
                        'solution' => 'stok opname dengan impor Excel mencocokkan hitungan fisik, dan setiap penyesuaian tercatat di riwayat stok.',
                    ],
                    [
                        'problem' => 'Belanja ke supplier tanpa catatan',
                        'detail' => 'Nota supplier hilang sehingga harga modal dan utang ke supplier tidak terlacak.',
                        'solution' => 'catat pembelian per supplier, termasuk retur ke supplier, sehingga stok dan harga modal ikut diperbarui.',
                    ],
                    [
                        'problem' => 'Barang dibeli per dus, dijual per pcs',
                        'detail' => 'Menghitung stok secara manual saat membongkar kemasan rawan salah.',
                        'solution' => 'multi satuan dengan faktor konversi dan fitur pecah kemasan menjaga stok tetap akurat.',
                    ],
                ],
                'features_sub' => 'Fitur retail untuk toko yang barangnya banyak.',
                'features' => [
                    ['title' => 'Scan barcode', 'desc' => 'Scan dengan kamera HP atau scanner USB, barang langsung masuk keranjang.'],
                    ['title' => 'Multi satuan & pecah kemasan', 'desc' => 'Beli per dus, jual per pcs. Pecah kemasan memindahkan stok antar satuan otomatis.', 'paid' => true],
                    ['title' => 'Varian & paket', 'desc' => 'Satu produk dengan varian ukuran atau warna, dan produk paket/bundling.', 'paid' => true],
                    ['title' => 'Stok opname', 'desc' => 'Hitung stok fisik, impor dari Excel, lalu lihat selisihnya sebelum disimpan.', 'paid' => true],
                    ['title' => 'Pembelian, supplier, retur', 'desc' => 'Catat pembelian dan retur ke supplier. Stok dan harga modal ikut diperbarui.', 'paid' => true],
                    ['title' => 'Harga grosir', 'desc' => 'Tetapkan harga grosir per barang untuk pelanggan bertipe grosir.'],
                    ['title' => 'Diskon & promo', 'desc' => 'Atur diskon untuk produk atau transaksi tertentu.', 'paid' => true],
                    ['title' => 'Banyak kasir & hak akses', 'desc' => 'Peran pemilik, admin, manajer, kasir, dan gudang dengan hak akses berbeda. Kasir bisa masuk dengan PIN.'],
                    ['title' => 'Laporan & kartu stok', 'desc' => 'Laporan penjualan, pembelian, persediaan, dan kartu stok per barang, bisa diekspor ke Excel atau PDF.'],
                ],
                'flow_title' => 'Alur barang masuk sampai terjual',
                'flow_sub' => 'Contoh satu barang: mi instan.',
                'flow' => [
                    ['title' => 'Barang datang dari supplier', 'desc' => 'Catat pembelian 10 dus mi instan. Stok bertambah 400 bungkus dan harga modal tercatat.'],
                    ['title' => 'Dijual eceran dan grosir', 'desc' => 'Pembeli eceran membayar harga normal, pelanggan grosir otomatis mendapat harga grosir.'],
                    ['title' => 'Stok menipis', 'desc' => 'Saat stok di bawah batas minimum, barang muncul di dashboard dan daftar belanja.'],
                    ['title' => 'Stok opname bulanan', 'desc' => 'Hitung fisik, impor hasilnya, dan selisih stok tercatat di laporan.'],
                ],
                'pricing_note' => 'Toko retail biasanya punya lebih dari 30 barang, jadi paket GrowUp (produk tanpa batas) lebih cocok. Anda tetap bisa mencoba gratis dulu dengan paket Starter.',
                'faq' => [
                    ['q' => 'Apakah bisa memakai scanner barcode USB?', 'a' => 'Bisa. Selain scanner USB, kamera HP juga bisa dipakai untuk scan barcode.'],
                    ['q' => 'Berapa banyak barang yang bisa dimasukkan?', 'a' => 'Paket Starter gratis dibatasi jumlah produknya. Paket GrowUp tidak membatasi jumlah produk, cocok untuk minimarket dan toko grosir.'],
                    ['q' => 'Apakah ada harga bertingkat berdasarkan jumlah beli?', 'a' => 'Saat ini tersedia satu harga grosir per barang yang dipakai untuk pelanggan bertipe grosir. Harga bertingkat berdasarkan jumlah belum tersedia.'],
                    ['q' => 'Bisakah dipakai beberapa kasir sekaligus?', 'a' => 'Bisa. Beberapa pengguna bisa masuk bersamaan di perangkat berbeda, dengan hak akses sesuai peran masing-masing.'],
                    ['q' => 'Apakah bisa untuk beberapa cabang?', 'a' => 'Bisa. Outlet tambahan tersedia sebagai add-on berbayar, dan stok bisa dipindahkan antar outlet.'],
                ],
                'article_keywords' => ['stok', 'opname', 'barang', 'retail', 'toko'],
            ],

            'aplikasi-kasir-toko-roti' => [
                'name' => 'Toko Roti',
                'eyebrow' => 'Bakery, toko roti & kue',
                'title' => 'Aplikasi Kasir Toko Roti & Kue (Bakery) | Kasir UMKM',
                'description' => 'Aplikasi kasir bakery dan toko kue: catat produksi harian, hitung HPP dari resep, terima pre-order dengan DP, dan pantau stok bahan. Coba gratis.',
                'h1' => 'Aplikasi Kasir Toko Roti & Kue',
                'lead' => 'Bakery bukan hanya menjual, tetapi juga memproduksi. Catat produksi harian dari bahan baku, hitung HPP per potong, terima pesanan kue ulang tahun dengan DP, dan jual di kasir yang sama.',
                'audience' => 'pemilik bakery',
                'cocok' => [
                    'Toko roti dan bakery rumahan',
                    'Toko kue yang menerima pesanan ulang tahun dan hampers',
                    'Usaha kue kering musiman (Lebaran, Natal, Imlek)',
                    'Bakery yang juga punya area makan di tempat',
                ],
                'problems_title' => 'Masalah yang sering terjadi di bakery',
                'problems_sub' => 'Di bakery, kesalahan hitung terjadi sebelum barang dijual: di dapur produksi.',
                'problems' => [
                    [
                        'problem' => 'Modal per roti tidak pasti',
                        'detail' => 'Harga tepung, mentega, dan telur sering naik, tetapi harga jual dihitung kira-kira.',
                        'solution' => 'resep per produk menghitung HPP dari takaran bahan, dan produksi mencatat biaya setiap batch.',
                    ],
                    [
                        'problem' => 'Pesanan kue tercecer',
                        'detail' => 'Pesanan via chat dicatat di kertas, DP terlupa, dan tanggal ambil tertukar.',
                        'solution' => 'pre-order mencatat pemesan, DP, dan tanggal ambil, lalu dilunasi saat kue diambil.',
                    ],
                    [
                        'problem' => 'Bahan baku habis mendadak',
                        'detail' => 'Produksi pagi tertunda karena tepung atau mentega ternyata habis.',
                        'solution' => 'stok bahan berkurang otomatis setiap produksi, dan daftar belanja menyarankan bahan yang perlu dibeli.',
                    ],
                ],
                'features_sub' => 'Fitur untuk usaha yang memproduksi sekaligus menjual.',
                'features' => [
                    ['title' => 'Produksi harian', 'desc' => 'Catat produksi barang jadi dari bahan baku. Stok roti bertambah, stok bahan berkurang, dan biaya produksi tercatat.', 'paid' => true],
                    ['title' => 'Resep & HPP', 'desc' => 'Masukkan takaran bahan per resep untuk mendapatkan modal per potong atau per loyang.', 'paid' => true],
                    ['title' => 'Pre-order dengan DP', 'desc' => 'Terima pesanan kue dengan uang muka dan tanggal ambil, lalu lunasi saat diambil.', 'paid' => true],
                    ['title' => 'Daftar belanja bahan', 'desc' => 'Bahan di bawah stok minimum masuk ke daftar belanja lengkap dengan saran jumlahnya.', 'paid' => true],
                    ['title' => 'Kasir & struk', 'desc' => 'Jual roti di etalase, catat tunai atau QRIS, dan cetak struk ke printer Bluetooth.'],
                    ['title' => 'Laporan produksi', 'desc' => 'Lihat jumlah dan biaya produksi per periode di samping laporan penjualan.'],
                ],
                'flow_title' => 'Dari dapur ke etalase',
                'flow_sub' => 'Contoh produksi roti sobek pagi hari.',
                'flow' => [
                    ['title' => 'Siapkan resep', 'desc' => 'Resep roti sobek: tepung, gula, mentega, telur, ragi. HPP per loyang langsung terhitung.'],
                    ['title' => 'Catat produksi', 'desc' => 'Produksi 30 loyang. Stok bahan berkurang dan stok roti sobek bertambah 30.'],
                    ['title' => 'Jual di kasir', 'desc' => 'Roti terjual di etalase, stok roti berkurang setiap transaksi.'],
                    ['title' => 'Terima pesanan', 'desc' => 'Pelanggan memesan kue ulang tahun untuk Sabtu dengan DP 50%. Pesanan tercatat sebagai pre-order.'],
                ],
                'pricing_note' => 'Kasir dan struk bisa dipakai gratis. Produksi, resep & HPP, pre-order, dan daftar belanja bahan ada di paket berbayar.',
                'faq' => [
                    ['q' => 'Apakah bisa menghitung HPP roti dari resep?', 'a' => 'Bisa, di paket GrowUp. Masukkan bahan dan takarannya per resep, aplikasi menghitung modal per produk.'],
                    ['q' => 'Bagaimana mencatat pesanan kue dengan DP?', 'a' => 'Gunakan fitur pre-order di paket GrowUp: catat pemesan, uang muka, dan tanggal ambil, lalu lunasi saat kue diambil.'],
                    ['q' => 'Apakah stok bahan berkurang otomatis saat produksi?', 'a' => 'Ya. Saat produksi dicatat, stok bahan baku berkurang sesuai resep dan stok barang jadi bertambah.'],
                    ['q' => 'Bisakah dipakai untuk bakery dengan area makan?', 'a' => 'Bisa. Fitur meja dan pesan lewat QR meja juga tersedia untuk jenis usaha bakery.'],
                ],
                'article_keywords' => ['HPP', 'roti', 'kue', 'bakery', 'produksi'],
            ],

            'aplikasi-kasir-apotek' => [
                'name' => 'Apotek',
                'eyebrow' => 'Apotek & toko obat',
                'title' => 'Aplikasi Kasir Apotek: Batch & Kedaluwarsa | Kasir UMKM',
                'description' => 'Aplikasi kasir apotek dan toko obat: stok per batch, tanggal kedaluwarsa, FEFO otomatis, golongan obat, nomor resep, dan laporan pemusnahan.',
                'h1' => 'Aplikasi Kasir Apotek & Toko Obat',
                'lead' => 'Setiap obat dicatat per batch dengan tanggal kedaluwarsa. Saat dijual, batch yang paling dekat kedaluwarsa dipakai lebih dulu, obat kedaluwarsa tidak bisa dijual, dan obat keras meminta nomor resep.',
                'audience' => 'pemilik apotek',
                'cocok' => [
                    'Apotek mandiri dan apotek keluarga',
                    'Toko obat berizin',
                    'Toko alat kesehatan dan vitamin',
                    'Apotek yang ingin mengurangi kerugian obat kedaluwarsa',
                ],
                'problems_title' => 'Masalah yang sering terjadi di kasir apotek',
                'problems_sub' => 'Di apotek, stok bukan hanya soal jumlah, tetapi juga batch dan tanggal kedaluwarsa.',
                'problems' => [
                    [
                        'problem' => 'Obat kedaluwarsa menumpuk',
                        'detail' => 'Batch baru terjual lebih dulu sehingga batch lama tertinggal di rak sampai kedaluwarsa.',
                        'solution' => 'penjualan otomatis memakai batch dengan kedaluwarsa paling dekat (FEFO), dan dashboard memberi peringatan sebelum kedaluwarsa.',
                    ],
                    [
                        'problem' => 'Obat keras terjual tanpa resep',
                        'detail' => 'Kasir tidak selalu tahu golongan setiap obat saat apotek ramai.',
                        'solution' => 'setiap produk punya golongan obat. Penjualan obat resep meminta nomor resep sebelum disimpan.',
                    ],
                    [
                        'problem' => 'Catatan obat rusak tidak rapi',
                        'detail' => 'Obat rusak atau kedaluwarsa dibuang tanpa catatan sehingga stok tidak cocok.',
                        'solution' => 'batch bisa dikarantina, dan pemusnahan tercatat di laporan pemusnahan.',
                    ],
                ],
                'features_sub' => 'Fitur khusus apotek yang aktif otomatis saat jenis usaha diatur sebagai apotek.',
                'features' => [
                    ['title' => 'Stok per batch', 'desc' => 'Setiap batch wajib punya tanggal kedaluwarsa, sehingga stok bisa dilacak per batch.'],
                    ['title' => 'FEFO otomatis', 'desc' => 'Saat dijual, batch dengan kedaluwarsa paling dekat dipakai lebih dulu. Batch kedaluwarsa diblokir.'],
                    ['title' => 'Peringatan kedaluwarsa', 'desc' => 'Dashboard menampilkan obat yang akan kedaluwarsa. Jangka waktu peringatan bisa diatur.'],
                    ['title' => 'Golongan obat & NIE', 'desc' => 'Tandai obat bebas, bebas terbatas, atau obat resep, dan simpan nomor izin edar.'],
                    ['title' => 'Nomor resep', 'desc' => 'Penjualan obat resep meminta nomor resep. Nama pasien dan dokter bisa dicatat.'],
                    ['title' => 'Karantina & pemusnahan', 'desc' => 'Karantina batch bermasalah, rilis kembali bila aman, dan catat pemusnahan di laporannya sendiri.'],
                    ['title' => 'Resep & HPP', 'desc' => 'Hitung modal produk yang dibuat dari beberapa bahan berdasarkan resepnya.', 'paid' => true],
                    ['title' => 'Pembelian dari PBF', 'desc' => 'Catat pembelian dari distributor beserta batch dan kedaluwarsanya.', 'paid' => true],
                    ['title' => 'Laporan & ekspor', 'desc' => 'Laporan penjualan dan persediaan, bisa diekspor ke Excel atau PDF.'],
                ],
                'flow_title' => 'Alur obat di apotek',
                'flow_sub' => 'Contoh satu produk: amoksisilin (obat resep).',
                'flow' => [
                    ['title' => 'Obat masuk', 'desc' => 'Pembelian dari distributor dicatat dengan nomor batch dan tanggal kedaluwarsa.'],
                    ['title' => 'Pasien datang dengan resep', 'desc' => 'Kasir memasukkan amoksisilin. Karena obat resep, aplikasi meminta nomor resep.'],
                    ['title' => 'Batch terdekat terpakai', 'desc' => 'Stok diambil dari batch yang paling dekat kedaluwarsa, bukan batch terbaru.'],
                    ['title' => 'Peringatan kedaluwarsa', 'desc' => 'Batch yang mendekati kedaluwarsa muncul di dashboard untuk segera dijual atau diretur.'],
                ],
                'pricing_note' => 'Stok per batch, FEFO, peringatan kedaluwarsa, golongan obat, dan nomor resep aktif untuk apotek. Untuk apotek dengan banyak produk, paket GrowUp tidak membatasi jumlah produk.',
                'faq' => [
                    ['q' => 'Apakah aplikasi kasir apotek ini mencatat tanggal kedaluwarsa?', 'a' => 'Ya. Untuk jenis usaha apotek, setiap batch wajib punya tanggal kedaluwarsa, dan obat kedaluwarsa tidak bisa dijual.'],
                    ['q' => 'Apa itu FEFO dan apakah otomatis?', 'a' => 'FEFO (first expired, first out) berarti obat yang paling dekat kedaluwarsa dijual lebih dulu. Aplikasi memilih batch secara otomatis saat penjualan.'],
                    ['q' => 'Bagaimana dengan penjualan obat keras?', 'a' => 'Setiap produk bisa ditandai golongannya. Penjualan obat resep meminta nomor resep, dan nama pasien serta dokter bisa dicatat.'],
                    ['q' => 'Apakah terhubung dengan sistem pelaporan pemerintah?', 'a' => 'Belum. Aplikasi menyediakan laporan penjualan, persediaan, dan pemusnahan yang bisa diekspor ke Excel atau PDF untuk keperluan pencatatan Anda.'],
                ],
                'article_keywords' => ['stok', 'kedaluwarsa', 'FIFO', 'FEFO', 'apotek'],
            ],

            'aplikasi-kasir-laundry' => [
                'name' => 'Laundry',
                'eyebrow' => 'Laundry kiloan & satuan',
                'title' => 'Aplikasi Kasir Laundry Kiloan & Satuan | Kasir UMKM',
                'description' => 'Aplikasi kasir laundry kiloan dan satuan: catat order per kg atau per pcs, estimasi selesai, status cucian, DP dan pelunasan. Mulai gratis dari HP.',
                'h1' => 'Aplikasi Kasir Laundry Kiloan & Satuan',
                'lead' => 'Catat setiap cucian dari diterima sampai diambil. Harga per kilo atau per potong, estimasi selesai, status cucian, dan DP tercatat rapi, lalu masuk otomatis ke laporan penjualan.',
                'audience' => 'pemilik laundry',
                'cocok' => [
                    'Laundry kiloan rumahan dan dekat kampus',
                    'Laundry satuan (jas, bed cover, sepatu)',
                    'Laundry yang menerima DP dan pelunasan saat ambil',
                    'Pemilik yang ingin memantau cucian tanpa buku nota',
                ],
                'problems_title' => 'Masalah yang sering terjadi di laundry',
                'problems_sub' => 'Laundry bekerja dengan barang milik pelanggan, jadi catatan yang hilang berarti komplain.',
                'problems' => [
                    [
                        'problem' => 'Cucian tidak jelas statusnya',
                        'detail' => 'Pelanggan bertanya "sudah selesai belum?" dan karyawan harus mencari di tumpukan.',
                        'solution' => 'setiap order punya status (diterima, diproses, siap, selesai) yang terlihat di daftar order.',
                    ],
                    [
                        'problem' => 'DP dan pelunasan tercampur',
                        'detail' => 'Ada yang bayar di depan, ada yang bayar sebagian, dan ada yang bayar saat ambil.',
                        'solution' => 'order mencatat status bayar (belum, sebagian, lunas), dan pelunasan dicatat saat cucian diambil.',
                    ],
                    [
                        'problem' => 'Omzet laundry tidak terhitung',
                        'detail' => 'Nota tertulis tangan sulit dijumlahkan menjadi laporan bulanan.',
                        'solution' => 'saat order selesai, transaksi penjualan dibuat otomatis sehingga omzet muncul di laporan.',
                    ],
                ],
                'features_sub' => 'Menu Order Laundry aktif otomatis saat jenis usaha diatur sebagai laundry.',
                'features' => [
                    ['title' => 'Order per kg atau per pcs', 'desc' => 'Satu order bisa berisi beberapa layanan dengan satuan bebas, misalnya cuci setrika 3,5 kg dan bed cover 1 pcs.'],
                    ['title' => 'Estimasi selesai', 'desc' => 'Catat perkiraan tanggal selesai di setiap order.'],
                    ['title' => 'Status cucian', 'desc' => 'Ubah status dari diterima, diproses, siap diambil, sampai selesai.'],
                    ['title' => 'DP & pelunasan', 'desc' => 'Catat uang muka, sisa tagihan, dan pelunasan saat pengambilan.'],
                    ['title' => 'Data pelanggan', 'desc' => 'Simpan nama dan nomor HP pelanggan untuk setiap order.'],
                    ['title' => 'Masuk ke laporan', 'desc' => 'Order yang selesai otomatis tercatat sebagai penjualan di laporan.'],
                ],
                'flow_title' => 'Alur satu order laundry',
                'flow_sub' => 'Contoh order Mbak Rina.',
                'flow' => [
                    ['title' => 'Cucian diterima', 'desc' => 'Cuci setrika 4,2 kg dan 1 bed cover. Estimasi selesai 2 hari, DP Rp20.000.'],
                    ['title' => 'Diproses', 'desc' => 'Karyawan mengubah status menjadi diproses saat cucian mulai dikerjakan.'],
                    ['title' => 'Siap diambil', 'desc' => 'Status berubah menjadi siap. Daftar order menunjukkan cucian mana yang menunggu diambil.'],
                    ['title' => 'Diambil dan lunas', 'desc' => 'Sisa tagihan dibayar, order selesai, dan penjualan tercatat di laporan.'],
                ],
                'pricing_note' => 'Order laundry tersedia sejak paket gratis. Paket berbayar menambah fitur seperti shift kasir, pembelian bahan (deterjen, plastik), dan laporan lanjutan.',
                'faq' => [
                    ['q' => 'Apakah bisa menghitung harga per kilo dengan koma?', 'a' => 'Bisa. Jumlah bisa diisi desimal, misalnya 3,5 kg, dan total dihitung dari harga per kg.'],
                    ['q' => 'Apakah pelanggan mendapat notifikasi WhatsApp otomatis?', 'a' => 'Belum. Saat ini status cucian dipantau di aplikasi, dan nomor HP pelanggan tersimpan di setiap order.'],
                    ['q' => 'Bisa mencatat DP?', 'a' => 'Bisa. Status bayar setiap order bisa belum bayar, sebagian, atau lunas, dan pelunasan dicatat saat cucian diambil.'],
                    ['q' => 'Apakah omzet laundry masuk ke laporan?', 'a' => 'Ya. Saat order diselesaikan, transaksi penjualan dibuat otomatis sehingga omzet tercatat di laporan penjualan.'],
                ],
                'article_keywords' => ['laundry', 'laporan keuangan', 'laba'],
            ],

            'aplikasi-kasir-barbershop' => [
                'name' => 'Barbershop',
                'eyebrow' => 'Barbershop & salon',
                'title' => 'Aplikasi Kasir Barbershop & Salon | Kasir UMKM',
                'description' => 'Aplikasi kasir barbershop dan salon: antrean pelanggan, pilih kapster, komisi kapster otomatis, dan laporan komisi. Coba gratis dari HP.',
                'h1' => 'Aplikasi Kasir Barbershop & Salon',
                'lead' => 'Atur antrean pelanggan, pilih kapster di setiap transaksi, dan biarkan komisi kapster dihitung otomatis. Tidak ada lagi hitung bagi hasil manual di akhir bulan.',
                'audience' => 'pemilik barbershop',
                'cocok' => [
                    'Barbershop dengan beberapa kapster',
                    'Salon rambut dan salon kecantikan',
                    'Usaha jasa dengan sistem bagi hasil per layanan',
                    'Barbershop yang ramai antrean di akhir pekan',
                ],
                'problems_title' => 'Masalah yang sering terjadi di barbershop',
                'problems_sub' => 'Usaha jasa menjual waktu kapster, jadi antrean dan bagi hasil harus jelas.',
                'problems' => [
                    [
                        'problem' => 'Antrean berebut',
                        'detail' => 'Pelanggan lupa urutan, ada yang menyerobot, dan ada yang pulang karena tidak jelas menunggu berapa lama.',
                        'solution' => 'antrean bernomor dengan status menunggu, dilayani, dan selesai, plus pilihan kapster dan prioritas.',
                    ],
                    [
                        'problem' => 'Komisi kapster dihitung manual',
                        'detail' => 'Rekap layanan per kapster dari buku sering selisih dan memicu ketidakpuasan.',
                        'solution' => 'komisi dihitung otomatis per transaksi, dengan persentase atau nominal tetap, dan bisa berbeda per layanan.',
                    ],
                    [
                        'problem' => 'Penjualan produk tidak tercatat',
                        'detail' => 'Pomade dan produk rambut terjual tanpa catatan stok.',
                        'solution' => 'layanan dan produk dijual di kasir yang sama, dan stok produk berkurang otomatis.',
                    ],
                ],
                'features_sub' => 'Fitur barbershop aktif otomatis saat jenis usaha diatur sebagai barbershop.',
                'features' => [
                    ['title' => 'Antrean pelanggan', 'desc' => 'Nomor antrean dengan status menunggu, dilayani, dan selesai, lengkap dengan pilihan kapster.'],
                    ['title' => 'Pilih kapster di kasir', 'desc' => 'Setiap transaksi mencatat kapster yang melayani.'],
                    ['title' => 'Komisi otomatis', 'desc' => 'Atur komisi persentase atau nominal tetap, dan bedakan per layanan bila perlu.'],
                    ['title' => 'Laporan komisi', 'desc' => 'Lihat total komisi setiap kapster per periode untuk pembayaran bagi hasil.', 'paid' => true],
                    ['title' => 'Layanan & produk', 'desc' => 'Jual potong rambut, paket, dan produk rambut dalam satu transaksi.'],
                    ['title' => 'Poin & member', 'desc' => 'Beri poin untuk pelanggan langganan dan tukarkan dengan layanan gratis.', 'paid' => true],
                ],
                'flow_title' => 'Alur satu pelanggan barbershop',
                'flow_sub' => 'Contoh Sabtu sore yang ramai.',
                'flow' => [
                    ['title' => 'Ambil nomor antrean', 'desc' => 'Pelanggan mendapat nomor A-07 dan memilih kapster Dedi.'],
                    ['title' => 'Dilayani', 'desc' => 'Saat giliran tiba, status berubah menjadi dilayani.'],
                    ['title' => 'Bayar di kasir', 'desc' => 'Potong rambut + pomade dibayar dengan QRIS. Kapster Dedi tercatat di transaksi.'],
                    ['title' => 'Komisi tercatat', 'desc' => 'Komisi Dedi untuk layanan ini dihitung otomatis dan muncul di laporan komisi.'],
                ],
                'pricing_note' => 'Antrean, pilih kapster, dan pengaturan komisi bisa dipakai di paket gratis. Laporan komisi dan poin member ada di paket berbayar.',
                'faq' => [
                    ['q' => 'Apakah komisi kapster dihitung otomatis?', 'a' => 'Ya. Atur komisi dalam persentase atau nominal tetap, bisa berbeda per layanan. Komisi tercatat di setiap transaksi.'],
                    ['q' => 'Bisakah pelanggan memilih kapster saat antre?', 'a' => 'Bisa. Antrean mencatat kapster yang dipilih, dan antrean prioritas juga bisa ditandai.'],
                    ['q' => 'Apakah bisa untuk salon kecantikan?', 'a' => 'Bisa. Fitur antrean, kapster, dan komisi berlaku untuk usaha jasa seperti salon, dengan daftar layanan yang Anda atur sendiri.'],
                    ['q' => 'Bagaimana melihat total komisi per kapster?', 'a' => 'Laporan komisi di paket GrowUp menampilkan total komisi setiap kapster per periode.'],
                ],
                'article_keywords' => ['gaji', 'karyawan', 'laporan keuangan'],
            ],
        ];

        foreach ($pages as $slug => &$page) {
            $page['slug'] = $slug;
        }

        return $pages;
    }
}
