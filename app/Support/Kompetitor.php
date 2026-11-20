<?php

namespace App\Support;

/**
 * Data & konten halaman "Alternatif [kompetitor]" (satu sumber kebenaran).
 *
 * ATURAN (halaman ini adalah klaim publik tentang perusahaan lain):
 * - Hanya tulis yang tercantum di situs resmi mereka, beri tanggal 'checked_at' dan 'sources'.
 * - "Tidak tercantum" bukan berarti "tidak ada": tulis "tidak tercantum di situs (per tanggal)".
 * - Jangan menebak alasan/motif mereka. Akui kelebihan mereka ('strengths').
 * - Periksa ulang setiap 3 bulan atau sebelum mengubah halaman.
 *
 * Placeholder yang diisi dari harga paket di database (lihat fill()):
 * {name} {free} {free_limits} {paid} {paid_price} {paid_yearly} {outlet_addon}
 */
final class Kompetitor
{
    /** @return array<string, mixed>|null */
    public static function find(string $key): ?array
    {
        $data = self::data()[$key] ?? null;

        return $data === null ? null : ['key' => $key] + $data;
    }

    /** @return list<string> kunci kompetitor */
    public static function keys(): array
    {
        return array_keys(self::data());
    }

    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        return array_map(fn (string $k) => self::find($k), self::keys());
    }

    /**
     * Isi placeholder harga Kasir UMKM (dari LandingPricing::payload()).
     *
     * @param  array<string, mixed>  $pricing
     */
    public static function fill(string $text, array $k, array $pricing): string
    {
        $free = $pricing['free'];
        $paid = $pricing['paid'];
        $limits = implode(', ', array_filter([
            $free['max_stores'].' outlet',
            $free['max_products'] ? $free['max_products'].' produk' : null,
            $free['max_customers'] ? $free['max_customers'].' pelanggan' : null,
        ]));

        return strtr($text, [
            '{name}' => $k['name'],
            '{free}' => $free['name'],
            '{free_limits}' => $limits,
            '{paid}' => $paid['name'],
            '{paid_price}' => $paid['monthly_label'] !== '' ? $paid['monthly_label'] : 'lihat harga',
            '{paid_yearly}' => $paid['yearly_label'] !== '' ? $paid['yearly_label'] : 'lihat harga',
            '{outlet_addon}' => $paid['outlet_addon_label'],
        ]);
    }

    /** @return array<string, array<string, mixed>> */
    private static function data(): array
    {
        return [
            'loyverse' => [
                'slug' => 'alternatif-loyverse',
                'name' => 'Loyverse',
                'checked_at' => '2026-11-20',
                'checked_label' => '20 November 2026',
                'sources' => [
                    ['label' => 'halaman harga Loyverse', 'url' => 'https://loyverse.com/id/pricing'],
                    ['label' => 'halaman inventaris Loyverse', 'url' => 'https://loyverse.com/id/advanced-inventory'],
                    ['label' => 'halaman unduhan Loyverse', 'url' => 'https://loyverse.com/id/download-loyverse'],
                ],
                'h1' => 'Alternatif Loyverse: aplikasi kasir dengan harga Rupiah untuk UMKM Indonesia',
                'description' => 'Mencari alternatif Loyverse? Bandingkan harga dan fitur dengan Kasir UMKM by MaccaPOS: harga dalam Rupiah, tanpa biaya per karyawan, fitur usaha lokal.',
                'lead' => 'Loyverse adalah aplikasi kasir gratis yang populer. Banyak pemilik usaha mulai mencari alternatif saat butuh fitur berbayarnya, karena biaya tambahan dihitung dalam dolar per toko dan per karyawan. Berikut perbandingan yang jujur, termasuk kapan Loyverse justru lebih cocok.',
                'tldr' => [
                    ['Harga', 'paket {paid} Kasir UMKM {paid_price} per bulan dalam Rupiah, sudah termasuk karyawan dan stok lanjutan. Di Loyverse, keduanya add-on berbayar dalam USD.'],
                    ['Fitur lokal', 'Kasir UMKM punya utang pelanggan, mode apotek, order laundry, dan komisi kapster.'],
                    ['Loyverse lebih unggul', 'bila Anda butuh banyak toko gratis, layar pelanggan, atau aplikasi iPhone.'],
                ],
                'reasons' => [
                    ['Biaya tambahan dalam dolar', 'Fasilitas tambahan Loyverse ditagih dalam USD dan belum termasuk pajak, sehingga biaya bulanan dalam Rupiah ikut kurs.'],
                    ['Biaya naik seiring jumlah karyawan', 'Manajemen karyawan Loyverse dihitung per karyawan. Semakin banyak kasir, semakin besar biayanya.'],
                    ['Butuh fitur khas usaha Indonesia', 'Seperti catatan utang pelanggan warung, nomor resep di apotek, atau order laundry kiloan dengan DP.'],
                ],
                'compare' => [
                    ['Kasir (POS) & struk', 'Gratis (paket {free})', 'Gratis'],
                    ['Batas paket gratis', 'Transaksi tanpa batas, {free_limits}', 'Lihat situs Loyverse'],
                    ['Mata uang tagihan', 'Rupiah', 'Dolar AS (USD), belum termasuk pajak'],
                    ['Karyawan & hak akses', 'Termasuk paket {paid} (kasir, gudang, manajer, admin), tanpa biaya per karyawan', 'Add-on $5 USD / bulan per karyawan'],
                    ['Stok lanjutan (pembelian, opname)', 'Termasuk paket {paid}', 'Add-on $25 USD / bulan per toko'],
                    ['Rentang laporan penjualan', 'Hingga 1 tahun per laporan (paket {paid})', 'Riwayat tanpa batas: add-on $9 USD / bulan per toko'],
                    ['Beberapa toko / outlet', 'Add-on {outlet_addon} / bulan per outlet', 'Gratis (add-on dihitung per toko)'],
                    ['Layar dapur (KDS)', 'Termasuk paket {paid}', 'Gratis'],
                    ['Layar pelanggan (CDS)', 'Belum tersedia', 'Gratis'],
                ],
                'source_note' => 'Setiap fasilitas tambahan Loyverse punya uji coba gratis 14 hari tanpa kartu.',
                'cost' => [
                    'title' => 'Contoh biaya bulanan: 1 toko, 3 karyawan',
                    'sub' => 'Toko yang ingin akun terpisah untuk 3 karyawan, laporan penjualan panjang, dan pengelolaan stok lengkap (pembelian & opname).',
                    'outlets' => 1,
                    'theirs_title' => 'Loyverse (add-on yang dibutuhkan)',
                    'theirs' => [
                        ['Loyverse POS', 'Gratis'],
                        ['Riwayat penjualan tanpa batas', '$9 USD'],
                        ['Manajemen karyawan × 3', '$15 USD'],
                        ['Manajemen stok barang', '$25 USD'],
                    ],
                    'theirs_total' => '$49 USD / bulan',
                    'theirs_note' => 'Belum termasuk pajak, ditagih dalam USD (nilai Rupiah mengikuti kurs).',
                    'ours' => [
                        ['Kasir & struk', 'Termasuk'],
                        ['Laporan penjualan (rentang hingga 1 tahun)', 'Termasuk'],
                        ['Akun karyawan & hak akses', 'Termasuk'],
                        ['Pembelian & stok opname', 'Termasuk'],
                    ],
                    'ours_note' => 'Dalam Rupiah. Absensi & payroll karyawan tersedia sebagai add-on terpisah.',
                ],
                'strengths' => [
                    ['Multi toko gratis', 'Mengelola beberapa toko sudah termasuk di Loyverse POS gratis.'],
                    ['Layar dapur & layar pelanggan gratis', 'Kitchen Display System dan Customer Display System tercantum gratis.'],
                    ['Aplikasi iPhone dan Android', 'Loyverse POS tersedia di App Store dan Google Play.'],
                    ['Produk global yang matang', 'Dipakai di banyak negara, tersedia dalam banyak bahasa, dengan API untuk pengembang.'],
                    ['Hanya butuh fitur dasar', 'Stok dasar gratis: pelacakan real-time, notifikasi stok menipis, item komposit, varian, dan impor item.'],
                ],
                'fit_us' => [
                    'Ingin biaya tetap dalam Rupiah, tanpa hitungan per karyawan',
                    'Punya beberapa kasir atau staf gudang dalam satu outlet',
                    'Butuh utang pelanggan, mode apotek, laundry, atau komisi kapster',
                    'Ingin menghitung HPP dan produksi dari resep',
                ],
                'faq' => [
                    ['Apa alternatif Loyverse untuk UMKM di Indonesia?', 'Kasir UMKM by MaccaPOS adalah aplikasi kasir buatan Indonesia dengan harga dalam Rupiah. Paket {free} gratis, dan paket {paid} {paid_price} per bulan per outlet tanpa biaya tambahan per karyawan.'],
                    ['Apakah Loyverse gratis?', 'Menurut halaman harga Loyverse, Loyverse POS, Dashboard, layar dapur, dan layar pelanggan gratis. Riwayat penjualan tanpa batas, manajemen karyawan, dan manajemen stok lanjutan adalah fasilitas tambahan berbayar dalam dolar AS.'],
                    ['Bagaimana kalau usaha saya punya beberapa cabang?', 'Di Kasir UMKM, setiap outlet tambahan adalah add-on {outlet_addon} per bulan, dan stok bisa ditransfer antar outlet. Di Loyverse, mengelola beberapa toko tercantum gratis, tetapi fasilitas tambahan dihitung per toko.'],
                ],
            ],

            'moka' => [
                'slug' => 'alternatif-moka-pos',
                'name' => 'Moka POS',
                'checked_at' => '2026-11-20',
                'checked_label' => '20 November 2026',
                'sources' => [
                    ['label' => 'halaman harga Moka', 'url' => 'https://www.mokapos.com/harga'],
                ],
                'h1' => 'Alternatif Moka POS: aplikasi kasir mulai gratis untuk UMKM',
                'description' => 'Mencari alternatif Moka POS yang lebih hemat? Bandingkan harga per outlet, batas karyawan, dan fitur dengan Kasir UMKM by MaccaPOS. Ada paket gratis.',
                'lead' => 'Moka POS adalah aplikasi kasir yang lengkap dan banyak dipakai di Indonesia, dengan paket mulai Rp299.000 per outlet per bulan. Untuk usaha kecil yang baru mulai atau punya beberapa cabang, biaya per outlet ini sering jadi pertimbangan. Berikut perbandingan yang jujur, termasuk kapan Moka justru lebih cocok.',
                'tldr' => [
                    ['Harga', 'Kasir UMKM punya paket {free} gratis dan paket {paid} {paid_price} per outlet per bulan. Paket Moka mulai Rp299.000 per outlet per bulan.'],
                    ['Karyawan', 'Kasir UMKM tidak membatasi atau menagih per karyawan. Paket Moka mencantumkan kuota 5–20 karyawan per outlet dan Rp10.000 per slot tambahan.'],
                    ['Moka lebih unggul', 'bila Anda butuh pembayaran digital terintegrasi, training tatap muka, atau pendampingan account manager.'],
                ],
                'reasons' => [
                    ['Biaya per outlet terasa berat di awal', 'Paket Moka mulai Rp299.000 per outlet per bulan. Untuk warung atau kedai baru, biaya tetap ini cukup besar dibanding omzet awal.'],
                    ['Cabang bertambah, biaya ikut berlipat', 'Harga Moka dihitung per outlet, jadi dua outlet berarti dua kali biaya paket.'],
                    ['Ingin mulai gratis dulu', 'Banyak pemilik usaha ingin mencoba aplikasi kasir tanpa biaya bulanan sebelum yakin berlangganan.'],
                ],
                'compare' => [
                    ['Paket gratis', 'Ada: paket {free}, transaksi tanpa batas, {free_limits}', 'Tidak tercantum paket gratis di halaman harga (ada tombol "Coba gratis")'],
                    ['Harga paket berbayar', '{paid}: {paid_price} / outlet / bulan', 'Basic Rp299.000, Pro Rp499.000, Enterprise Rp799.000 / outlet / bulan'],
                    ['Karyawan per outlet', 'Tanpa batas & tanpa biaya per karyawan (paket {paid})', 'Basic 5, Pro 10, Enterprise 20; tambahan Rp10.000 / slot'],
                    ['Outlet tambahan', 'Add-on {outlet_addon} / outlet / bulan', 'Harga paket per outlet'],
                    ['Menu digital & pesan lewat QR meja', 'Paket {paid} (usaha F&B dan bakery)', 'Tercantum di tabel fitur'],
                    ['Pembayaran QRIS', 'Catat pembayaran QRIS (gambar QRIS statis milik toko)', 'Pembayaran digital e-wallet & QRIS tercantum di tabel fitur'],
                    ['Bahan baku & stok lanjutan', 'Resep & HPP, pembelian, stok opname (paket {paid})', 'Ingredients & Advanced Inventory tercantum di tabel fitur'],
                    ['Dukungan', 'WhatsApp, telepon & email', 'Live chat & WhatsApp, call centre, training tatap muka; account manager (min. 5 outlet)'],
                ],
                'source_note' => 'Fitur per paket Moka berbeda-beda; lihat tabel perbandingan di situs Moka untuk rinciannya.',
                'cost' => [
                    'title' => 'Contoh biaya bulanan: 2 outlet',
                    'sub' => 'Usaha dengan 2 outlet, masing-masing dengan beberapa kasir, memakai paket berbayar terendah.',
                    'outlets' => 2,
                    'theirs_title' => 'Moka POS (paket Basic)',
                    'theirs' => [
                        ['Paket Basic outlet 1', 'Rp299.000'],
                        ['Paket Basic outlet 2', 'Rp299.000'],
                    ],
                    'theirs_total' => 'Rp598.000 / bulan',
                    'theirs_note' => 'Kuota 5 karyawan per outlet; slot tambahan Rp10.000. Fitur paket Basic dan {paid} tidak identik.',
                    'ours' => [
                        ['Paket {paid} (outlet 1)', '{paid_price}'],
                        ['Outlet tambahan', '{outlet_addon}'],
                        ['Akun karyawan', 'Termasuk'],
                    ],
                    'ours_note' => 'Dalam Rupiah, tanpa biaya per karyawan.',
                ],
                'strengths' => [
                    ['Pembayaran digital terintegrasi', 'Moka mencantumkan penerimaan pembayaran digital melalui e-wallet dan QRIS.'],
                    ['Dukungan tatap muka', 'Setup & training tatap muka, call centre, serta live chat & WhatsApp tercantum di halaman harga.'],
                    ['Account manager', 'Pendampingan account manager untuk usaha dengan minimal 5 outlet.'],
                    ['Kuota pelanggan besar', 'Database pelanggan hingga 10.000–50.000 per bisnis, atau tanpa batas di paket Enterprise.'],
                ],
                'fit_us' => [
                    'Baru mulai dan ingin mencoba kasir tanpa biaya bulanan',
                    'Punya banyak kasir per outlet dan tidak ingin dihitung per karyawan',
                    'Ingin menambah cabang dengan biaya per outlet yang lebih ringan',
                    'Butuh mode apotek, laundry, atau komisi kapster',
                ],
                'faq' => [
                    ['Apa alternatif Moka POS yang lebih murah?', 'Kasir UMKM by MaccaPOS punya paket {free} gratis selamanya dan paket {paid} {paid_price} per outlet per bulan, tanpa biaya per karyawan.'],
                    ['Berapa harga Moka POS?', 'Menurut halaman harga Moka, paket Basic Rp299.000, Pro Rp499.000, dan Enterprise Rp799.000 per outlet per bulan.'],
                    ['Apakah Kasir UMKM bisa menerima QRIS seperti Moka?', 'Kasir UMKM mencatat pembayaran QRIS dengan menampilkan gambar QRIS statis milik toko Anda. Pembayaran tidak diproses lewat gateway, jadi pengecekan dana masuk dilakukan di aplikasi bank atau e-wallet Anda.'],
                ],
            ],

            'pawoon' => [
                'slug' => 'alternatif-pawoon',
                'name' => 'Pawoon',
                'checked_at' => '2026-11-20',
                'checked_label' => '20 November 2026',
                'sources' => [
                    ['label' => 'halaman harga Pawoon', 'url' => 'https://www.pawoon.com/harga/'],
                ],
                'h1' => 'Alternatif Pawoon: aplikasi kasir gratis tanpa batas transaksi harian',
                'description' => 'Mencari alternatif Pawoon? Paket gratis Kasir UMKM tanpa batas transaksi harian, paket berbayar lebih hemat per outlet. Bandingkan harga dan fiturnya.',
                'lead' => 'Pawoon adalah aplikasi kasir Indonesia dengan paket gratis dan paket Pro Rp299rb per outlet per bulan. Paket gratisnya dibatasi 7 transaksi per hari, sehingga banyak usaha kecil yang sudah ramai mulai mencari pilihan lain. Berikut perbandingan yang jujur, termasuk kapan Pawoon justru lebih cocok.',
                'tldr' => [
                    ['Paket gratis', 'paket {free} Kasir UMKM tanpa batas transaksi harian. Paket gratis Pawoon dibatasi 7 transaksi per hari.'],
                    ['Harga', 'paket {paid} Kasir UMKM {paid_price} per outlet per bulan. Paket Pro Pawoon Rp299rb per outlet per bulan.'],
                    ['Pawoon lebih unggul', 'bila Anda butuh integrasi pembayaran digital, GrabFood (lewat Digiresto), atau Jurnal & Accurate.'],
                ],
                'reasons' => [
                    ['Paket gratis cepat habis', 'Paket gratis Pawoon dibatasi 7 transaksi per hari. Warung yang ramai bisa melewatinya sebelum siang.'],
                    ['Naik ke Pro terasa mahal', 'Paket Pro Pawoon Rp299rb per outlet per bulan, cukup besar untuk usaha yang baru tumbuh.'],
                    ['Butuh fitur khas usaha tertentu', 'Seperti mode apotek dengan batch dan kedaluwarsa, order laundry, atau komisi kapster.'],
                ],
                'compare' => [
                    ['Transaksi di paket gratis', 'Tanpa batas (paket {free})', '7 transaksi / hari'],
                    ['Batas lain paket gratis', '{free_limits}', 'Satu outlet, input produk terbatas'],
                    ['Harga paket berbayar', '{paid}: {paid_price} / outlet / bulan', 'Pro: Rp299rb / outlet / bulan'],
                    ['Outlet tambahan', 'Add-on {outlet_addon} / outlet / bulan', 'Harga paket per outlet'],
                    ['Karyawan & hak akses', 'Termasuk paket {paid}, tanpa biaya per karyawan', 'Multi-karyawan tercantum di tabel fitur'],
                    ['Absensi karyawan', 'Add-on Absensi & Payroll', 'Absensi (face recognition) tercantum di tabel fitur'],
                    ['Integrasi', 'Belum ada integrasi otomatis; penjualan GoFood, GrabFood, ShopeeFood bisa dicatat manual beserta potongan komisinya', 'Pembayaran digital, EDC, Jurnal & Accurate, GrabFood via Digiresto, Open API tercantum'],
                    ['Fitur usaha khusus', 'Mode apotek, laundry, barbershop, produksi', 'Tidak tercantum di halaman harga'],
                ],
                'source_note' => 'Rincian fitur per paket Pawoon ada di tabel perbandingan di situs Pawoon.',
                'cost' => [
                    'title' => 'Contoh biaya bulanan: 1 outlet yang sudah ramai',
                    'sub' => 'Warung dengan lebih dari 7 transaksi per hari, sehingga paket gratis Pawoon tidak cukup.',
                    'outlets' => 1,
                    'theirs_title' => 'Pawoon',
                    'theirs' => [
                        ['Paket gratis (maks. 7 transaksi/hari)', 'Tidak cukup'],
                        ['Paket Pro', 'Rp299rb'],
                    ],
                    'theirs_total' => 'Rp299rb / bulan',
                    'theirs_note' => 'Fitur paket Pro Pawoon dan paket {paid} tidak identik; bandingkan fitur yang Anda butuhkan.',
                    'ours' => [
                        ['Paket {free} (transaksi tanpa batas)', 'Gratis'],
                        ['Atau paket {paid} untuk fitur lengkap', '{paid_price}'],
                    ],
                    'ours_total' => 'Mulai Gratis',
                    'ours_note' => 'Paket {free} gratis selama produk dan pelanggan masih dalam batasnya.',
                ],
                'strengths' => [
                    ['Integrasi pembayaran', 'Pembayaran digital (GoPay, OVO, ShopeePay, DANA, LinkAja) dan EDC tercantum di tabel fitur.'],
                    ['Integrasi akuntansi & pesan antar', 'Integrasi Jurnal & Accurate Online serta Digiresto & GrabFood tercantum.'],
                    ['Absensi face recognition', 'Absensi karyawan dengan pengenalan wajah tercantum di tabel fitur.'],
                    ['Jual pulsa & paket data', 'Toko bisa menjual produk digital dari aplikasi kasir.'],
                ],
                'fit_us' => [
                    'Transaksi harian sudah lebih dari 7 tapi belum siap bayar Rp299rb',
                    'Ingin paket berbayar yang lebih hemat per outlet',
                    'Butuh mode apotek, laundry, atau komisi kapster',
                    'Ingin menghitung HPP dan produksi dari resep',
                ],
                'faq' => [
                    ['Apa alternatif Pawoon yang gratis?', 'Kasir UMKM by MaccaPOS punya paket {free} gratis selamanya tanpa batas transaksi harian, untuk {free_limits}.'],
                    ['Berapa batas transaksi paket gratis Pawoon?', 'Menurut halaman harga Pawoon, paket gratis dibatasi 7 transaksi per hari untuk satu outlet.'],
                    ['Apakah Kasir UMKM terhubung ke GrabFood atau Accurate?', 'Belum terhubung otomatis. Penjualan GoFood, GrabFood, dan ShopeeFood bisa dicatat manual sebagai channel penjualan beserta potongan komisinya. Bila integrasi otomatis penting, Pawoon mencantumkannya di tabel fiturnya.'],
                ],
            ],

            'majoo' => [
                'slug' => 'alternatif-majoo',
                'name' => 'Majoo',
                'checked_at' => '2026-11-20',
                'checked_label' => '20 November 2026',
                'sources' => [
                    ['label' => 'halaman harga Majoo', 'url' => 'https://majoo.id/harga'],
                ],
                'h1' => 'Alternatif Majoo: aplikasi kasir mulai gratis untuk UMKM yang baru tumbuh',
                'description' => 'Mencari alternatif Majoo yang lebih ringan biayanya? Bandingkan harga per outlet dan fitur dengan Kasir UMKM by MaccaPOS. Ada paket gratis selamanya.',
                'lead' => 'Majoo adalah aplikasi bisnis lengkap, dari kasir, akuntansi, sampai payroll, dengan paket mulai Rp249.000 per outlet per bulan. Untuk warung, kedai, atau toko kecil yang baru mulai, banyak fiturnya belum dibutuhkan dan biaya per outlet jadi pertimbangan. Berikut perbandingan yang jujur, termasuk kapan Majoo justru lebih cocok.',
                'tldr' => [
                    ['Harga', 'Kasir UMKM punya paket {free} gratis dan paket {paid} {paid_price} per outlet per bulan. Paket Majoo mulai Rp249.000 per outlet per bulan (belum PPN).'],
                    ['Cabang', 'paket Starter Majoo untuk satu outlet; multi outlet mulai paket Advance Rp499.000 per outlet. Di Kasir UMKM, outlet tambahan {outlet_addon} per bulan.'],
                    ['Majoo lebih unggul', 'bila Anda butuh integrasi GoFood/GrabFood, QRIS dinamis & EDC, toko online & marketplace, atau payroll lengkap dalam satu sistem.'],
                ],
                'reasons' => [
                    ['Fiturnya lebih banyak dari yang dibutuhkan', 'Majoo mencakup akuntansi, toko online, hingga payroll. Usaha kecil yang baru mulai sering hanya butuh kasir, stok, dan laporan.'],
                    ['Biaya per outlet cukup besar', 'Paket Majoo mulai Rp249.000 per outlet per bulan, belum termasuk PPN.'],
                    ['Ingin mencoba tanpa biaya bulanan', 'Banyak pemilik usaha ingin memakai aplikasi kasir gratis dulu sebelum berlangganan.'],
                ],
                'compare' => [
                    ['Paket gratis', 'Ada: paket {free}, transaksi tanpa batas, {free_limits}', 'Tidak tercantum paket gratis di halaman harga (ada tombol "Coba Gratis")'],
                    ['Harga paket berbayar', '{paid}: {paid_price} / outlet / bulan', 'Starter Rp249.000, Advance Rp499.000, Prime Rp999.000 / outlet / bulan (belum PPN); Prime+ lewat konsultasi'],
                    ['Lebih dari satu outlet', 'Add-on {outlet_addon} / outlet / bulan', 'Starter: satu outlet; multi outlet mulai paket Advance'],
                    ['Meja & multi satuan', 'Meja (usaha F&B & bakery) dan multi satuan', 'Mulai paket Advance'],
                    ['Produksi & HPP', 'Produksi dari bahan baku, resep & HPP (paket {paid})', 'Fitur produksi stok & COGS average mulai paket Advance'],
                    ['Harga grosir & batch', 'Harga grosir per barang; batch & kedaluwarsa di mode apotek', 'Grosir, batch number, serial number di paket Prime'],
                    ['Pembayaran digital', 'Catat pembayaran QRIS (gambar QRIS statis milik toko)', 'QRIS di Starter; QRIS dinamis & EDC mulai Advance'],
                    ['Pesanan GoFood / GrabFood', 'Dicatat manual sebagai channel penjualan beserta komisinya', 'Integrasi order online GrabFood & GoFood di paket Prime'],
                    ['Akuntansi', 'Laporan laba rugi, neraca, jurnal (paket {paid})', 'Akuntansi lengkap mulai paket Advance'],
                    ['Absensi & payroll', 'Add-on Absensi & Payroll', 'Payroll & majoo Teams di paket Prime'],
                ],
                'source_note' => 'Halaman harga Majoo juga menyebut diskon hingga 50% dan paket bundling perangkat; rincian fitur per paket ada di situs Majoo.',
                'cost' => [
                    'title' => 'Contoh biaya bulanan: 1 outlet',
                    'sub' => 'Toko atau kedai dengan satu outlet yang memakai paket berbayar terendah.',
                    'outlets' => 1,
                    'theirs_title' => 'Majoo (paket Starter)',
                    'theirs' => [
                        ['Paket Starter (single outlet)', 'Rp249.000'],
                    ],
                    'theirs_total' => 'Rp249.000 / bulan',
                    'theirs_note' => 'Belum termasuk PPN. Paket Starter Majoo sudah mencakup toko online, QRIS, dan pencatatan keuangan; fiturnya tidak identik dengan paket {paid}.',
                    'ours' => [
                        ['Paket {paid}', '{paid_price}'],
                        ['Atau paket {free}', 'Gratis'],
                    ],
                    'ours_note' => 'Dalam Rupiah. Paket {free} gratis selama produk dan pelanggan masih dalam batasnya.',
                ],
                'strengths' => [
                    ['Integrasi pesan antar', 'Order online GrabFood & GoFood tercantum di paket Prime.'],
                    ['Pembayaran terintegrasi', 'QRIS dinamis dan EDC kartu debit/kredit tercantum mulai paket Advance.'],
                    ['Toko online & marketplace', 'Website, weborder, dan omnichannel marketplace (Shopee, Grabmart) tercantum di paket Prime.'],
                    ['Satu sistem dari kasir sampai payroll', 'Akuntansi lengkap, payroll, dan aplikasi karyawan majoo Teams dalam satu langganan.'],
                    ['Tetap jalan tanpa internet di banyak perangkat', 'Local server untuk multi perangkat tanpa koneksi internet tercantum di paket Prime.'],
                ],
                'fit_us' => [
                    'Baru mulai dan ingin kasir gratis atau biaya bulanan yang ringan',
                    'Hanya butuh kasir, stok, laporan, dan karyawan tanpa modul ERP',
                    'Ingin menambah cabang dengan biaya per outlet yang lebih ringan',
                    'Butuh mode apotek, laundry, atau komisi kapster',
                ],
                'faq' => [
                    ['Apa alternatif Majoo yang lebih murah?', 'Kasir UMKM by MaccaPOS punya paket {free} gratis selamanya dan paket {paid} {paid_price} per outlet per bulan. Outlet tambahan {outlet_addon} per bulan.'],
                    ['Berapa harga Majoo?', 'Menurut halaman harga Majoo, paket Starter Rp249.000, Advance Rp499.000, dan Prime Rp999.000 per outlet per bulan, belum termasuk PPN. Paket Prime+ lewat konsultasi.'],
                    ['Apakah Kasir UMKM terhubung ke GoFood dan GrabFood seperti Majoo?', 'Belum terhubung otomatis. Penjualan GoFood, GrabFood, dan ShopeeFood bisa dicatat manual sebagai channel penjualan beserta potongan komisinya. Majoo mencantumkan integrasi order online di paket Prime.'],
                ],
            ],

            'qasir' => [
                'slug' => 'alternatif-qasir',
                'name' => 'Qasir',
                'checked_at' => '2026-11-20',
                'checked_label' => '20 November 2026',
                'sources' => [
                    ['label' => 'halaman harga Qasir', 'url' => 'https://www.qasir.id/en/pricing'],
                ],
                'h1' => 'Alternatif Qasir: aplikasi kasir gratis selamanya, bisa bayar bulanan',
                'description' => 'Mencari alternatif Qasir? Kasir UMKM punya paket gratis selamanya, bisa bayar bulanan, dan fitur khusus apotek, laundry, dan barbershop. Bandingkan di sini.',
                'lead' => 'Qasir adalah aplikasi kasir Indonesia yang terjangkau: paket Pro Rp699.000 per tahun dengan uji coba gratis 7 hari. Bila Anda ingin terus memakai versi gratis, membayar per bulan, atau butuh fitur khusus jenis usaha, berikut perbandingan yang jujur, termasuk kapan Qasir justru lebih cocok.',
                'tldr' => [
                    ['Paket gratis', 'Kasir UMKM punya paket {free} gratis selamanya. Halaman harga Qasir mencantumkan uji coba gratis 7 hari.'],
                    ['Cara bayar', 'paket {paid} bisa dibayar bulanan ({paid_price}) atau tahunan ({paid_yearly}). Paket Qasir ditagih per 12 bulan.'],
                    ['Qasir lebih unggul', 'bila Anda siap bayar setahun di muka: paket Pro Qasir Rp699.000 per tahun lebih murah dari paket {paid} tahunan.'],
                ],
                'reasons' => [
                    ['Masa uji coba singkat', 'Uji coba gratis Qasir berlaku 7 hari. Sebagian pemilik usaha ingin memakai versi gratis lebih lama.'],
                    ['Harus bayar setahun di muka', 'Paket Qasir ditagih sekali per 12 bulan, sehingga perlu menyiapkan biaya setahun sekaligus.'],
                    ['Butuh fitur khas jenis usaha', 'Seperti mode apotek dengan batch dan kedaluwarsa, order laundry, komisi kapster, atau produksi dari resep.'],
                ],
                'compare' => [
                    ['Paket gratis', 'Ada, tanpa batas waktu: paket {free} ({free_limits})', 'Tidak tercantum paket gratis; uji coba gratis 7 hari, semua fitur'],
                    ['Harga paket berbayar', '{paid}: {paid_price} / bulan atau {paid_yearly} / tahun', 'Pro Rp699.000 / 12 bulan; Pro Plus Rp1.199.000 / 12 bulan (belum pajak)'],
                    ['Cara bayar', 'Bulanan atau tahunan', 'Ditagih sekali per 12 bulan'],
                    ['Akun karyawan', 'Termasuk paket {paid}, tanpa biaya per karyawan', 'Akun staf tanpa batas dengan hak akses'],
                    ['Jumlah produk', 'Paket {free} dibatasi; paket {paid} tanpa batas', 'Produk tanpa batas'],
                    ['Outlet tambahan', 'Add-on {outlet_addon} / outlet / bulan', 'Tidak dirinci di halaman harga'],
                    ['Fitur usaha khusus', 'Mode apotek, laundry, barbershop, produksi & HPP, pesan lewat QR meja', 'Tidak tercantum di halaman harga'],
                ],
                'source_note' => 'Halaman harga Qasir menyebut harga belum termasuk pajak dan terakhir ditinjau 11 Oktober 2026.',
                'cost' => [
                    'title' => 'Contoh biaya setahun: 1 outlet',
                    'sub' => 'Toko dengan satu outlet. Bila Anda siap bayar tahunan dan hanya butuh fitur kasir umum, Qasir Pro lebih murah.',
                    'outlets' => 1,
                    'theirs_title' => 'Qasir (paket Pro)',
                    'theirs' => [
                        ['Uji coba', 'Gratis 7 hari'],
                        ['Paket Pro (12 bulan)', 'Rp699.000'],
                    ],
                    'theirs_total' => 'Rp699.000 / tahun',
                    'theirs_note' => 'Belum termasuk pajak, ditagih sekali per 12 bulan.',
                    'ours' => [
                        ['Paket {free} (tanpa batas waktu)', 'Gratis'],
                        ['Paket {paid} tahunan', '{paid_yearly}'],
                        ['Atau paket {paid} bulanan', '{paid_price} / bulan'],
                    ],
                    'ours_total' => 'Mulai Gratis',
                    'ours_note' => 'Untuk paket berbayar setahun penuh, Qasir Pro lebih murah. Kasir UMKM unggul bila Anda ingin tetap gratis atau membayar per bulan.',
                ],
                'strengths' => [
                    ['Harga tahunan lebih murah', 'Paket Pro Rp699.000 per 12 bulan, sekitar Rp1.900 per hari menurut halaman harganya.'],
                    ['Staf dan produk tanpa batas', 'Akun staf dan produk tanpa batas tercantum di paket berbayarnya.'],
                    ['Struk & laporan lanjutan', 'Struk digital lewat email, nomor antrean di struk, dan laporan penjualan yang dikirim otomatis ke email.'],
                ],
                'fit_us' => [
                    'Ingin memakai aplikasi kasir gratis tanpa batas waktu',
                    'Lebih nyaman membayar per bulan daripada setahun di muka',
                    'Butuh mode apotek, laundry, komisi kapster, atau produksi dari resep',
                    'Restoran atau kafe yang ingin pesan lewat QR meja dan layar dapur',
                ],
                'faq' => [
                    ['Apa alternatif Qasir yang gratis?', 'Kasir UMKM by MaccaPOS punya paket {free} gratis selamanya dengan transaksi tanpa batas, untuk {free_limits}.'],
                    ['Berapa harga Qasir?', 'Menurut halaman harga Qasir, paket Pro Rp699.000 dan Pro Plus Rp1.199.000 per 12 bulan, belum termasuk pajak, dengan uji coba gratis 7 hari.'],
                    ['Mana yang lebih murah, Qasir atau Kasir UMKM?', 'Untuk paket berbayar setahun penuh, Qasir Pro (Rp699.000 per tahun) lebih murah dari paket {paid} tahunan ({paid_yearly}). Kasir UMKM lebih hemat bila paket {free} gratis sudah cukup, atau bila Anda ingin membayar per bulan.'],
                ],
            ],

            'olsera' => [
                'slug' => 'alternatif-olsera',
                'name' => 'Olsera',
                'checked_at' => '2026-11-20',
                'checked_label' => '20 November 2026',
                'sources' => [
                    ['label' => 'halaman harga Olsera', 'url' => 'https://www.olsera.com/id/pricing'],
                ],
                'h1' => 'Alternatif Olsera: aplikasi kasir gratis, bisa bayar per bulan',
                'description' => 'Mencari alternatif Olsera? Kasir UMKM punya paket gratis selamanya, bisa bayar bulanan, dan fitur apotek, laundry, serta barbershop. Bandingkan harganya.',
                'lead' => 'Olsera adalah aplikasi kasir dengan paket tahunan mulai Rp1.288.000 per tahun (belum PPN) dan uji coba gratis 14 hari. Bila Anda ingin memulai gratis, membayar per bulan, atau butuh fitur khusus jenis usaha, berikut perbandingan yang jujur, termasuk kapan Olsera justru lebih cocok.',
                'tldr' => [
                    ['Paket gratis', 'Kasir UMKM punya paket {free} gratis selamanya. Halaman harga Olsera mencantumkan uji coba gratis 14 hari.'],
                    ['Cara bayar', 'paket {paid} bisa dibayar bulanan ({paid_price}) atau tahunan ({paid_yearly}). Paket Olsera tercantum per tahun, mulai Rp1.288.000 (belum PPN).'],
                    ['Olsera lebih unggul', 'bila Anda butuh POS Windows & iOS, integrasi GrabFood, integrasi software akuntansi, atau marketplace.'],
                ],
                'reasons' => [
                    ['Biaya tahunan di muka', 'Paket Olsera tercantum per tahun, sehingga perlu menyiapkan biaya setahun sekaligus.'],
                    ['Ingin mulai gratis', 'Uji coba Olsera berlaku 14 hari. Sebagian pemilik usaha ingin memakai versi gratis lebih lama sebelum berlangganan.'],
                    ['Butuh fitur khas jenis usaha', 'Seperti mode apotek dengan batch dan kedaluwarsa, order laundry, atau komisi kapster.'],
                ],
                'compare' => [
                    ['Paket gratis', 'Ada, tanpa batas waktu: paket {free} ({free_limits})', 'Tidak tercantum paket gratis; uji coba gratis 14 hari'],
                    ['Harga paket berbayar', '{paid}: {paid_price} / bulan atau {paid_yearly} / tahun', 'Basic Rp1.288.000, Premium Rp1.988.000, Pro Rp2.688.000 / tahun (belum PPN)'],
                    ['Cara bayar', 'Bulanan atau tahunan', 'Harga tercantum per tahun'],
                    ['Karyawan & perangkat', 'Akun karyawan termasuk paket {paid}, tanpa biaya per karyawan', 'Basic: 3 perangkat; Premium & Pro: karyawan dan perangkat tanpa batas'],
                    ['Platform', 'Browser (HP, tablet, laptop) dan aplikasi Android', 'POS Android & Windows (Basic); + iOS (Premium & Pro)'],
                    ['Pesanan GrabFood', 'Dicatat manual sebagai channel penjualan beserta komisinya', 'Integrasi GrabFood tercantum di paket Basic'],
                    ['Absensi karyawan', 'Add-on Absensi & Payroll', 'Absensi PIN (Basic), absensi wajah (Pro)'],
                    ['Fitur usaha khusus', 'Mode apotek, laundry, barbershop, produksi & HPP', 'Tidak tercantum di halaman harga'],
                ],
                'source_note' => 'Harga Olsera yang dikutip adalah harga yang ditampilkan (harga coret Rp1.888.000, Rp2.888.000, Rp3.888.000) dan belum termasuk PPN.',
                'cost' => [
                    'title' => 'Contoh biaya setahun: 1 usaha',
                    'sub' => 'Usaha yang membandingkan paket berbayar terendah selama setahun.',
                    'outlets' => 1,
                    'theirs_title' => 'Olsera (paket Basic)',
                    'theirs' => [
                        ['Uji coba', 'Gratis 14 hari'],
                        ['Paket Basic (1 tahun)', 'Rp1.288.000'],
                    ],
                    'theirs_total' => 'Rp1.288.000 / tahun',
                    'theirs_note' => 'Belum termasuk PPN. Paket Basic Olsera sudah mencakup integrasi GrabFood dan POS Windows; fiturnya tidak identik dengan paket {paid}.',
                    'ours' => [
                        ['Paket {free} (tanpa batas waktu)', 'Gratis'],
                        ['Paket {paid} tahunan', '{paid_yearly}'],
                        ['Atau paket {paid} bulanan', '{paid_price} / bulan'],
                    ],
                    'ours_total' => 'Mulai Gratis',
                    'ours_note' => 'Bisa mulai dari paket {free}, lalu naik ke paket {paid} bulanan saat usaha berkembang.',
                ],
                'strengths' => [
                    ['POS Windows & iOS', 'Aplikasi kasir untuk Windows PC (semua paket) dan iOS (Premium & Pro).'],
                    ['Integrasi GrabFood', 'Integrasi GrabFood tercantum sejak paket Basic.'],
                    ['Akuntansi & marketplace', 'Integrasi software akuntansi (Premium) serta integrasi marketplace dan Olsera Akunting (Pro).'],
                    ['Karyawan & perangkat tanpa batas', 'Paket Premium dan Pro tidak membatasi jumlah karyawan dan perangkat.'],
                ],
                'fit_us' => [
                    'Ingin memakai aplikasi kasir gratis tanpa batas waktu',
                    'Lebih nyaman membayar per bulan daripada setahun di muka',
                    'Butuh mode apotek, laundry, atau komisi kapster',
                    'Ingin menghitung HPP dan produksi dari resep',
                ],
                'faq' => [
                    ['Apa alternatif Olsera yang gratis?', 'Kasir UMKM by MaccaPOS punya paket {free} gratis selamanya dengan transaksi tanpa batas, untuk {free_limits}.'],
                    ['Berapa harga Olsera?', 'Menurut halaman harga Olsera, paket Basic Rp1.288.000, Premium Rp1.988.000, dan Pro Rp2.688.000 per tahun, belum termasuk PPN, dengan uji coba gratis 14 hari.'],
                    ['Apakah Kasir UMKM bisa dipakai di Windows?', 'Bisa lewat browser di laptop atau PC Windows. Aplikasi yang diinstal saat ini tersedia untuk Android.'],
                ],
            ],

            'kasir-pintar' => [
                'slug' => 'alternatif-kasir-pintar',
                'name' => 'Kasir Pintar',
                'checked_at' => '2026-11-20',
                'checked_label' => '20 November 2026',
                'sources' => [
                    ['label' => 'halaman harga Kasir Pintar', 'url' => 'https://kasirpintar.co.id/harga'],
                ],
                'h1' => 'Alternatif Kasir Pintar: fitur usaha lengkap dalam satu paket',
                'description' => 'Mencari alternatif Kasir Pintar? Bandingkan paket gratis, batas akun staf, dan fitur bahan baku, menu restoran, serta apotek dengan Kasir UMKM by MaccaPOS.',
                'lead' => 'Kasir Pintar adalah aplikasi kasir yang populer dengan paket gratis dan paket Pro Rp666.000 per tahun. Beberapa fitur seperti bahan baku, menu restoran, dan absensi tersedia sebagai plugin terpisah. Bila Anda ingin fitur tersebut dalam satu paket, berikut perbandingan yang jujur, termasuk kapan Kasir Pintar justru lebih cocok.',
                'tldr' => [
                    ['Fitur dalam satu paket', 'resep & HPP, layar dapur, pesan lewat QR meja, dan produksi termasuk paket {paid}. Di Kasir Pintar, bahan baku dan food menu adalah plugin terpisah.'],
                    ['Akun staf', 'paket {paid} tanpa batas dan tanpa biaya per karyawan. Kasir Pintar Pro mencantumkan hingga 5 akun staf.'],
                    ['Kasir Pintar lebih unggul', 'bila Anda butuh paket gratis dengan banyak barang (hingga 1.000), harga Pro tahunan yang lebih murah, atau aplikasi iOS & desktop.'],
                ],
                'reasons' => [
                    ['Fitur tambahan dibeli terpisah', 'Bahan baku, food menu, POS offline, dan absensi StaffPlus tercantum sebagai plugin berbayar di luar paket Pro.'],
                    ['Staf lebih dari 5 orang', 'Kasir Pintar Pro mencantumkan multi-user hingga 5 akun staf.'],
                    ['Butuh fitur khas jenis usaha', 'Seperti mode apotek dengan batch, kedaluwarsa, dan nomor resep.'],
                ],
                'compare' => [
                    ['Paket gratis', 'Paket {free}: transaksi tanpa batas, {free_limits}', 'Free: transaksi tanpa batas (hanya 1.000 data terbaru tampil), barang hingga 1.000, 1 pengguna, struk dengan watermark'],
                    ['Harga paket berbayar', '{paid}: {paid_price} / bulan atau {paid_yearly} / tahun', 'Pro: Rp666.000 / tahun (juga tersedia 6 bulan Rp333.000)'],
                    ['Akun staf', 'Tanpa batas & tanpa biaya per karyawan (paket {paid})', 'Pro: hingga 5 akun staf'],
                    ['Jumlah barang', 'Paket {free} dibatasi; paket {paid} tanpa batas', 'Free hingga 1.000; Pro hingga 10.000'],
                    ['Bahan baku / resep & HPP', 'Termasuk paket {paid}', 'Plugin Bahan Baku, berbayar terpisah'],
                    ['Fitur restoran', 'Pesan lewat QR meja & layar dapur, paket {paid}', 'Plugin Food Menu ("fitur profesional untuk restoran"), berbayar terpisah'],
                    ['Absensi karyawan', 'Add-on Absensi & Payroll', 'Plugin StaffPlus, berbayar terpisah'],
                    ['Mode apotek (batch, kedaluwarsa, resep)', 'Ada', 'Tidak tercantum di halaman harga'],
                ],
                'source_note' => 'Harga plugin Kasir Pintar tercantum per bulan di situsnya; rinciannya bisa dicek langsung.',
                'cost' => [
                    'title' => 'Contoh: restoran kecil dengan 6 staf',
                    'sub' => 'Restoran yang butuh resep & bahan baku, fitur menu/meja, dan akun untuk 6 staf.',
                    'outlets' => 1,
                    'theirs_title' => 'Kasir Pintar',
                    'theirs' => [
                        ['Paket Pro (1 tahun)', 'Rp666.000'],
                        ['Plugin Bahan Baku', 'Berbayar terpisah'],
                        ['Plugin Food Menu', 'Berbayar terpisah'],
                        ['Akun staf', 'Maks. 5 di paket Pro'],
                    ],
                    'theirs_total' => 'Rp666.000 + plugin',
                    'theirs_note' => 'Untuk kebutuhan kasir umum tanpa plugin, Kasir Pintar Pro lebih murah per tahun.',
                    'ours' => [
                        ['Paket {paid} bulanan', '{paid_price}'],
                        ['Resep & HPP, QR meja, layar dapur', 'Termasuk'],
                        ['Akun staf', 'Tanpa batas'],
                    ],
                    'ours_note' => 'Atau bayar tahunan {paid_yearly}. Dalam satu paket, tanpa plugin terpisah.',
                ],
                'strengths' => [
                    ['Paket gratis yang longgar', 'Kasir Pintar Free mencantumkan transaksi tanpa batas dan hingga 1.000 barang.'],
                    ['Harga Pro tahunan lebih murah', 'Paket Pro Rp666.000 per tahun, atau Rp333.000 untuk 6 bulan.'],
                    ['Banyak aplikasi', 'Tersedia Kasir Pintar Pro untuk Android, aplikasi iOS, dan Kasir Pintar Desktop.'],
                    ['Ekosistem usaha', 'Jualan online, QRIS, cek resi & ongkir, hingga pinjaman dana tercantum di situsnya.'],
                ],
                'fit_us' => [
                    'Punya lebih dari 5 staf dan tidak ingin dibatasi jumlah akun',
                    'Ingin resep & HPP, QR meja, dan layar dapur tanpa plugin terpisah',
                    'Butuh mode apotek, laundry, atau komisi kapster',
                    'Lebih nyaman membayar per bulan',
                ],
                'faq' => [
                    ['Apa alternatif Kasir Pintar untuk restoran?', 'Kasir UMKM by MaccaPOS menyediakan resep & HPP, pesan lewat QR meja, dan layar dapur dalam paket {paid} ({paid_price} per bulan), tanpa plugin terpisah dan tanpa batas akun staf.'],
                    ['Berapa harga Kasir Pintar Pro?', 'Menurut halaman harga Kasir Pintar, paket Pro Rp666.000 per tahun atau Rp333.000 untuk 6 bulan. Plugin seperti Bahan Baku, Food Menu, dan StaffPlus berbayar terpisah.'],
                    ['Mana yang lebih murah, Kasir Pintar atau Kasir UMKM?', 'Untuk kasir umum tanpa plugin, Kasir Pintar Pro tahunan lebih murah. Kasir UMKM bisa lebih hemat bila Anda butuh banyak fitur yang di Kasir Pintar dijual sebagai plugin, atau punya lebih dari 5 staf.'],
                ],
            ],
        ];
    }
}
