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
     * Ringkasan per aplikasi untuk halaman /aplikasi-kasir-terbaik (Kasir UMKM + semua kompetitor).
     * Hanya berisi fakta yang sudah ada di data() per kompetitor (bersumber & bertanggal).
     *
     * @return list<array{key: string, name: string, slug: ?string, free: string, price: string, billing: string, best_for: string, consider: string}>
     */
    public static function summaries(): array
    {
        $rows = [
            'kasir-umkm' => [
                'name' => 'Kasir UMKM by MaccaPOS',
                'slug' => null,
                'free' => 'Paket {free} gratis selamanya: transaksi tanpa batas, {free_limits}',
                'price' => '{paid}: {paid_price} / outlet / bulan',
                'billing' => 'Per outlet, bulanan atau tahunan; tanpa biaya per karyawan atau perangkat',
                'best_for' => 'UMKM yang ingin mulai gratis, punya beberapa staf, atau butuh mode apotek, laundry, barbershop, dan F&B (QR meja, layar dapur, resep & HPP)',
                'consider' => 'Paket gratis dibatasi 30 produk; belum ada integrasi otomatis ojol & pembayaran; belum ada aplikasi iOS',
            ],
            'loyverse' => [
                'free' => 'POS, beberapa toko, layar dapur & layar pelanggan gratis',
                'price' => 'Add-on mulai $5 USD / bulan per karyawan',
                'billing' => 'Add-on dalam USD, per toko atau per karyawan',
                'best_for' => 'Toko yang cukup dengan fitur dasar gratis, atau punya banyak toko',
                'consider' => 'Karyawan, stok lanjutan, dan riwayat penjualan panjang adalah add-on berbayar dalam USD',
            ],
            'moka' => [
                'free' => 'Tidak tercantum paket gratis (ada tombol coba gratis)',
                'price' => 'Basic Rp299.000 / outlet / bulan',
                'billing' => 'Per outlet; kuota karyawan per paket',
                'best_for' => 'Usaha mapan yang butuh pembayaran digital terintegrasi dan training tatap muka',
                'consider' => 'Biaya per outlet relatif tinggi untuk usaha yang baru mulai',
            ],
            'pawoon' => [
                'free' => 'Gratis 7 transaksi / hari, satu outlet',
                'price' => 'Pro Rp299rb / outlet / bulan',
                'billing' => 'Per outlet',
                'best_for' => 'Usaha yang butuh integrasi pembayaran digital, Jurnal & Accurate, atau GrabFood',
                'consider' => 'Paket gratis dibatasi 7 transaksi per hari',
            ],
            'majoo' => [
                'free' => 'Tidak tercantum paket gratis (ada tombol coba gratis)',
                'price' => 'Starter Rp249.000 / outlet / bulan (belum PPN)',
                'billing' => 'Per outlet; Starter untuk satu outlet',
                'best_for' => 'Bisnis yang ingin satu sistem dari kasir, akuntansi, toko online, sampai payroll',
                'consider' => 'Banyak fitur integrasi ada di paket yang lebih tinggi',
            ],
            'qasir' => [
                'free' => 'Uji coba gratis 7 hari',
                'price' => 'Pro Rp699.000 / 12 bulan (belum pajak)',
                'billing' => 'Ditagih per 12 bulan',
                'best_for' => 'Toko dengan kebutuhan kasir umum yang siap bayar tahunan dengan harga hemat',
                'consider' => 'Tidak ada pilihan bayar bulanan di halaman harganya',
            ],
            'olsera' => [
                'free' => 'Uji coba gratis 14 hari',
                'price' => 'Basic Rp1.288.000 / tahun (belum PPN)',
                'billing' => 'Harga per tahun',
                'best_for' => 'Usaha yang butuh POS Windows atau iOS dan integrasi GrabFood',
                'consider' => 'Paket Basic dibatasi 3 perangkat',
            ],
            'kasir-pintar' => [
                'free' => 'Gratis: transaksi tanpa batas, hingga 1.000 barang, 1 pengguna',
                'price' => 'Pro Rp666.000 / tahun',
                'billing' => 'Paket 6 atau 12 bulan; plugin per bulan',
                'best_for' => 'Toko kecil yang butuh paket gratis longgar dan harga Pro murah',
                'consider' => 'Bahan baku, food menu, dan absensi adalah plugin terpisah; Pro maks. 5 akun staf',
            ],
            'ireap' => [
                'free' => 'iREAP POS Lite gratis',
                'price' => 'PRO Rp99.000 / bulan per perangkat (Rp500.000 / 12 bulan)',
                'billing' => 'Per voucher: 1 voucher untuk 1 perangkat',
                'best_for' => 'Toko dengan 1 mesin kasir, atau usaha dengan salesman keliling',
                'consider' => 'Biaya bertambah untuk setiap perangkat kasir',
            ],
            'accurate-pos' => [
                'free' => 'Uji coba gratis 30 hari',
                'price' => 'Paket Dasar Rp222.000 / bulan (termasuk PPN)',
                'billing' => 'Bulanan; perangkat, meja, outlet, dan pengguna back office sebagai add-on',
                'best_for' => 'Usaha yang sudah memakai ekosistem Accurate',
                'consider' => 'Biaya bertambah dengan add-on',
            ],
            'kasir-aja' => [
                'free' => 'Gratis: transaksi tanpa batas, satu outlet, katalog terbatas',
                'price' => 'Premium Rp149.000 / outlet / bulan (belum pajak)',
                'billing' => 'Per outlet',
                'best_for' => 'Usaha yang butuh QRIS terintegrasi, aplikasi iOS, toko online, atau PPOB',
                'consider' => 'Fitur khusus apotek, laundry, dan barbershop tidak tercantum di halaman harga',
            ],
            'esb' => [
                'free' => 'Basic mulai dari Rp0 (syarat & ketentuan berlaku)',
                'price' => 'Advanced mulai dari Rp499.000 / bulan',
                'billing' => 'Basic & Advanced "mulai dari"; Enterprise sesuai kebutuhan',
                'best_for' => 'Restoran dan kafe multi-cabang yang butuh integrasi ojol, pembayaran digital, dan ERP',
                'consider' => 'Khusus bisnis kuliner; harga detail lewat konsultasi',
            ],
            'iseller' => [
                'free' => 'Tidak tercantum paket gratis (ada tombol coba gratis)',
                'price' => 'Starter Rp300.000 / bulan',
                'billing' => 'Per paket; kasir tambahan Rp225.000–Rp450.000 / bulan; add-on terpisah',
                'best_for' => 'Usaha yang butuh omnichannel (Shopee, Lazada, online ordering) dan integrasi Accurate/Jurnal',
                'consider' => 'Biaya bertambah untuk setiap kasir tambahan dan add-on',
            ],
            'jurnal-pos' => [
                'free' => 'Tidak tercantum paket gratis; ada uji coba dan demo gratis',
                'price' => 'Essentials Rp299.000 / bulan (belum PPN; promo Rp209.300 untuk langganan 1 tahun)',
                'billing' => 'Per paket; Plus untuk lebih dari 1 outlet',
                'best_for' => 'Usaha F&B dan retail yang sudah memakai software akuntansi Mekari Jurnal',
                'consider' => 'Keunggulan utamanya terasa bila memakai Mekari Jurnal',
            ],
            'kasironline' => [
                'free' => 'Gratis: 1 toko, 2 pengguna, laporan 31 hari terakhir',
                'price' => 'Pro Rp99.000 / toko / bulan (belum pajak)',
                'billing' => 'Per toko; cabang & layar dapur di Business Rp249.000',
                'best_for' => 'Toko yang butuh QRIS terintegrasi dan struk atau laporan lewat WhatsApp',
                'consider' => 'Cabang, layar dapur, dan absensi ada di paket Business',
            ],
        ];

        $out = [];
        foreach ($rows as $key => $row) {
            $k = $key === 'kasir-umkm' ? null : self::find($key);
            $out[] = ['key' => $key, 'name' => $k['name'] ?? $row['name'], 'slug' => $k['slug'] ?? null] + $row;
        }

        return $out;
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

            'ireap' => [
                'slug' => 'alternatif-ireap',
                'name' => 'iReap POS',
                'checked_at' => '2026-11-20',
                'checked_label' => '20 November 2026',
                'sources' => [
                    ['label' => 'kalkulator harga iReap POS PRO', 'url' => 'https://www.ireappos.com/#harga'],
                ],
                'h1' => 'Alternatif iReap POS: satu harga per outlet, tanpa biaya per perangkat',
                'description' => 'Mencari alternatif iReap POS? Bandingkan biaya per perangkat, aplikasi Android dan browser, serta fitur apotek, laundry, dan restoran dengan Kasir UMKM.',
                'lead' => 'iReap POS adalah aplikasi kasir Android yang sudah lama dipakai UMKM, dengan versi Lite gratis dan versi PRO yang dihitung per perangkat (voucher). Bila toko Anda memakai beberapa mesin kasir, atau ingin membuka kasir dari browser di perangkat apa pun, berikut perbandingan yang jujur, termasuk kapan iReap justru lebih cocok.',
                'tldr' => [
                    ['Cara hitung biaya', 'Kasir UMKM dihitung per outlet ({paid_price} per bulan), tanpa biaya per perangkat. iReap PRO dihitung per voucher: 1 voucher untuk 1 perangkat.'],
                    ['Perangkat', 'Kasir UMKM berjalan di browser HP, tablet, laptop, dan aplikasi Android. iReap POS adalah aplikasi Android dengan web dashboard untuk pemilik.'],
                    ['iReap lebih unggul', 'bila Anda hanya memakai 1 mesin kasir dan siap bayar setahun: voucher 12 bulan Rp500.000, plus fitur salesman keliling.'],
                ],
                'reasons' => [
                    ['Biaya naik per mesin kasir', '1 voucher iReap PRO berlaku untuk 1 perangkat. Toko dengan 2 atau 3 mesin kasir perlu 2 atau 3 voucher.'],
                    ['Ingin kasir dari browser', 'Sebagian pemilik ingin kasir bisa dibuka di laptop atau tablet apa pun tanpa memasang aplikasi.'],
                    ['Butuh fitur khas jenis usaha', 'Seperti mode apotek, order laundry, komisi kapster, atau pesan lewat QR meja.'],
                ],
                'compare' => [
                    ['Versi gratis', 'Paket {free}: transaksi tanpa batas, {free_limits}', 'iREAP POS Lite gratis di Google Play'],
                    ['Harga versi berbayar', '{paid}: {paid_price} / outlet / bulan atau {paid_yearly} / tahun', 'PRO per voucher: Rp99.000 (1 bulan), Rp199.000 (3 bulan), Rp299.000 (6 bulan), Rp500.000 (12 bulan)'],
                    ['Hitungan biaya', 'Per outlet, tanpa biaya per perangkat', '1 voucher = 1 perangkat, 1 aplikasi'],
                    ['Pengguna', 'Akun karyawan termasuk paket {paid}', 'User tidak dibatasi'],
                    ['Perangkat kasir', 'Browser (HP, tablet, laptop) dan aplikasi Android', 'Aplikasi Android; web dashboard pemilik gratis'],
                    ['Uji coba versi berbayar', 'Bisa mulai dari paket {free} tanpa batas waktu', 'Coba PRO gratis 14 hari'],
                    ['Fitur usaha khusus', 'Mode apotek, laundry, barbershop, QR meja & layar dapur', 'Tidak tercantum di bagian harga'],
                ],
                'source_note' => 'Harga iReap diambil dari kalkulator harga di beranda iReap; voucher tambahan juga dihitung untuk perangkat helper, salesman, dan iREAP Invoice.',
                'cost' => [
                    'title' => 'Contoh biaya: 1 toko, 2 mesin kasir',
                    'sub' => 'Toko dengan dua perangkat kasir yang dipakai bersamaan.',
                    'outlets' => 1,
                    'theirs_title' => 'iReap POS PRO (2 voucher)',
                    'theirs' => [
                        ['Voucher 1 bulan × 2 perangkat', 'Rp198.000'],
                        ['Atau voucher 12 bulan × 2 perangkat', 'Rp1.000.000 / tahun'],
                    ],
                    'theirs_total' => 'Rp198.000 / bulan',
                    'theirs_note' => 'Bila dibayar 12 bulan: Rp1.000.000 per tahun untuk 2 perangkat. Paket {paid} tahunan: {paid_yearly}.',
                    'ours' => [
                        ['Paket {paid} (1 outlet)', '{paid_price}'],
                        ['Perangkat kasir kedua', 'Tanpa biaya'],
                    ],
                    'ours_note' => 'Dalam Rupiah, dihitung per outlet berapa pun perangkatnya.',
                ],
                'strengths' => [
                    ['Murah untuk 1 perangkat tahunan', 'Voucher PRO 12 bulan Rp500.000, sekitar Rp1.396 per hari menurut kalkulatornya.'],
                    ['Versi Lite gratis', 'iREAP POS Lite tersedia gratis di Google Play.'],
                    ['Salesman keliling & invoice', 'Salesman bisa membuat sales order dari lapangan, dan iREAP Invoice untuk piutang & tagihan.'],
                    ['Pengguna tidak dibatasi', 'Kalkulator harga iReap menyebut user tidak dibatasi dan web dashboard pemilik gratis.'],
                ],
                'fit_us' => [
                    'Memakai lebih dari satu perangkat kasir di satu outlet',
                    'Ingin membuka kasir dari browser di laptop atau tablet apa pun',
                    'Butuh mode apotek, laundry, komisi kapster, atau QR meja',
                    'Ingin menghitung HPP dan produksi dari resep',
                ],
                'faq' => [
                    ['Apa alternatif iReap POS untuk toko dengan beberapa kasir?', 'Kasir UMKM by MaccaPOS dihitung per outlet: paket {paid} {paid_price} per bulan tanpa biaya per perangkat, jadi menambah mesin kasir tidak menambah biaya.'],
                    ['Berapa harga iReap POS PRO?', 'Menurut kalkulator harga iReap, satu voucher PRO (untuk 1 perangkat) Rp99.000 per bulan, Rp199.000 per 3 bulan, Rp299.000 per 6 bulan, atau Rp500.000 per 12 bulan.'],
                    ['Apakah iReap POS gratis?', 'iREAP POS Lite tersedia gratis di Google Play. Versi PRO berbayar per voucher dengan uji coba gratis 14 hari.'],
                ],
            ],

            'accurate-pos' => [
                'slug' => 'alternatif-accurate-pos',
                'name' => 'Accurate POS',
                'checked_at' => '2026-11-20',
                'checked_label' => '20 November 2026',
                'sources' => [
                    ['label' => 'halaman harga Accurate POS', 'url' => 'https://accurate.id/pos/harga/'],
                ],
                'h1' => 'Alternatif Accurate POS: aplikasi kasir mulai gratis untuk UMKM',
                'description' => 'Mencari alternatif Accurate POS? Bandingkan harga paket, add-on outlet, perangkat, dan meja dengan Kasir UMKM by MaccaPOS yang punya paket gratis.',
                'lead' => 'Accurate POS adalah aplikasi kasir dari tim Accurate, dengan Paket Dasar Rp222.000 per bulan (sudah termasuk PPN) dan add-on untuk outlet, perangkat, meja, serta pengguna back office. Bila Anda ingin mulai gratis atau ingin fitur tersebut sudah termasuk dalam satu paket, berikut perbandingan yang jujur, termasuk kapan Accurate POS justru lebih cocok.',
                'tldr' => [
                    ['Harga', 'Kasir UMKM punya paket {free} gratis dan paket {paid} {paid_price} per bulan. Paket Dasar Accurate POS Rp222.000 per bulan (termasuk PPN), dengan uji coba gratis 30 hari.'],
                    ['Add-on', 'di paket {paid}, beberapa perangkat dan fitur meja sudah termasuk. Di Accurate POS, Multi Device (Rp22.200) dan Table Management (Rp55.500) adalah add-on bulanan.'],
                    ['Accurate POS lebih unggul', 'bila Anda sudah memakai ekosistem Accurate, atau butuh layar dapur dan layanan pesan antar yang sedang gratis (beta).'],
                ],
                'reasons' => [
                    ['Biaya bertambah dengan add-on', 'Perangkat tambahan, manajemen meja, outlet tambahan, dan pengguna back office di Accurate POS masing-masing add-on bulanan.'],
                    ['Ingin mulai tanpa biaya', 'Paket Dasar Accurate POS Rp222.000 per bulan setelah uji coba 30 hari. Sebagian usaha kecil ingin paket gratis untuk mulai.'],
                    ['Butuh fitur khas jenis usaha', 'Seperti mode apotek, order laundry, atau komisi kapster.'],
                ],
                'compare' => [
                    ['Paket gratis', 'Ada: paket {free}, transaksi tanpa batas, {free_limits}', 'Tidak tercantum paket gratis; uji coba gratis 30 hari'],
                    ['Harga paket', '{paid}: {paid_price} / bulan', 'Paket Dasar Rp222.000 / bulan (termasuk PPN 11%)'],
                    ['Pengguna', 'Akun karyawan termasuk paket {paid}', '1 akses pengguna; pengguna back office tambahan Rp22.200 / bulan'],
                    ['Perangkat tambahan', 'Tanpa biaya per perangkat', 'Multi Device: add-on Rp22.200 / bulan'],
                    ['Outlet tambahan', 'Add-on {outlet_addon} / bulan', 'Multi Outlet: add-on Rp222.000 / bulan'],
                    ['Manajemen meja', 'Termasuk paket {paid} (usaha F&B & bakery)', 'Table Management: add-on Rp55.500 / bulan'],
                    ['Layar dapur (KDS)', 'Termasuk paket {paid}', 'Gratis (beta)'],
                    ['Cara bayar', 'Bulanan atau tahunan', 'Tagihan bulanan, bisa deposit saldo'],
                    ['Fitur usaha khusus', 'Mode apotek, laundry, barbershop, produksi & HPP', 'Tidak tercantum di halaman harga'],
                ],
                'source_note' => 'Halaman harga Accurate POS menyebut harga sudah termasuk PPN 11%; layar dapur dan Food Delivery Service berlabel beta dan gratis.',
                'cost' => [
                    'title' => 'Contoh biaya bulanan: restoran 1 outlet, 2 perangkat',
                    'sub' => 'Restoran kecil dengan dua perangkat kasir dan pengaturan meja.',
                    'outlets' => 1,
                    'theirs_title' => 'Accurate POS',
                    'theirs' => [
                        ['Paket Dasar', 'Rp222.000'],
                        ['Multi Device', 'Rp22.200'],
                        ['Table Management', 'Rp55.500'],
                    ],
                    'theirs_total' => 'Rp299.700 / bulan',
                    'theirs_note' => 'Sudah termasuk PPN 11% menurut halaman harganya.',
                    'ours' => [
                        ['Paket {paid}', '{paid_price}'],
                        ['Perangkat kedua', 'Tanpa biaya'],
                        ['Meja & pesan lewat QR meja', 'Termasuk'],
                    ],
                    'ours_note' => 'Dalam Rupiah, tanpa biaya per perangkat.',
                ],
                'strengths' => [
                    ['Ekosistem Accurate', 'Dikembangkan oleh tim Accurate, cocok bila usaha Anda sudah memakai produk Accurate.'],
                    ['Layar dapur & pesan antar gratis (beta)', 'Kitchen Display System dan Food Delivery Service tercantum gratis selama beta.'],
                    ['Harga sudah termasuk PPN', 'Harga di halaman Accurate POS sudah termasuk PPN 11%.'],
                    ['Deposit saldo', 'Biaya bulanan bisa dipotong otomatis dari saldo yang didepositkan.'],
                ],
                'fit_us' => [
                    'Ingin mulai dengan paket gratis tanpa batas waktu',
                    'Memakai beberapa perangkat kasir tanpa biaya tambahan',
                    'Restoran atau kafe yang butuh meja dan QR meja tanpa add-on terpisah',
                    'Butuh mode apotek, laundry, atau komisi kapster',
                ],
                'faq' => [
                    ['Apa alternatif Accurate POS untuk UMKM?', 'Kasir UMKM by MaccaPOS punya paket {free} gratis dan paket {paid} {paid_price} per bulan, tanpa biaya per perangkat, dengan meja dan QR meja untuk usaha F&B.'],
                    ['Berapa harga Accurate POS?', 'Menurut halaman harga Accurate POS, Paket Dasar Rp222.000 per bulan termasuk PPN 11%, dengan add-on Multi Outlet Rp222.000, Multi Device Rp22.200, Table Management Rp55.500, dan pengguna back office Rp22.200 per bulan.'],
                    ['Apakah Kasir UMKM terhubung dengan Accurate Online?', 'Belum. Kasir UMKM punya laporan keuangan sendiri (laba rugi, neraca, jurnal) dan bisa mengekspor laporan ke Excel atau PDF.'],
                ],
            ],

            'kasir-aja' => [
                'slug' => 'alternatif-kasiraja',
                'name' => 'KasirAja',
                'checked_at' => '2026-11-20',
                'checked_label' => '20 November 2026',
                'sources' => [
                    ['label' => 'halaman harga KasirAja', 'url' => 'https://kasiraja.id/harga-aplikasi-kasir-murah'],
                    ['label' => 'syarat & ketentuan KasirAja', 'url' => 'https://kasiraja.id/terms-and-conditon'],
                ],
                'h1' => 'Alternatif KasirAja: paket berbayar lebih hemat dan fitur khusus jenis usaha',
                'description' => 'Mencari alternatif KasirAja? Bandingkan paket gratis, harga Premium per outlet, dan fitur apotek, laundry, serta barbershop dengan Kasir UMKM by MaccaPOS.',
                'lead' => 'KasirAja adalah aplikasi kasir Indonesia dengan paket Gratis dan paket Premium Rp149.000 per outlet per bulan. Keduanya sama-sama punya paket gratis, jadi perbedaannya ada di harga paket berbayar dan fitur khusus jenis usaha. Berikut perbandingan yang jujur, termasuk kapan KasirAja justru lebih cocok.',
                'tldr' => [
                    ['Harga', 'paket {paid} Kasir UMKM {paid_price} per outlet per bulan. Paket Premium KasirAja Rp149.000 per outlet per bulan.'],
                    ['Fitur usaha', 'Kasir UMKM punya mode apotek, order laundry, dan komisi kapster.'],
                    ['KasirAja lebih unggul', 'bila Anda butuh QRIS terintegrasi dengan notifikasi pembayaran, aplikasi iOS, toko online, atau penjualan pulsa & PPOB.'],
                ],
                'reasons' => [
                    ['Ingin paket berbayar lebih hemat', 'Paket Premium KasirAja Rp149.000 per outlet per bulan, belum termasuk pajak menurut syarat & ketentuannya.'],
                    ['Butuh fitur khas jenis usaha', 'Seperti mode apotek dengan batch, kedaluwarsa, dan nomor resep, order laundry, atau komisi kapster.'],
                    ['Ingin mencoba kasir dari browser', 'Kasir UMKM bisa dibuka dari browser HP, tablet, atau laptop tanpa memasang aplikasi.'],
                ],
                'compare' => [
                    ['Paket gratis', 'Paket {free}: transaksi tanpa batas, {free_limits}', 'Gratis: transaksi tanpa batas, satu outlet, katalog produk terbatas, laporan standar'],
                    ['Harga paket berbayar', '{paid}: {paid_price} / outlet / bulan', 'Premium: Rp149.000 / outlet / bulan (belum pajak)'],
                    ['Outlet tambahan', 'Add-on {outlet_addon} / outlet / bulan', 'Harga Premium per outlet'],
                    ['Pembayaran QRIS', 'Catat pembayaran QRIS (gambar QRIS statis milik toko)', 'QR pembayaran dibuat dari aplikasi, dengan notifikasi saat pembayaran sukses'],
                    ['Fitur restoran', 'QR meja, layar dapur, resep & HPP (paket {paid})', 'QR table, aplikasi dapur & waiters, resep produk tercantum'],
                    ['Pesanan GoFood / GrabFood', 'Dicatat manual sebagai channel penjualan beserta komisinya', 'Pencatatan GrabFood & GoFood terpisah'],
                    ['Perangkat', 'Browser (HP, tablet, laptop) dan aplikasi Android', 'Aplikasi KasirAja POS iOS dan Android'],
                    ['Fitur usaha khusus', 'Mode apotek, laundry, barbershop, produksi', 'Tidak tercantum di halaman harga'],
                ],
                'source_note' => 'Syarat & ketentuan KasirAja menyebut uji coba gratis 14 hari dan biaya belum termasuk pajak.',
                'cost' => [
                    'title' => 'Contoh biaya bulanan: 2 outlet',
                    'sub' => 'Usaha dengan dua outlet yang memakai paket berbayar.',
                    'outlets' => 2,
                    'theirs_title' => 'KasirAja (paket Premium)',
                    'theirs' => [
                        ['Premium outlet 1', 'Rp149.000'],
                        ['Premium outlet 2', 'Rp149.000'],
                    ],
                    'theirs_total' => 'Rp298.000 / bulan',
                    'theirs_note' => 'Belum termasuk pajak. Fitur Premium KasirAja dan paket {paid} tidak identik.',
                    'ours' => [
                        ['Paket {paid} (outlet 1)', '{paid_price}'],
                        ['Outlet tambahan', '{outlet_addon}'],
                    ],
                    'ours_note' => 'Dalam Rupiah, tanpa biaya per karyawan atau perangkat.',
                ],
                'strengths' => [
                    ['QRIS terintegrasi', 'QR pembayaran dibuat dari aplikasi dan outlet mendapat notifikasi saat pembayaran sukses.'],
                    ['Aplikasi iOS & Android', 'Syarat & ketentuannya menyebut aplikasi KasirAja POS untuk iOS dan Android.'],
                    ['Toko online & PPOB', 'Paket Premium mencantumkan toko online serta penjualan pulsa & PPOB.'],
                    ['Fitur restoran lengkap', 'Aplikasi dapur, aplikasi waiters, reservasi, dan pesanan online dengan kurir tercantum di halaman F&B-nya.'],
                ],
                'fit_us' => [
                    'Ingin paket berbayar dengan biaya per outlet yang lebih ringan',
                    'Butuh mode apotek, laundry, atau komisi kapster',
                    'Ingin kasir bisa dibuka dari browser di perangkat apa pun',
                    'Ingin menghitung produksi dan HPP dari resep',
                ],
                'faq' => [
                    ['Apa alternatif KasirAja?', 'Kasir UMKM by MaccaPOS juga punya paket {free} gratis selamanya, dengan paket {paid} {paid_price} per outlet per bulan dan fitur khusus apotek, laundry, serta barbershop.'],
                    ['Berapa harga KasirAja?', 'Menurut halaman harga KasirAja, paket Gratis untuk satu outlet dan paket Premium Rp149.000 per outlet per bulan. Syarat & ketentuannya menyebut biaya belum termasuk pajak.'],
                    ['Apakah QRIS Kasir UMKM terhubung otomatis seperti KasirAja?', 'Belum. Kasir UMKM menampilkan gambar QRIS statis milik toko Anda dan mencatat metode pembayarannya; pengecekan dana masuk dilakukan di aplikasi bank atau e-wallet Anda.'],
                ],
            ],

            'esb' => [
                'slug' => 'alternatif-esb',
                'name' => 'ESB',
                'checked_at' => '2026-11-20',
                'checked_label' => '20 November 2026',
                'sources' => [
                    ['label' => 'halaman harga ESB', 'url' => 'https://www.esb.id/id/pricing'],
                ],
                'h1' => 'Alternatif ESB: aplikasi kasir untuk kuliner dan usaha lainnya',
                'description' => 'Mencari alternatif ESB POS? Bandingkan paket, harga, dan fitur restoran dengan Kasir UMKM by MaccaPOS, yang juga melayani toko, apotek, laundry, dan barbershop.',
                'lead' => 'ESB adalah sistem kasir dan ERP khusus bisnis kuliner, dengan paket Basic mulai Rp0 (syarat & ketentuan berlaku), Advanced mulai Rp499.000 per bulan, dan Enterprise sesuai kebutuhan. Bila usaha Anda kuliner kecil yang belum butuh ERP, atau bukan usaha kuliner, berikut perbandingan yang jujur, termasuk kapan ESB justru lebih cocok.',
                'tldr' => [
                    ['Fokus usaha', 'ESB dirancang untuk bisnis kuliner. Kasir UMKM melayani kuliner sekaligus toko, apotek, laundry, dan barbershop.'],
                    ['Harga', 'paket {paid} Kasir UMKM {paid_price} per outlet per bulan. ESB Advanced mulai Rp499.000 per bulan; Basic mulai Rp0 dengan syarat & ketentuan.'],
                    ['ESB lebih unggul', 'bila Anda restoran multi-cabang yang butuh integrasi GoFood, GrabFood, ShopeeFood, pembayaran digital terintegrasi, dan modul ERP.'],
                ],
                'reasons' => [
                    ['Usaha bukan kuliner', 'ESB menyebut solusinya untuk bisnis kuliner. Toko, apotek, laundry, atau barbershop butuh fitur yang berbeda.'],
                    ['Belum butuh ERP', 'Paket Advanced dan Enterprise ESB menyasar restoran multi-cabang dan restoran besar dengan modul ERP.'],
                    ['Ingin harga yang jelas di awal', 'Paket Basic ESB "mulai dari Rp0" dengan syarat & ketentuan berlaku, dan Enterprise sesuai kebutuhan lewat konsultasi.'],
                ],
                'compare' => [
                    ['Paket awal', 'Paket {free} gratis: transaksi tanpa batas, {free_limits}', 'Basic: mulai dari Rp0 / bulan (syarat & ketentuan berlaku)'],
                    ['Paket berikutnya', '{paid}: {paid_price} / outlet / bulan', 'Advanced: mulai dari Rp499.000 / bulan; Enterprise: sesuai kebutuhan'],
                    ['Jenis usaha', 'Kuliner, toko, apotek, laundry, barbershop', 'Bisnis kuliner (restoran & kafe)'],
                    ['Meja, split bill, pesan sendiri', 'Meja, split bill, pesan lewat QR meja (paket {paid})', 'Table management, split bill, self order tercantum di tabel fitur'],
                    ['Pesanan GoFood / GrabFood / ShopeeFood', 'Dicatat manual sebagai channel penjualan beserta komisinya', 'Integrasi GoFood, GrabFood & ShopeeFood tercantum'],
                    ['Pembayaran digital', 'Catat pembayaran QRIS (gambar QRIS statis milik toko)', 'QRIS, kartu, e-wallet, Alipay & WeChat Pay tercantum'],
                    ['Laporan & ERP', 'Laporan penjualan, stok, laba rugi, neraca', 'Hingga 100+ laporan; modul Simple & Full ERP'],
                ],
                'source_note' => 'Rincian fitur per paket ESB ada di tabel fitur di halaman harganya.',
                'cost' => [
                    'title' => 'Contoh: restoran 2 cabang',
                    'sub' => 'Restoran dengan dua cabang yang butuh pengelolaan dari satu akun.',
                    'outlets' => 2,
                    'theirs_title' => 'ESB (paket Advanced)',
                    'theirs' => [
                        ['Paket Advanced', 'Mulai Rp499.000'],
                    ],
                    'theirs_total' => 'Mulai Rp499.000 / bulan',
                    'theirs_note' => 'Halaman harga ESB menyebut "mulai dari"; hubungi ESB untuk harga sesuai jumlah cabang. Fitur Advanced dan paket {paid} tidak identik.',
                    'ours' => [
                        ['Paket {paid} (cabang 1)', '{paid_price}'],
                        ['Cabang tambahan', '{outlet_addon}'],
                    ],
                    'ours_note' => 'Dalam Rupiah, tanpa biaya per karyawan atau perangkat.',
                ],
                'strengths' => [
                    ['Khusus kuliner', 'Fitur restoran mendalam: merge & link table, move item & table, batas stok menu, customer display.'],
                    ['Integrasi ojol & pembayaran', 'Integrasi GoFood, GrabFood & ShopeeFood serta pembayaran digital termasuk Alipay & WeChat Pay.'],
                    ['ERP untuk restoran besar', 'Jurnal penjualan & persediaan otomatis, 100+ laporan, serta modul Simple & Full ERP.'],
                    ['Skala multi-cabang', 'Paket Advanced dan Enterprise dirancang untuk restoran multi-cabang dan restoran besar.'],
                ],
                'fit_us' => [
                    'Usaha bukan kuliner: toko, apotek, laundry, atau barbershop',
                    'Kuliner kecil yang belum butuh modul ERP',
                    'Ingin harga per outlet yang jelas tanpa konsultasi',
                    'Ingin mulai dengan paket gratis tanpa batas waktu',
                ],
                'faq' => [
                    ['Apa alternatif ESB untuk usaha kecil?', 'Kasir UMKM by MaccaPOS punya paket {free} gratis dan paket {paid} {paid_price} per outlet per bulan, dengan QR meja, layar dapur, serta resep & HPP untuk usaha kuliner.'],
                    ['Berapa harga ESB?', 'Menurut halaman harga ESB, paket Basic mulai dari Rp0 per bulan (syarat & ketentuan berlaku), Advanced mulai dari Rp499.000 per bulan, dan Enterprise sesuai kebutuhan.'],
                    ['Apakah Kasir UMKM bisa untuk usaha selain kuliner?', 'Bisa. Selain kuliner, Kasir UMKM punya mode untuk toko kelontong dan retail, apotek, laundry, barbershop, dan bakery.'],
                ],
            ],

            'iseller' => [
                'slug' => 'alternatif-iseller',
                'name' => 'iSeller',
                'checked_at' => '2026-11-20',
                'checked_label' => '20 November 2026',
                'sources' => [
                    ['label' => 'halaman harga iSeller', 'url' => 'https://www.isellercommerce.com/id/pricing'],
                ],
                'h1' => 'Alternatif iSeller: aplikasi kasir mulai gratis, tanpa biaya per kasir',
                'description' => 'Mencari alternatif iSeller? Bandingkan harga paket, biaya kasir tambahan, dan add-on dengan Kasir UMKM by MaccaPOS yang punya paket gratis selamanya.',
                'lead' => 'iSeller adalah platform kasir dan penjualan omnichannel dengan paket mulai Rp300.000 per bulan, ditambah biaya untuk setiap kasir tambahan dan berbagai add-on. Untuk UMKM yang baru tumbuh dan belum butuh omnichannel, berikut perbandingan yang jujur, termasuk kapan iSeller justru lebih cocok.',
                'tldr' => [
                    ['Harga', 'Kasir UMKM punya paket {free} gratis dan paket {paid} {paid_price} per outlet per bulan. Paket Starter iSeller Rp300.000 per bulan untuk satu outlet.'],
                    ['Kasir tambahan', 'Kasir UMKM tidak menagih per perangkat. Di iSeller, kasir tambahan Rp225.000 per bulan di outlet yang sama.'],
                    ['iSeller lebih unggul', 'bila Anda butuh omnichannel (Shopee, Lazada, online ordering), integrasi Accurate & Jurnal, atau paket perangkat keras.'],
                ],
                'reasons' => [
                    ['Biaya awal cukup besar', 'Paket Starter iSeller Rp300.000 per bulan, sebelum add-on dan kasir tambahan.'],
                    ['Kasir kedua menambah biaya', 'Setiap register tambahan di outlet yang sama Rp225.000 per bulan, dan Rp450.000 di outlet berbeda.'],
                    ['Fitur dasar sebagai add-on', 'Kontrol stok (sesi opname, multi user) tercantum sebagai add-on Stock Control Rp150.000 per bulan.'],
                ],
                'compare' => [
                    ['Paket gratis', 'Ada: paket {free}, transaksi tanpa batas, {free_limits}', 'Tidak tercantum paket gratis; ada tombol coba gratis'],
                    ['Harga paket', '{paid}: {paid_price} / outlet / bulan', 'Starter Rp300.000, Pro Rp570.000, Advanced Rp1.110.000 / bulan; Enterprise hubungi'],
                    ['Kasir tambahan', 'Tanpa biaya per perangkat', 'Rp225.000 / bulan (outlet sama), Rp450.000 / bulan (outlet berbeda)'],
                    ['Outlet', 'Add-on {outlet_addon} / outlet / bulan', 'Starter 1 outlet; Pro maks. 3; Advanced maks. 8'],
                    ['Stok opname', 'Termasuk paket {paid}', 'Add-on Stock Control Rp150.000 / bulan'],
                    ['Pesanan GrabFood', 'Dicatat manual sebagai channel penjualan beserta komisinya', 'Add-on GrabFood Rp75.000 / outlet / bulan'],
                    ['Marketplace & akuntansi', 'Belum ada integrasi', 'Add-on Shopee, Lazada, Accurate, Jurnal tercantum'],
                    ['Fitur usaha khusus', 'Mode apotek, laundry, barbershop, produksi & HPP', 'Tidak tercantum di halaman harga'],
                ],
                'source_note' => 'Harga paket iSeller yang dikutip adalah harga bulanan untuk "Software Only"; halaman harganya menyebut hemat 10% untuk tagihan tahunan.',
                'cost' => [
                    'title' => 'Contoh biaya bulanan: 1 outlet, 2 kasir',
                    'sub' => 'Toko dengan dua perangkat kasir yang dipakai bersamaan.',
                    'outlets' => 1,
                    'theirs_title' => 'iSeller (paket Starter)',
                    'theirs' => [
                        ['Paket Starter (termasuk 1 kasir)', 'Rp300.000'],
                        ['Register tambahan (outlet sama)', 'Rp225.000'],
                    ],
                    'theirs_total' => 'Rp525.000 / bulan',
                    'theirs_note' => 'Paket Starter iSeller sudah termasuk 10.000 produk dan 1 add-on premium gratis. Add-on lain seperti Stock Control (Rp150.000) atau GrabFood (Rp75.000) dihitung terpisah.',
                    'ours' => [
                        ['Paket {paid}', '{paid_price}'],
                        ['Kasir kedua', 'Tanpa biaya'],
                        ['Stok opname', 'Termasuk'],
                    ],
                    'ours_note' => 'Dalam Rupiah, tanpa biaya per perangkat atau per karyawan.',
                ],
                'strengths' => [
                    ['Omnichannel', 'Add-on Shopee, Lazada, dan F&B Online Ordering untuk menjual di banyak kanal.'],
                    ['Integrasi akuntansi', 'Add-on Accurate dan Jurnal untuk sinkronisasi penjualan dan inventaris.'],
                    ['Paket perangkat keras', 'Paket iSeller Basic dan Mobile menggabungkan software dengan tablet atau mPOS.'],
                    ['Katalog besar & pengguna tidak terbatas', 'Starter hingga 10.000 produk, Advanced tanpa batas; pengguna tidak terbatas.'],
                ],
                'fit_us' => [
                    'Baru mulai dan ingin kasir gratis atau biaya bulanan yang ringan',
                    'Memakai lebih dari satu perangkat kasir tanpa biaya tambahan',
                    'Butuh stok opname tanpa add-on terpisah',
                    'Butuh mode apotek, laundry, atau komisi kapster',
                ],
                'faq' => [
                    ['Apa alternatif iSeller yang lebih murah?', 'Kasir UMKM by MaccaPOS punya paket {free} gratis dan paket {paid} {paid_price} per outlet per bulan, tanpa biaya per kasir atau perangkat.'],
                    ['Berapa harga iSeller?', 'Menurut halaman harga iSeller, paket Starter Rp300.000, Pro Rp570.000, dan Advanced Rp1.110.000 per bulan (Software Only), dengan register tambahan Rp225.000 atau Rp450.000 per bulan dan add-on terpisah.'],
                    ['Apakah Kasir UMKM terhubung ke Shopee atau Accurate seperti iSeller?', 'Belum. Kasir UMKM berfokus pada kasir, stok, dan laporan untuk UMKM. Bila integrasi marketplace atau akuntansi penting, iSeller menyediakannya sebagai add-on.'],
                ],
            ],

            'jurnal-pos' => [
                'slug' => 'alternatif-jurnal-pos',
                'name' => 'Jurnal POS',
                'checked_at' => '2026-11-20',
                'checked_label' => '20 November 2026',
                'sources' => [
                    ['label' => 'halaman harga Mekari POS', 'url' => 'https://pos.mekari.com/harga'],
                    ['label' => 'halaman Mekari POS di Jurnal', 'url' => 'https://www.jurnal.id/id/fitur/mekari-pos/'],
                ],
                'h1' => 'Alternatif Jurnal POS (Mekari POS): aplikasi kasir mulai gratis untuk UMKM',
                'description' => 'Mencari alternatif Jurnal POS atau Mekari POS? Bandingkan harga dan fitur dengan Kasir UMKM by MaccaPOS: ada paket gratis dan laporan keuangan bawaan.',
                'lead' => 'Jurnal POS kini bernama Mekari POS: aplikasi kasir untuk F&B dan retail yang tersambung otomatis ke software akuntansi Mekari Jurnal, dengan paket mulai Rp299.000 per bulan (belum PPN). Bila Anda belum memakai Jurnal atau ingin mulai dengan biaya lebih ringan, berikut perbandingan yang jujur, termasuk kapan Mekari POS justru lebih cocok.',
                'tldr' => [
                    ['Harga', 'Kasir UMKM punya paket {free} gratis dan paket {paid} {paid_price} per outlet per bulan. Paket Essentials Mekari POS Rp299.000 per bulan, atau Rp209.300 dengan promo untuk pengguna baru berlangganan 1 tahun.'],
                    ['Akuntansi', 'Kasir UMKM punya laporan laba rugi, neraca, dan jurnal sendiri. Mekari POS mencatat penjualan langsung ke Mekari Jurnal.'],
                    ['Mekari POS lebih unggul', 'bila Anda sudah memakai Mekari Jurnal, butuh pesanan multi-channel terpusat, atau demo dan pelatihan langsung.'],
                ],
                'reasons' => [
                    ['Belum memakai Mekari Jurnal', 'Kekuatan utama Mekari POS adalah sinkronisasi ke Mekari Jurnal. Bila belum memakai Jurnal, keuntungan ini belum terasa.'],
                    ['Biaya bulanan cukup besar', 'Paket Essentials Mekari POS Rp299.000 per bulan, belum termasuk PPN.'],
                    ['Usaha selain F&B dan retail', 'Mekari POS menyediakan versi F&B dan retail. Apotek, laundry, dan barbershop butuh fitur yang berbeda.'],
                ],
                'compare' => [
                    ['Paket gratis', 'Ada: paket {free}, transaksi tanpa batas, {free_limits}', 'Tidak tercantum paket gratis; ada uji coba dan demo gratis'],
                    ['Harga paket', '{paid}: {paid_price} / outlet / bulan', 'Essentials Rp299.000, Plus Rp399.000 / bulan (belum PPN); POS 360 lewat konsultasi'],
                    ['Promo', '—', 'Diskon hingga 30% untuk pengguna baru dengan langganan 1 tahun'],
                    ['Multi outlet', 'Add-on {outlet_addon} / outlet / bulan', 'Paket Plus untuk lebih dari 1 outlet'],
                    ['Akuntansi', 'Laporan laba rugi, neraca, jurnal bawaan (paket {paid})', 'Penjualan, pembayaran, pajak, dan diskon tercatat otomatis ke Mekari Jurnal'],
                    ['Jenis usaha', 'Kuliner, toko, apotek, laundry, barbershop', 'Versi F&B dan versi retail'],
                    ['Fitur khusus', 'Mode apotek, laundry, barbershop, produksi & HPP', 'Tidak tercantum di halaman harga'],
                ],
                'source_note' => 'Halaman harga Mekari POS menyebut harga belum termasuk PPN; harga promo khusus pengguna baru dengan langganan 1 tahun.',
                'cost' => [
                    'title' => 'Contoh biaya bulanan: 1 outlet',
                    'sub' => 'Kafe atau toko dengan satu outlet yang memakai paket berbayar terendah.',
                    'outlets' => 1,
                    'theirs_title' => 'Mekari POS (paket Essentials)',
                    'theirs' => [
                        ['Paket Essentials', 'Rp299.000'],
                        ['Atau harga promo (langganan 1 tahun)', 'Rp209.300'],
                    ],
                    'theirs_total' => 'Rp209.300–Rp299.000 / bulan',
                    'theirs_note' => 'Belum termasuk PPN. Paket Essentials sudah tersinkron ke Mekari Jurnal; fiturnya tidak identik dengan paket {paid}.',
                    'ours' => [
                        ['Paket {paid}', '{paid_price}'],
                        ['Atau paket {free}', 'Gratis'],
                    ],
                    'ours_note' => 'Dalam Rupiah, tanpa biaya per karyawan atau perangkat.',
                ],
                'strengths' => [
                    ['Tersambung ke Mekari Jurnal', 'Penjualan, pembayaran, pajak, dan diskon dari POS tercatat otomatis ke sistem akuntansi Mekari Jurnal.'],
                    ['Pesanan multi-channel terpusat', 'Pesanan dari berbagai channel online dipantau dan diproses selaras dengan operasional outlet.'],
                    ['Demo & pelatihan', 'Demo gratis online atau on-site, serta panduan setup dan pelatihan software & hardware.'],
                    ['Skala besar', 'Paket POS 360 untuk full-service restaurant dan retail besar, hingga 50 user.'],
                ],
                'fit_us' => [
                    'Belum memakai Mekari Jurnal dan ingin laporan keuangan langsung di aplikasi kasir',
                    'Ingin mulai dengan paket gratis atau biaya bulanan yang ringan',
                    'Butuh mode apotek, laundry, atau komisi kapster',
                    'Ingin bayar bulanan dengan harga tetap, tanpa syarat promo',
                ],
                'faq' => [
                    ['Apakah Jurnal POS sama dengan Mekari POS?', 'Ya. Situs Jurnal kini menautkan fitur POS-nya ke Mekari POS, aplikasi kasir yang tersambung ke software akuntansi Mekari Jurnal.'],
                    ['Berapa harga Mekari POS?', 'Menurut halaman harga Mekari POS, paket Essentials Rp299.000 dan Plus Rp399.000 per bulan, belum termasuk PPN, dengan diskon hingga 30% untuk pengguna baru berlangganan 1 tahun. Paket POS 360 lewat konsultasi.'],
                    ['Apa alternatif Jurnal POS untuk UMKM?', 'Kasir UMKM by MaccaPOS punya paket {free} gratis dan paket {paid} {paid_price} per outlet per bulan, lengkap dengan laporan laba rugi, neraca, dan jurnal di dalam aplikasi.'],
                ],
            ],

            'kasironline' => [
                'slug' => 'alternatif-kasironline',
                'name' => 'KasirOnline',
                'checked_at' => '2026-11-20',
                'checked_label' => '20 November 2026',
                'sources' => [
                    ['label' => 'halaman KasirOnline', 'url' => 'https://kasironline.com/'],
                ],
                'h1' => 'Alternatif KasirOnline: fitur restoran dan cabang tanpa naik ke paket termahal',
                'description' => 'Mencari alternatif KasirOnline? Bandingkan paket gratis, harga Pro dan Business, layar dapur, serta biaya cabang dengan Kasir UMKM by MaccaPOS.',
                'lead' => 'KasirOnline adalah aplikasi kasir dengan paket Gratis, Pro Rp99.000, dan Business Rp249.000 per toko per bulan. Harga paket berbayar pertamanya sama dengan Kasir UMKM, jadi perbedaannya ada di fitur yang termasuk di tiap paket. Berikut perbandingan yang jujur, termasuk kapan KasirOnline justru lebih cocok.',
                'tldr' => [
                    ['Harga setara', 'paket {paid} Kasir UMKM {paid_price} per outlet per bulan; paket Pro KasirOnline Rp99.000 per toko per bulan.'],
                    ['Fitur di paket yang sama', 'layar dapur, QR meja, resep & HPP, dan akun karyawan termasuk paket {paid}. Di KasirOnline, layar dapur, cabang, absensi, dan hak akses kustom ada di paket Business Rp249.000.'],
                    ['KasirOnline lebih unggul', 'bila Anda butuh QRIS terintegrasi dengan status lunas otomatis, paket gratis untuk 2 pengguna, atau struk dan laporan lewat WhatsApp.'],
                ],
                'reasons' => [
                    ['Cabang butuh paket Business', 'Banyak cabang dan transfer stok di KasirOnline ada di paket Business Rp249.000 per toko per bulan.'],
                    ['Layar dapur di paket teratas', 'Layar dapur dan layar pelanggan KasirOnline tercantum di paket Business.'],
                    ['Butuh fitur khas jenis usaha', 'Seperti mode apotek dengan batch dan kedaluwarsa, order laundry, atau komisi kapster.'],
                ],
                'compare' => [
                    ['Paket gratis', 'Paket {free}: transaksi tanpa batas, {free_limits}', 'Gratis: 1 toko, 2 pengguna, laporan 31 hari terakhir'],
                    ['Paket berbayar pertama', '{paid}: {paid_price} / outlet / bulan', 'Pro: Rp99.000 / toko / bulan (belum pajak)'],
                    ['Cabang', 'Add-on {outlet_addon} / outlet / bulan', 'Paket Business: Rp249.000 / toko / bulan'],
                    ['Layar dapur', 'Termasuk paket {paid}', 'Paket Business'],
                    ['Hak akses karyawan', 'Peran kasir, gudang, manajer, admin (paket {paid})', 'Hak akses kustom di paket Business; kasir masuk dengan PIN'],
                    ['Pembayaran QRIS', 'Catat pembayaran QRIS (gambar QRIS statis milik toko)', 'QRIS dengan status lunas otomatis di layar kasir (biaya mengikuti penyedia pembayaran)'],
                    ['Mode offline', 'Penjualan tetap tercatat saat offline dan tersinkron otomatis', 'Tunai, struk, dan tutup shift tetap jalan saat offline; QRIS butuh internet'],
                    ['Fitur usaha khusus', 'Mode apotek, laundry, barbershop, produksi & HPP, QR meja', 'Tidak tercantum di halaman harga'],
                ],
                'source_note' => 'Harga KasirOnline belum termasuk pajak; paket berbayar bisa dicoba 14 hari.',
                'cost' => [
                    'title' => 'Contoh biaya bulanan: kafe 2 cabang dengan layar dapur',
                    'sub' => 'Kafe dengan dua cabang yang memakai layar dapur di setiap cabang.',
                    'outlets' => 2,
                    'theirs_title' => 'KasirOnline (paket Business)',
                    'theirs' => [
                        ['Business cabang 1', 'Rp249.000'],
                        ['Business cabang 2', 'Rp249.000'],
                    ],
                    'theirs_total' => 'Rp498.000 / bulan',
                    'theirs_note' => 'Belum termasuk pajak. Untuk satu toko tanpa layar dapur, paket Pro KasirOnline (Rp99.000) setara dengan paket {paid}.',
                    'ours' => [
                        ['Paket {paid} (cabang 1)', '{paid_price}'],
                        ['Cabang tambahan', '{outlet_addon}'],
                        ['Layar dapur', 'Termasuk'],
                    ],
                    'ours_note' => 'Dalam Rupiah, tanpa biaya per karyawan atau perangkat.',
                ],
                'strengths' => [
                    ['QRIS terintegrasi', 'Status lunas pembayaran QRIS muncul sendiri di layar kasir, tanpa mencocokkan mutasi.'],
                    ['Paket gratis untuk 2 pengguna', 'Paket Gratis mencantumkan 1 toko dan 2 pengguna, tanpa batas waktu.'],
                    ['Struk & laporan lewat WhatsApp', 'Struk bisa dikirim lewat WhatsApp dan email; paket Pro mengirim laporan terjadwal ke email dan WhatsApp.'],
                    ['API', 'Paket Business mencantumkan API dan dukungan prioritas.'],
                ],
                'fit_us' => [
                    'Restoran atau kafe yang butuh layar dapur dan QR meja tanpa paket termahal',
                    'Punya beberapa cabang dan ingin biaya cabang yang lebih ringan',
                    'Butuh mode apotek, laundry, atau komisi kapster',
                    'Ingin menghitung HPP dan produksi dari resep',
                ],
                'faq' => [
                    ['Apa alternatif KasirOnline?', 'Kasir UMKM by MaccaPOS punya paket {free} gratis dan paket {paid} {paid_price} per outlet per bulan yang sudah termasuk layar dapur, QR meja, serta resep & HPP.'],
                    ['Berapa harga KasirOnline?', 'Menurut situs KasirOnline, paket Gratis Rp0, Pro Rp99.000, dan Business Rp249.000 per toko per bulan, belum termasuk pajak.'],
                    ['Mana yang lebih murah untuk banyak cabang?', 'Di Kasir UMKM, cabang tambahan {outlet_addon} per bulan di atas paket {paid}. Di KasirOnline, banyak cabang ada di paket Business Rp249.000 per toko per bulan.'],
                ],
            ],
        ];
    }
}
