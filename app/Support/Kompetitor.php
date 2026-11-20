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
 * {name} {free} {free_limits} {paid} {paid_price} {outlet_addon}
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
        ];
    }
}
