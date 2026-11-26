<?php

namespace App\Support;

/**
 * Konten halaman SEO lokal "Aplikasi Kasir di [kota]".
 *
 * Setiap kota ditulis manual (jenis usaha khas, tantangan, alur, FAQ berbeda)
 * agar tidak menjadi halaman tipis yang hanya mengganti nama kota. Tes
 * tests/Feature/KotaPageTest.php menolak kalimat yang sama dipakai di dua kota.
 *
 * ATURAN:
 * - Setiap klaim fitur harus benar-benar ada di aplikasi. Fitur yang hanya ada
 *   di paket berbayar diberi 'paid' => true (tampil label paket GrowUp).
 * - Jangan mengarang hal lokal: tidak ada kantor cabang, jumlah pelanggan di
 *   kota itu, testimoni, atau klaim peringkat. Satu-satunya alamat usaha:
 *   Jl. Benteng Somba Opu, Tanjung Permai No 30 Gowa, Sulawesi Selatan.
 * - Jangan menulis harga mati; harga diambil dari database lewat LandingPricing.
 * - 'jenis' pada daftar usaha menunjuk slug di App\Support\JenisUsaha.
 *
 * Halaman ditampilkan oleh KotaController + resources/views/kota/*.blade.php,
 * didaftarkan di routes/web.php dan sitemap.xml lewat slugs().
 */
final class KotaLayanan
{
    /** Alamat halaman indeks semua kota. */
    public const INDEX_PATH = 'aplikasi-kasir/kota';

    /** Jam layanan tim (WITA), sama dengan yang tertulis di footer. */
    private const JAM_BUKA_WITA = 8;

    private const JAM_TUTUP_WITA = 17;

    /** Selisih jam tiap zona waktu terhadap WITA. */
    private const SELISIH_ZONA = ['WIB' => -1, 'WITA' => 0, 'WIT' => 1];

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

    /**
     * Kota dikelompokkan per provinsi, untuk halaman indeks. Provinsi yang baru
     * punya satu kota digabung ke kelompok "Provinsi lain" supaya indeks tidak
     * berisi deretan bagian yang hanya memuat satu kartu.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function byProvince(): array
    {
        $groups = [];
        foreach (self::all() as $kota) {
            $groups[$kota['province']][] = $kota;
        }

        $lain = [];
        foreach ($groups as $province => $kotaList) {
            if (count($kotaList) < 2) {
                $lain = array_merge($lain, $kotaList);
                unset($groups[$province]);
            }
        }
        if ($lain !== []) {
            $groups[$groups === [] ? 'Semua kota' : 'Provinsi lain'] = $lain;
        }

        return $groups;
    }

    /**
     * Kota lain untuk tautan "kota terdekat": yang ditulis di 'nearby' lebih dulu,
     * lalu sisanya, supaya setiap kota tetap terhubung ke semua kota lain.
     *
     * @return list<array<string, mixed>>
     */
    public static function others(string $slug): array
    {
        $pages = self::pages();
        $current = $pages[$slug] ?? null;
        if ($current === null) {
            return [];
        }

        $ordered = [];
        foreach ($current['nearby'] as $key) {
            $nearSlug = 'aplikasi-kasir-'.$key;
            if (isset($pages[$nearSlug])) {
                $ordered[$nearSlug] = $pages[$nearSlug];
            }
        }
        foreach ($pages as $otherSlug => $page) {
            if ($otherSlug !== $slug) {
                $ordered[$otherSlug] ??= $page;
            }
        }

        return array_values($ordered);
    }

    /**
     * Jam layanan tim dalam zona waktu kota itu, mis. "07.00 – 16.00 WIB".
     */
    public static function jamLayanan(string $zone): string
    {
        $selisih = self::SELISIH_ZONA[$zone] ?? 0;

        return sprintf('%02d.00 – %02d.00 %s', self::JAM_BUKA_WITA + $selisih, self::JAM_TUTUP_WITA + $selisih, $zone);
    }

    /** @return array<string, array<string, mixed>> */
    private static function pages(): array
    {
        $pages = [
            'makassar' => [
                'name' => 'Makassar',
                'province' => 'Sulawesi Selatan',
                'zone' => 'WITA',
                'tagline' => 'Warkop, kuliner khas, dan toko grosir',
                'title' => 'Aplikasi Kasir Makassar: Warkop, Kuliner & Toko | Kasir UMKM',
                'description' => 'Aplikasi kasir untuk usaha di Makassar: warkop, rumah makan coto dan konro, toko grosir, dan toko oleh-oleh. Jalan di HP, tetap mencatat saat offline.',
                'h1' => 'Aplikasi Kasir untuk Usaha di Makassar',
                'lead' => 'Dari warkop yang ramai sampai larut malam, rumah makan coto di jam makan siang, sampai toko grosir di pusat kota. Kasir UMKM mencatat penjualan dari HP, dan dikembangkan oleh tim yang berbasis di Gowa, tetangga Makassar.',
                'usaha_sub' => 'Empat jenis usaha yang paling sering kami temui di Makassar, dan apa yang membuat kasirnya berbeda.',
                'usaha' => [
                    ['title' => 'Warkop & kedai kopi', 'desc' => 'Nongkrong di warkop sudah jadi kebiasaan orang Makassar. Satu meja bisa memesan berkali-kali dalam semalam, jadi pesanan perlu disimpan dulu dan ditagih di akhir.', 'jenis' => 'aplikasi-kasir-cafe'],
                    ['title' => 'Rumah makan coto, konro & pallubasa', 'desc' => 'Jam makan siang datang serentak. Pesanan per meja, tambahan ketupat atau burasa, dan tagihan rombongan perlu tercatat cepat tanpa kertas yang terselip.', 'jenis' => 'aplikasi-kasir-restoran'],
                    ['title' => 'Toko grosir & eceran', 'desc' => 'Pedagang di pusat grosir melayani pembeli eceran dan pembeli dari daerah dalam satu lapak. Harga eceran dan harga grosir harus dipisah dengan jelas.', 'jenis' => 'aplikasi-kasir-toko-retail'],
                    ['title' => 'Toko oleh-oleh & kue', 'desc' => 'Toko oleh-oleh dan toko kue ramai saat musim libur dan menjelang hari raya. Pesanan dalam jumlah besar sering dipesan dulu dan diambil kemudian.', 'jenis' => 'aplikasi-kasir-toko-roti'],
                ],
                'tantangan_title' => 'Tantangan kasir yang sering muncul di Makassar',
                'tantangan_sub' => 'Tiga situasi yang akrab bagi pemilik usaha di kota ini, dan cara aplikasi menanganinya.',
                'tantangan' => [
                    [
                        'problem' => 'Tagihan meja warkop tercampur',
                        'detail' => 'Pelanggan datang berombongan, pesan kopi, lalu menambah gorengan dan mi satu per satu. Di akhir malam kasir menebak siapa memesan apa.',
                        'solution' => 'simpan pesanan per meja sebagai open bill, tambahkan kapan saja, lalu bagi tagihannya dengan split bill saat mereka pulang.',
                    ],
                    [
                        'problem' => 'Dapur kewalahan di jam makan siang',
                        'detail' => 'Pesanan coto dan konro diteriakkan dari depan. Saat ramai, ada meja yang terlewat dan ada porsi yang dimasak dua kali.',
                        'solution' => 'pesanan dari kasir langsung muncul di layar antrean dapur, dan koki menandai menu yang sudah selesai.',
                        'paid' => true,
                    ],
                    [
                        'problem' => 'Harga grosir ditentukan dari ingatan',
                        'detail' => 'Pembeli dari luar kota mengambil lusinan, pembeli lokal membeli satuan. Karyawan baru sering salah memberi harga.',
                        'solution' => 'setiap barang menyimpan harga eceran dan harga grosir. Harga grosir otomatis dipakai untuk pelanggan bertipe grosir.',
                    ],
                ],
                'flow_title' => 'Satu malam di warkop Makassar',
                'flow_sub' => 'Contoh alur dari meja pertama terisi sampai tutup.',
                'flow' => [
                    ['title' => 'Sore: meja pertama terisi', 'desc' => 'Empat orang duduk di meja 3 dan memesan kopi susu. Kasir menyimpannya sebagai pesanan terbuka atas meja itu.'],
                    ['title' => 'Malam: pesanan bertambah', 'desc' => 'Mereka menambah mi goreng dan pisang goreng. Tambahan masuk ke tagihan yang sama, tanpa catatan kertas.'],
                    ['title' => 'Pulang: bayar masing-masing', 'desc' => 'Tagihan dibagi empat. Dua orang membayar tunai, dua lainnya memindai QRIS warkop.'],
                    ['title' => 'Tutup: lihat hasil hari ini', 'desc' => 'Laporan penjualan menunjukkan omzet malam itu dan menu yang paling banyak dipesan.'],
                ],
                'bantuan' => 'Tim Kasir UMKM berbasis di Gowa, tepat di sebelah Makassar, jadi jam layanan kami sama dengan jam buka toko Anda. Pertanyaan dijawab lewat WhatsApp, dan panduan tertulis bisa dibuka kapan saja.',
                'faq' => [
                    ['q' => 'Apakah Kasir UMKM punya kantor di Makassar?', 'a' => 'Alamat kami ada di Gowa, tepat di sebelah Makassar: Jl. Benteng Somba Opu, Tanjung Permai No 30. Aplikasinya dipasang sendiri dari HP tanpa perlu teknisi datang, dan bantuan diberikan lewat WhatsApp.'],
                    ['q' => 'Apakah cocok untuk warkop yang buka sampai tengah malam?', 'a' => 'Cocok. Pesanan disimpan per meja, bisa ditambah kapan saja, dan dibayar di akhir. Untuk warkop yang berganti penjaga, fitur shift kasir di paket GrowUp mencatat setoran setiap shift.'],
                    ['q' => 'Bisakah menerima pembayaran QRIS dan transfer bank?', 'a' => 'Bisa. Kasir mencatat pembayaran tunai, QRIS dengan gambar QRIS toko Anda, e-wallet, dan transfer. Satu transaksi juga bisa dibayar dengan beberapa metode sekaligus.'],
                    ['q' => 'Saya punya cabang di Makassar dan Gowa, bisa dipantau sekaligus?', 'a' => 'Bisa. Outlet tambahan tersedia sebagai add-on berbayar. Stok tiap cabang tercatat terpisah dan bisa dipindahkan antar outlet.'],
                ],
                'nearby' => ['gowa', 'maros', 'parepare'],
                'article_keywords' => ['kopi', 'cafe', 'rumah makan', 'grosir', 'HPP'],
            ],

            'gowa' => [
                'name' => 'Gowa',
                'province' => 'Sulawesi Selatan',
                'zone' => 'WITA',
                'tagline' => 'Sungguminasa, Samata, sampai Malino',
                'title' => 'Aplikasi Kasir Gowa & Sungguminasa untuk UMKM | Kasir UMKM',
                'description' => 'Aplikasi kasir untuk usaha di Gowa: warung di Sungguminasa, kedai sekitar kampus Samata, dan toko oleh-oleh Malino. Dibuat oleh tim yang berbasis di Gowa.',
                'h1' => 'Aplikasi Kasir untuk Usaha di Gowa',
                'lead' => 'Kasir UMKM dikembangkan oleh tim yang berbasis di Gowa. Kami membuatnya untuk warung, toko, dan kedai seperti yang ada di Sungguminasa, Samata, sampai Malino: cukup pakai HP, mudah dipelajari, dan tetap mencatat saat sinyal hilang.',
                'usaha_sub' => 'Gowa membentang dari pinggir Makassar sampai dataran tinggi, dan jenis usahanya ikut berbeda di tiap kawasan.',
                'usaha' => [
                    ['title' => 'Warung & toko kelontong', 'desc' => 'Di permukiman Sungguminasa dan Pallangga, warung sembako melayani tetangga yang sering mengambil barang dulu dan membayar saat gajian.', 'jenis' => 'aplikasi-kasir-toko-kelontong'],
                    ['title' => 'Kedai sekitar kampus', 'desc' => 'Kawasan kampus di Samata dan Bontomarannu dipenuhi kedai kopi, warung makan, dan booth minuman dengan pembeli mahasiswa yang ramai di jam istirahat kuliah.', 'jenis' => 'aplikasi-kasir-cafe'],
                    ['title' => 'Laundry kiloan', 'desc' => 'Kos-kosan mahasiswa membuat laundry kiloan tumbuh di sekitar kampus. Cucian datang banyak di akhir pekan dan harus dikembalikan tepat waktu.', 'jenis' => 'aplikasi-kasir-laundry'],
                    ['title' => 'Toko oleh-oleh & kedai di Malino', 'desc' => 'Malino ramai di akhir pekan dan musim libur. Toko oleh-oleh dan kedai di jalur wisata menerima banyak pembeli dalam waktu singkat.', 'jenis' => 'aplikasi-kasir-toko-retail'],
                ],
                'tantangan_title' => 'Tantangan kasir yang sering muncul di Gowa',
                'tantangan_sub' => 'Dari warung di kota kabupaten sampai kedai di jalur wisata, masalahnya berbeda-beda.',
                'tantangan' => [
                    [
                        'problem' => 'Utang tetangga menumpuk di buku',
                        'detail' => 'Catatan bon ditulis di buku yang sama dengan catatan belanja. Saat ditagih, jumlahnya tidak cocok dengan ingatan pelanggan.',
                        'solution' => 'utang dicatat per pelanggan beserta cicilannya, dan utang yang jatuh tempo terlihat tanpa membuka buku.',
                        'paid' => true,
                    ],
                    [
                        'problem' => 'Sinyal hilang di jalur wisata',
                        'detail' => 'Di dataran tinggi, sinyal bisa naik turun justru saat pengunjung sedang ramai, dan aplikasi yang harus selalu online jadi tidak bisa dipakai.',
                        'solution' => 'penjualan tetap tercatat saat offline, lalu tersinkron sendiri ketika HP kembali mendapat internet.',
                    ],
                    [
                        'problem' => 'Lupa cucian mana yang sudah lunas',
                        'detail' => 'Nota laundry kertas mudah hilang. Karyawan harus mengingat cucian siapa yang sudah lunas dan mana yang baru bayar uang muka.',
                        'solution' => 'setiap order laundry punya status cucian, uang muka, dan sisa tagihan, lalu dilunasi saat cucian diambil.',
                    ],
                ],
                'flow_title' => 'Satu hari di warung dekat kampus Samata',
                'flow_sub' => 'Contoh alur sederhana untuk warung yang dijaga pemiliknya sendiri.',
                'flow' => [
                    ['title' => 'Pagi: barang baru datang', 'desc' => 'Pemilik menambah stok air mineral dan mi instan yang baru dibeli dari agen.'],
                    ['title' => 'Siang: jam istirahat kuliah', 'desc' => 'Pembeli datang bergantian. Barcode dipindai dengan kamera HP dan pembayaran dicatat tunai atau QRIS.'],
                    ['title' => 'Sore: langganan ambil dulu', 'desc' => 'Seorang mahasiswa langganan membeli kebutuhan kos dan membayar minggu depan. Transaksinya dicatat sebagai utang atas namanya.'],
                    ['title' => 'Malam: hitung hasil', 'desc' => 'Laporan menunjukkan omzet hari itu dan barang yang paling laku, tanpa menghitung nota satu per satu.'],
                ],
                'bantuan' => 'Alamat kami di Jl. Benteng Somba Opu, Tanjung Permai No 30 Gowa. Bantuan diberikan lewat WhatsApp pada jam kerja, dan aplikasinya dipasang sendiri dari HP tanpa perlu menunggu teknisi.',
                'faq' => [
                    ['q' => 'Di mana alamat Kasir UMKM di Gowa?', 'a' => 'Di Jl. Benteng Somba Opu, Tanjung Permai No 30 Gowa, Sulawesi Selatan. Pertanyaan bisa kami jawab lewat WhatsApp, jadi Anda tidak perlu datang untuk mulai memakai aplikasinya.'],
                    ['q' => 'Apakah bisa dipakai di Malino yang sinyalnya tidak stabil?', 'a' => 'Bisa. Kasir tetap mencatat penjualan saat offline. Begitu HP kembali terhubung ke internet, datanya tersinkron otomatis.'],
                    ['q' => 'Warung saya kecil, apakah perlu beli alat kasir?', 'a' => 'Tidak. Cukup HP Android atau browser di HP, tablet, atau laptop. Kamera HP bisa dipakai untuk scan barcode, dan printer struk Bluetooth bisa ditambahkan belakangan bila diperlukan.'],
                    ['q' => 'Apakah pelanggan langganan bisa bayar belakangan?', 'a' => 'Bisa, dengan fitur catat utang di paket GrowUp. Setiap transaksi bayar belakangan tersimpan atas nama pelanggan, lengkap dengan cicilan dan tanggal jatuh temponya.'],
                ],
                'nearby' => ['makassar', 'maros', 'parepare'],
                'article_keywords' => ['warung', 'kelontong', 'utang', 'laundry', 'stok'],
            ],

            'maros' => [
                'name' => 'Maros',
                'province' => 'Sulawesi Selatan',
                'zone' => 'WITA',
                'tagline' => 'Roti Maros, jalan poros, dan wisata Bantimurung',
                'title' => 'Aplikasi Kasir Maros: Toko Roti, Warung & Kedai | Kasir UMKM',
                'description' => 'Aplikasi kasir untuk usaha di Maros: toko roti Maros, warung makan di jalan poros, kedai di kawasan wisata Bantimurung, dan toko kelontong. Mulai gratis.',
                'h1' => 'Aplikasi Kasir untuk Usaha di Maros',
                'lead' => 'Maros dilewati siapa saja yang menuju bandara atau ke utara Sulawesi Selatan. Toko roti, warung makan, dan kedai di jalur itu melayani pembeli yang singgah sebentar, jadi kasirnya harus cepat dan stoknya harus jelas.',
                'usaha_sub' => 'Usaha di Maros banyak bergantung pada orang yang lewat dan orang yang berwisata.',
                'usaha' => [
                    ['title' => 'Toko roti Maros', 'desc' => 'Roti Maros dibeli sebagai oleh-oleh, sering berkotak-kotak sekaligus. Produksi harian harus pas: kurang berarti pembeli kecewa, berlebih berarti roti tersisa.', 'jenis' => 'aplikasi-kasir-toko-roti'],
                    ['title' => 'Warung makan di jalan poros', 'desc' => 'Sopir dan penumpang singgah makan lalu melanjutkan perjalanan. Pesanan harus cepat sampai ke dapur dan tagihan harus siap begitu mereka selesai.', 'jenis' => 'aplikasi-kasir-restoran'],
                    ['title' => 'Kedai di kawasan wisata', 'desc' => 'Bantimurung dan Rammang-Rammang ramai pada akhir pekan dan musim libur. Penjualan melonjak dalam beberapa jam, lalu sepi lagi di hari kerja.', 'jenis' => 'aplikasi-kasir-cafe'],
                    ['title' => 'Toko kelontong & sembako', 'desc' => 'Toko sembako di Turikale dan sekitarnya menjual eceran untuk rumah tangga sekaligus memasok warung-warung kecil di kampung.', 'jenis' => 'aplikasi-kasir-toko-kelontong'],
                ],
                'tantangan_title' => 'Tantangan kasir yang sering muncul di Maros',
                'tantangan_sub' => 'Pembeli yang singgah tidak mau menunggu, dan pesanan oleh-oleh sering datang dalam jumlah besar.',
                'tantangan' => [
                    [
                        'problem' => 'Antre panjang saat rombongan singgah',
                        'detail' => 'Satu bus berhenti dan puluhan orang membeli bersamaan. Kasir menulis nota tangan dan menghitung kembalian dengan kalkulator.',
                        'solution' => 'pilih barang di layar kasir, total dan kembalian terhitung otomatis, dan struk tercetak dari printer Bluetooth.',
                    ],
                    [
                        'problem' => 'Modal per roti tidak pernah dihitung',
                        'detail' => 'Harga tepung, mentega, dan gula berubah, tetapi harga jual per kotak tetap sama selama berbulan-bulan.',
                        'solution' => 'masukkan takaran bahan per resep, lalu aplikasi menghitung modal per potong atau per loyang.',
                        'paid' => true,
                    ],
                    [
                        'problem' => 'Pesanan besar batal diambil',
                        'detail' => 'Pesanan oleh-oleh dalam jumlah besar dicatat di kertas tanpa uang muka. Saat pemesan tidak datang, roti sudah terlanjur dibuat.',
                        'solution' => 'catat pesanan sebagai pre-order dengan uang muka dan tanggal ambil, lalu lunasi saat diambil.',
                        'paid' => true,
                    ],
                ],
                'flow_title' => 'Satu hari di toko roti Maros',
                'flow_sub' => 'Contoh dari adonan pagi sampai pesanan rombongan.',
                'flow' => [
                    ['title' => 'Subuh: produksi', 'desc' => 'Roti hari ini dicatat sebagai produksi. Stok roti bertambah dan stok tepung, gula, serta mentega berkurang sesuai resep.'],
                    ['title' => 'Pagi: pembeli pertama', 'desc' => 'Pembeli yang menuju bandara mengambil enam kotak. Kasir memilih produk, menerima pembayaran, dan mencetak struk.'],
                    ['title' => 'Siang: pesanan rombongan', 'desc' => 'Sebuah rombongan memesan 40 kotak untuk diambil besok pagi dan membayar uang muka. Pesanan tersimpan sebagai pre-order.'],
                    ['title' => 'Sore: cek sisa', 'desc' => 'Stok roti yang tersisa terlihat di aplikasi, jadi jumlah adonan besok bisa disesuaikan.'],
                ],
                'bantuan' => 'Maros berada di zona waktu yang sama dengan tim kami di Gowa. Pertanyaan soal produksi, resep, atau pemasangan printer dijawab lewat WhatsApp pada jam kerja.',
                'faq' => [
                    ['q' => 'Apakah bisa dipakai toko roti Maros yang produksi setiap hari?', 'a' => 'Bisa. Fitur produksi di paket GrowUp mencatat roti yang dibuat setiap hari: stok roti bertambah, stok bahan berkurang, dan biaya produksinya tercatat.'],
                    ['q' => 'Apakah harus datang ke kantor untuk memasang aplikasinya?', 'a' => 'Tidak. Anda mendaftar dan memasang sendiri dari HP. Tim kami berbasis di Gowa dan membantu lewat WhatsApp bila ada yang membingungkan.'],
                    ['q' => 'Bisakah mencatat pesanan oleh-oleh yang dibayar DP dulu?', 'a' => 'Bisa, dengan fitur pre-order di paket GrowUp. Catat nama pemesan, uang muka, dan tanggal ambil, lalu lunasi sisanya saat roti diambil.'],
                    ['q' => 'Usaha saya ramai hanya di akhir pekan, apakah harus langsung berlangganan?', 'a' => 'Tidak. Paket Starter gratis selamanya dengan transaksi tanpa batas, dengan batas jumlah produk dan pelanggan. Anda bisa pindah ke paket GrowUp nanti saat membutuhkan fiturnya.'],
                ],
                'nearby' => ['makassar', 'gowa', 'parepare'],
                'article_keywords' => ['roti', 'kue', 'HPP', 'produksi', 'warung makan'],
            ],

            'parepare' => [
                'name' => 'Parepare',
                'province' => 'Sulawesi Selatan',
                'zone' => 'WITA',
                'tagline' => 'Kota pelabuhan dan niaga di pesisir barat',
                'title' => 'Aplikasi Kasir Parepare: Toko, Grosir & Kuliner | Kasir UMKM',
                'description' => 'Aplikasi kasir untuk usaha di Parepare: toko dan grosir kota pelabuhan, toko roti mantao, rumah makan, dan apotek. Stok rapi, laporan dari HP. Mulai gratis.',
                'h1' => 'Aplikasi Kasir untuk Usaha di Parepare',
                'lead' => 'Parepare adalah kota pelabuhan dan kota niaga. Banyak tokonya memasok pembeli dari kabupaten sekitar seperti Pinrang, Sidrap, dan Barru, jadi stok, harga grosir, dan catatan pembelian harus rapi.',
                'usaha_sub' => 'Sebagai kota transit, Parepare hidup dari perdagangan dan dari orang yang singgah.',
                'usaha' => [
                    ['title' => 'Toko grosir & agen', 'desc' => 'Agen sembako dan toko grosir melayani pedagang dari kabupaten tetangga yang berbelanja dalam jumlah besar, sering per dus atau per karung.', 'jenis' => 'aplikasi-kasir-toko-retail'],
                    ['title' => 'Toko roti & oleh-oleh mantao', 'desc' => 'Roti mantao adalah oleh-oleh yang dicari orang saat singgah di Parepare. Toko perlu tahu berapa yang diproduksi dan berapa yang terjual setiap hari.', 'jenis' => 'aplikasi-kasir-toko-roti'],
                    ['title' => 'Rumah makan & kedai tepi pantai', 'desc' => 'Rumah makan seafood dan kedai di sepanjang pesisir ramai di sore hingga malam, terutama di akhir pekan.', 'jenis' => 'aplikasi-kasir-restoran'],
                    ['title' => 'Apotek & toko obat', 'desc' => 'Apotek melayani warga kota dan pembeli dari daerah sekitar. Dengan ratusan jenis obat, tanggal kedaluwarsa tiap batch harus terpantau.', 'jenis' => 'aplikasi-kasir-apotek'],
                ],
                'tantangan_title' => 'Tantangan kasir yang sering muncul di Parepare',
                'tantangan_sub' => 'Toko yang menjual grosir dan eceran sekaligus paling sering bermasalah di stok dan harga modal.',
                'tantangan' => [
                    [
                        'problem' => 'Beli per dus, jual per pcs',
                        'detail' => 'Barang masuk dari distributor dalam dus, lalu dijual per dus ke pedagang dan per pcs ke pembeli biasa. Sisa stok dihitung manual.',
                        'solution' => 'satu barang punya beberapa satuan dengan faktor konversi, dan pecah kemasan memindahkan stok antar satuan secara otomatis.',
                        'paid' => true,
                    ],
                    [
                        'problem' => 'Nota dari distributor tercecer',
                        'detail' => 'Barang datang beberapa kali seminggu. Nota pembelian menumpuk sehingga harga modal terbaru tidak diketahui.',
                        'solution' => 'catat setiap pembelian per supplier. Stok bertambah dan harga modal ikut diperbarui.',
                        'paid' => true,
                    ],
                    [
                        'problem' => 'Pemilik tidak selalu di toko',
                        'detail' => 'Pemilik sering keluar kota untuk belanja barang atau mengurus usaha lain, dan baru tahu omzet saat pulang.',
                        'solution' => 'laporan penjualan bisa dibuka dari HP di mana saja, dan setiap karyawan masuk dengan akun serta hak aksesnya sendiri.',
                    ],
                ],
                'flow_title' => 'Satu hari di toko grosir Parepare',
                'flow_sub' => 'Contoh satu barang dari datang sampai terjual: minyak goreng.',
                'flow' => [
                    ['title' => 'Pagi: barang datang', 'desc' => 'Sepuluh dus minyak goreng dari distributor dicatat sebagai pembelian. Stok bertambah dan harga modalnya tersimpan.'],
                    ['title' => 'Siang: pedagang dari luar kota', 'desc' => 'Seorang pelanggan grosir mengambil lima dus. Karena tipenya grosir, harga grosir langsung terpakai.'],
                    ['title' => 'Sore: pembeli eceran', 'desc' => 'Tetangga membeli dua liter dengan harga eceran, dan stoknya ikut berkurang.'],
                    ['title' => 'Malam: pemilik mengecek dari rumah', 'desc' => 'Omzet hari itu dan barang terlaris terlihat dari HP.'],
                ],
                'bantuan' => 'Tim kami berbasis di Gowa, beberapa jam perjalanan dari Parepare. Karena itu bantuan diberikan dari jauh lewat WhatsApp, pada zona waktu yang sama dengan Anda.',
                'faq' => [
                    ['q' => 'Apakah ada perwakilan Kasir UMKM di Parepare?', 'a' => 'Tidak ada. Tim kami berbasis di Gowa, Sulawesi Selatan, dan melayani pengguna di Parepare lewat WhatsApp. Aplikasinya bisa dipasang sendiri tanpa kunjungan teknisi.'],
                    ['q' => 'Bisakah satu barang dijual per dus dan per pcs?', 'a' => 'Bisa. Satu barang dapat memiliki beberapa satuan dengan faktor konversi, misalnya 1 dus = 12 botol. Fitur pecah kemasan di paket GrowUp memindahkan stok dari dus ke pcs.'],
                    ['q' => 'Apakah bisa memberi harga khusus untuk pedagang langganan?', 'a' => 'Bisa. Simpan harga grosir pada barang, lalu tandai pelanggan tersebut sebagai pelanggan grosir. Harga grosir akan dipakai otomatis saat ia berbelanja.'],
                    ['q' => 'Apakah apotek di Parepare bisa memakai aplikasi ini?', 'a' => 'Bisa. Untuk jenis usaha apotek, stok dicatat per batch dengan tanggal kedaluwarsa, dan batch yang paling dekat kedaluwarsa dijual lebih dulu.'],
                ],
                'nearby' => ['makassar', 'maros', 'gowa'],
                'article_keywords' => ['stok', 'grosir', 'supplier', 'pembelian', 'apotek'],
            ],

            'manado' => [
                'name' => 'Manado',
                'province' => 'Sulawesi Utara',
                'zone' => 'WITA',
                'tagline' => 'Rumah kopi, kuliner Minahasa, dan oleh-oleh',
                'title' => 'Aplikasi Kasir Manado: Rumah Kopi, Kuliner & Toko | Kasir UMKM',
                'description' => 'Aplikasi kasir untuk usaha di Manado: rumah kopi, rumah makan khas Minahasa, toko oleh-oleh, dan kedai di kawasan Boulevard. Jalan di HP dan tablet.',
                'h1' => 'Aplikasi Kasir untuk Usaha di Manado',
                'lead' => 'Manado hidup dari kuliner dan tamu yang datang berwisata. Rumah kopi, rumah makan, dan toko oleh-oleh butuh kasir yang cepat saat ramai dan laporan yang jujur soal menu mana yang benar-benar menghasilkan.',
                'usaha_sub' => 'Kuliner dan oleh-oleh adalah wajah usaha kecil di Manado.',
                'usaha' => [
                    ['title' => 'Rumah kopi', 'desc' => 'Rumah kopi adalah tempat orang Manado bertemu dan mengobrol berjam-jam. Kopi, teh, dan kue dipesan bertahap, jadi tagihan meja perlu dibuka sejak tamu duduk.', 'jenis' => 'aplikasi-kasir-cafe'],
                    ['title' => 'Rumah makan khas Minahasa', 'desc' => 'Tinutuan di pagi hari, ikan bakar dan masakan pedas di siang dan malam. Banyak rumah makan melayani rombongan keluarga dan tamu yang sedang berwisata.', 'jenis' => 'aplikasi-kasir-restoran'],
                    ['title' => 'Toko oleh-oleh & kue', 'desc' => 'Klappertaart, sambal roa, dan kue khas dibawa pulang wisatawan. Pesanan menumpuk menjelang Natal dan musim libur, sering dipesan beberapa hari sebelumnya.', 'jenis' => 'aplikasi-kasir-toko-roti'],
                    ['title' => 'Toko di kawasan Boulevard', 'desc' => 'Pusat belanja di tepi pantai dipenuhi kedai minuman, toko pakaian, dan toko aksesori dengan banyak pilihan ukuran dan warna.', 'jenis' => 'aplikasi-kasir-toko-retail'],
                ],
                'tantangan_title' => 'Tantangan kasir yang sering muncul di Manado',
                'tantangan_sub' => 'Rumah makan yang ramai belum tentu untung, dan rombongan besar menguji kecepatan kasir.',
                'tantangan' => [
                    [
                        'problem' => 'Menu laris, untungnya tidak jelas',
                        'detail' => 'Harga ikan dan bumbu naik turun mengikuti musim. Harga jual jarang disesuaikan karena modal per porsi tidak pernah dihitung.',
                        'solution' => 'masukkan resep tiap menu. Aplikasi menghitung HPP per porsi sehingga Anda tahu menu mana yang untungnya tipis.',
                        'paid' => true,
                    ],
                    [
                        'problem' => 'Rombongan minta bayar terpisah',
                        'detail' => 'Satu meja panjang berisi dua belas orang dari tiga keluarga. Kasir memisahkan tagihan dengan kalkulator sambil antrean menunggu.',
                        'solution' => 'split bill membagi satu tagihan menjadi beberapa pembayaran, dan satu transaksi bisa dibayar dengan beberapa metode.',
                    ],
                    [
                        'problem' => 'Stok ukuran dan warna tidak terpantau',
                        'detail' => 'Toko pakaian menyimpan satu model dalam banyak ukuran dan warna. Pembeli menanyakan ukuran M, dan karyawan harus membongkar gudang untuk memastikan.',
                        'solution' => 'satu produk bisa punya varian ukuran atau warna, sehingga tiap pilihan tercatat sendiri.',
                        'paid' => true,
                    ],
                ],
                'flow_title' => 'Satu hari di rumah makan Manado',
                'flow_sub' => 'Contoh dari sarapan sampai rombongan makan siang.',
                'flow' => [
                    ['title' => 'Pagi: sarapan tinutuan', 'desc' => 'Pesanan dicatat per meja dan dibayar tunai atau QRIS begitu tamu selesai.'],
                    ['title' => 'Siang: rombongan datang', 'desc' => 'Dua belas orang duduk di satu meja panjang. Pesanannya disimpan sebagai satu tagihan terbuka.'],
                    ['title' => 'Selesai makan: bayar per keluarga', 'desc' => 'Tagihan dibagi tiga, service charge dan pajak ikut terhitung, dan tiap keluarga membayar dengan caranya sendiri.'],
                    ['title' => 'Malam: lihat menu terlaris', 'desc' => 'Laporan menunjukkan menu yang paling banyak dipesan hari itu, jadi belanja besok bisa disesuaikan.'],
                ],
                'bantuan' => 'Manado dan tim kami di Gowa sama-sama berada di zona WITA, jadi jam layanan kami sama dengan jam kerja Anda. Bantuan diberikan lewat WhatsApp.',
                'faq' => [
                    ['q' => 'Apakah Kasir UMKM punya kantor di Manado?', 'a' => 'Tidak. Kami berbasis di Gowa, Sulawesi Selatan. Pengguna di Manado mendaftar dan memasang aplikasinya sendiri, dan kami membantu lewat WhatsApp di zona waktu yang sama.'],
                    ['q' => 'Bisakah pajak restoran dan service charge dihitung otomatis?', 'a' => 'Bisa. Atur persentase pajak dan service charge di pengaturan. Nilainya dihitung di setiap transaksi dan tampil di struk.'],
                    ['q' => 'Apakah bisa dipakai di tablet untuk kasir rumah kopi?', 'a' => 'Bisa. Kasir UMKM berjalan di browser HP, tablet, dan laptop, juga lewat aplikasi Android.'],
                    ['q' => 'Bagaimana mencatat pesanan kue yang dipesan untuk Natal?', 'a' => 'Gunakan pre-order di paket GrowUp. Catat pemesan, uang muka, dan tanggal ambil, lalu pelunasan dicatat saat kue diambil.'],
                ],
                'nearby' => ['palu', 'kendari', 'makassar'],
                'article_keywords' => ['HPP', 'restoran', 'rumah makan', 'kopi', 'menu'],
            ],

            'palu' => [
                'name' => 'Palu',
                'province' => 'Sulawesi Tengah',
                'zone' => 'WITA',
                'tagline' => 'Kaledo, bawang goreng, dan usaha di tepi teluk',
                'title' => 'Aplikasi Kasir Palu untuk Warung, Kuliner & Toko | Kasir UMKM',
                'description' => 'Aplikasi kasir untuk usaha di Palu: rumah makan kaledo, toko oleh-oleh bawang goreng, toko kelontong, dan barbershop. Tetap mencatat saat offline.',
                'h1' => 'Aplikasi Kasir untuk Usaha di Palu',
                'lead' => 'Untuk usaha di Palu yang ingin berhenti mencatat di buku: rumah makan, toko oleh-oleh, warung, sampai tempat pangkas rambut. Cukup dari HP, dan penjualan tetap tercatat ketika internet bermasalah.',
                'usaha_sub' => 'Dari kuliner khas sampai usaha jasa, ini yang banyak dijalankan pelaku UMKM di Palu.',
                'usaha' => [
                    ['title' => 'Rumah makan kaledo', 'desc' => 'Kaledo dimasak sejak pagi dan sering habis sebelum sore. Pemilik perlu tahu berapa porsi yang terjual dan kapan biasanya habis.', 'jenis' => 'aplikasi-kasir-restoran'],
                    ['title' => 'Toko oleh-oleh bawang goreng', 'desc' => 'Bawang goreng Palu dijual dalam berbagai ukuran kemasan dan sering dikirim ke luar kota. Stok tiap ukuran harus dihitung terpisah.', 'jenis' => 'aplikasi-kasir-toko-retail'],
                    ['title' => 'Warung & toko kelontong', 'desc' => 'Warung di lingkungan perumahan menjadi tempat belanja harian, dan sebagian pelanggan terbiasa membayar setelah gajian.', 'jenis' => 'aplikasi-kasir-toko-kelontong'],
                    ['title' => 'Barbershop & salon', 'desc' => 'Tempat pangkas rambut modern tumbuh di sekitar kampus dan pusat kota, dengan beberapa kapster yang dibayar dari bagi hasil.', 'jenis' => 'aplikasi-kasir-barbershop'],
                ],
                'tantangan_title' => 'Tantangan kasir yang sering muncul di Palu',
                'tantangan_sub' => 'Catatan tangan masih umum dipakai, dan di situlah selisih biasanya bermula.',
                'tantangan' => [
                    [
                        'problem' => 'Bagi hasil kapster dihitung di kertas',
                        'detail' => 'Setiap malam pemilik menghitung jumlah kepala per kapster dari catatan tangan. Selisih kecil sering menjadi bahan perdebatan.',
                        'solution' => 'kapster dipilih di setiap transaksi, dan komisinya terhitung otomatis dalam persen atau nominal tetap.',
                    ],
                    [
                        'problem' => 'Kemasan besar dan kecil tertukar',
                        'detail' => 'Bawang goreng kemasan 100 gram dan 250 gram dicatat sebagai satu barang, sehingga stok dan harga sering salah.',
                        'solution' => 'setiap ukuran kemasan dicatat sebagai varian dari produk yang sama.',
                        'paid' => true,
                    ],
                    [
                        'problem' => 'Internet putus saat toko ramai',
                        'detail' => 'Ketika jaringan terganggu, kasir kembali menulis di buku dan catatan itu jarang dimasukkan lagi ke aplikasi.',
                        'solution' => 'kasir tetap mencatat penjualan saat offline dan menyinkronkannya sendiri setelah internet kembali.',
                    ],
                ],
                'flow_title' => 'Satu hari di barbershop Palu',
                'flow_sub' => 'Contoh dari pelanggan pertama sampai hitung bagi hasil.',
                'flow' => [
                    ['title' => 'Buka: pelanggan mulai antre', 'desc' => 'Pelanggan pertama mengambil nomor antrean dan memilih kapster langganannya.'],
                    ['title' => 'Giliran tiba', 'desc' => 'Saat gilirannya tiba, status antrean diubah menjadi dilayani.'],
                    ['title' => 'Bayar di kasir', 'desc' => 'Potong rambut dan satu produk perawatan dibayar sekaligus. Nama kapster ikut tercatat di transaksi.'],
                    ['title' => 'Tutup: bagi hasil', 'desc' => 'Komisi setiap kapster hari itu sudah terhitung, tanpa menjumlah catatan tangan.'],
                ],
                'bantuan' => 'Palu berada di zona WITA, sama dengan tim kami di Gowa. Kami tidak punya perwakilan di Sulawesi Tengah, jadi semua bantuan diberikan lewat WhatsApp dan panduan tertulis.',
                'faq' => [
                    ['q' => 'Apakah bisa dipakai di Palu tanpa didatangi teknisi?', 'a' => 'Bisa. Pendaftaran dan pemasangan dilakukan sendiri dari HP. Tim kami berbasis di Gowa, Sulawesi Selatan, dan membantu lewat WhatsApp.'],
                    ['q' => 'Apa yang terjadi kalau internet mati saat melayani pembeli?', 'a' => 'Anda tetap bisa melanjutkan transaksi. Penjualan yang dicatat saat offline tersinkron otomatis setelah internet tersambung lagi.'],
                    ['q' => 'Bisakah menghitung komisi kapster secara otomatis?', 'a' => 'Bisa. Atur komisi dalam persentase atau nominal tetap, dan bedakan per layanan bila perlu. Laporan komisi per kapster tersedia di paket GrowUp.'],
                    ['q' => 'Apakah bisa mencetak struk untuk pembeli oleh-oleh?', 'a' => 'Bisa. Struk dicetak ke printer thermal Bluetooth dari browser yang mendukung Web Bluetooth atau dari aplikasi Android Kasir UMKM.'],
                ],
                'nearby' => ['manado', 'kendari', 'makassar'],
                'article_keywords' => ['barbershop', 'komisi', 'warung', 'stok', 'gaji'],
            ],

            'kendari' => [
                'name' => 'Kendari',
                'province' => 'Sulawesi Tenggara',
                'zone' => 'WITA',
                'tagline' => 'Seafood teluk, oleh-oleh mete, dan laundry',
                'title' => 'Aplikasi Kasir Kendari: Kuliner, Toko & Laundry | Kasir UMKM',
                'description' => 'Aplikasi kasir untuk usaha di Kendari: rumah makan seafood dan sinonggi, toko oleh-oleh mete, minimarket, dan laundry. Laporan bisa dibuka dari HP.',
                'h1' => 'Aplikasi Kasir untuk Usaha di Kendari',
                'lead' => 'Rumah makan di tepi teluk, toko oleh-oleh, minimarket, dan laundry di Kendari punya kebutuhan kasir yang berbeda. Kasir UMKM membantu pemiliknya mencatat penjualan dan stok sejak hari pertama buka.',
                'usaha_sub' => 'Empat jenis usaha yang umum di Kendari, masing-masing dengan cara mencatat yang berbeda.',
                'usaha' => [
                    ['title' => 'Rumah makan seafood & sinonggi', 'desc' => 'Ikan segar dibeli setiap pagi dan harganya berubah-ubah. Rumah makan perlu mencatat belanja harian supaya tahu untung sebenarnya.', 'jenis' => 'aplikasi-kasir-restoran'],
                    ['title' => 'Toko oleh-oleh mete', 'desc' => 'Kacang mete dijual per kemasan dan per dus, mentah maupun goreng. Wisatawan dan perantau yang pulang kampung membelinya dalam jumlah banyak.', 'jenis' => 'aplikasi-kasir-toko-retail'],
                    ['title' => 'Minimarket & toko kelontong', 'desc' => 'Barang di minimarket dan toko kelontong jumlahnya ratusan, dan pemilik sering menjaga toko sambil mengerjakan hal lain.', 'jenis' => 'aplikasi-kasir-toko-kelontong'],
                    ['title' => 'Laundry kiloan', 'desc' => 'Pekerja dan mahasiswa perantau menjadi pelanggan tetap laundry. Order datang setiap hari dengan janji selesai yang berbeda-beda.', 'jenis' => 'aplikasi-kasir-laundry'],
                ],
                'tantangan_title' => 'Tantangan kasir yang sering muncul di Kendari',
                'tantangan_sub' => 'Uang yang keluar masuk tanpa catatan adalah sumber masalah paling umum.',
                'tantangan' => [
                    [
                        'problem' => 'Belanja ikan pagi tidak tercatat',
                        'detail' => 'Uang belanja diambil dari laci kasir tanpa catatan. Di akhir bulan omzet terlihat besar, tetapi uangnya tidak ada.',
                        'solution' => 'catat pembelian bahan dari pemasok, lalu bandingkan dengan laporan penjualan.',
                        'paid' => true,
                    ],
                    [
                        'problem' => 'Janji selesai cucian meleset',
                        'detail' => 'Order laundry ditulis di buku tanpa urutan. Pelanggan datang mengambil, dan cuciannya ternyata belum dikerjakan.',
                        'solution' => 'setiap order punya perkiraan tanggal selesai dan status, dari diterima, diproses, sampai siap diambil.',
                    ],
                    [
                        'problem' => 'Karyawan menjaga toko sendirian',
                        'detail' => 'Pemilik tidak selalu di tempat, dan tidak ada cara mudah untuk mencocokkan uang di laci dengan penjualan hari itu.',
                        'solution' => 'kasir membuka dan menutup shift, sehingga setoran tiap shift bisa dicocokkan dengan penjualannya.',
                        'paid' => true,
                    ],
                ],
                'flow_title' => 'Satu hari di laundry kiloan Kendari',
                'flow_sub' => 'Contoh satu order dari cucian masuk sampai diambil.',
                'flow' => [
                    ['title' => 'Pagi: cucian masuk', 'desc' => 'Cuci setrika 5,3 kg dan dua selimut dicatat dalam satu order, dengan perkiraan selesai lusa.'],
                    ['title' => 'Uang muka', 'desc' => 'Pelanggan membayar sebagian. Sisa tagihannya tersimpan di order itu.'],
                    ['title' => 'Dikerjakan', 'desc' => 'Karyawan mengubah status menjadi diproses, lalu siap diambil ketika sudah dilipat.'],
                    ['title' => 'Diambil', 'desc' => 'Sisa tagihan dilunasi, order selesai, dan penjualannya masuk ke laporan.'],
                ],
                'bantuan' => 'Kami tidak punya perwakilan di Sulawesi Tenggara. Tim kami di Gowa melayani pengguna di Kendari lewat WhatsApp, dan keduanya berada di zona WITA.',
                'faq' => [
                    ['q' => 'Apakah Kasir UMKM bisa dipakai di Kendari?', 'a' => 'Bisa. Kasir UMKM berjalan di browser dan aplikasi Android, jadi bisa dipakai di mana saja selama ada HP. Tim kami berbasis di Gowa, Sulawesi Selatan, dan membantu lewat WhatsApp.'],
                    ['q' => 'Bisakah menjual mete per kemasan dan per dus?', 'a' => 'Bisa. Satu barang dapat punya beberapa satuan dengan faktor konversi, misalnya per kemasan dan per dus.'],
                    ['q' => 'Apakah order laundry bisa dibayar sebagian dulu?', 'a' => 'Bisa. Setiap order punya status bayar: belum bayar, sebagian, atau lunas. Pelunasan dicatat saat cucian diambil.'],
                    ['q' => 'Saya baru buka usaha, paket mana yang sebaiknya dipilih?', 'a' => 'Mulai dari paket Starter yang gratis untuk mengenal aplikasinya. Bila jumlah produk bertambah atau Anda butuh pembelian, shift kasir, dan fitur lanjutan lain, pindah ke paket GrowUp.'],
                ],
                'nearby' => ['makassar', 'palu', 'manado'],
                'article_keywords' => ['laundry', 'restoran', 'stok', 'pembukuan', 'warung'],
            ],

            'jakarta' => [
                'name' => 'Jakarta',
                'province' => 'DKI Jakarta',
                'zone' => 'WIB',
                'tagline' => 'Warteg, kedai kopi, grosir, dan pesanan ojol',
                'title' => 'Aplikasi Kasir Jakarta & Jabodetabek untuk UMKM | Kasir UMKM',
                'description' => 'Aplikasi kasir untuk UMKM di Jakarta, Bogor, Depok, Tangerang, dan Bekasi: warteg, kedai kopi, toko grosir, dan usaha rumahan. Catat penjualan ojol.',
                'h1' => 'Aplikasi Kasir untuk UMKM di Jakarta & Jabodetabek',
                'lead' => 'Di Jakarta dan kota sekitarnya, pembeli datang dari banyak arah: makan di tempat, bungkus, dan pesanan ojek online. Kasir UMKM mencatat semuanya dalam satu aplikasi di HP, tanpa perlu membeli mesin kasir.',
                'usaha_sub' => 'Berlaku juga untuk Bogor, Depok, Tangerang, dan Bekasi, yang jenis usahanya mirip.',
                'usaha' => [
                    ['title' => 'Warteg & warung makan', 'desc' => 'Jam makan siang pekerja kantor berlangsung singkat dan padat. Lauk dipilih sendiri, jadi kasir harus menghitung kombinasi yang berbeda di setiap piring.', 'jenis' => 'aplikasi-kasir-restoran'],
                    ['title' => 'Kedai kopi & minuman', 'desc' => 'Kedai kopi susu dan booth minuman menjual lewat gerai dan lewat aplikasi ojek online, dengan pilihan gula, es, dan topping di tiap pesanan.', 'jenis' => 'aplikasi-kasir-cafe'],
                    ['title' => 'Toko grosir & pedagang pasar', 'desc' => 'Pedagang di pusat grosir seperti Tanah Abang dan Asemka melayani pembeli eceran dan reseller, dengan banyak varian warna dan ukuran.', 'jenis' => 'aplikasi-kasir-toko-retail'],
                    ['title' => 'Usaha kue rumahan', 'desc' => 'Banyak usaha kue dan makanan dijalankan dari dapur rumah di Bogor, Depok, Tangerang, dan Bekasi, dengan pesanan yang masuk lewat chat.', 'jenis' => 'aplikasi-kasir-toko-roti'],
                ],
                'tantangan_title' => 'Tantangan kasir yang sering muncul di Jabodetabek',
                'tantangan_sub' => 'Persaingan ketat dan biaya tinggi membuat setiap rupiah yang tidak tercatat terasa.',
                'tantangan' => [
                    [
                        'problem' => 'Penjualan ojol dan gerai tercampur',
                        'detail' => 'Uang dari aplikasi ojek online masuk belakangan dan sudah dipotong komisi. Tanpa dipisah, omzet gerai terlihat lebih besar dari kenyataannya.',
                        'solution' => 'catat penjualan GoFood, GrabFood, dan ShopeeFood sebagai channel penjualan tersendiri beserta potongan komisinya.',
                    ],
                    [
                        'problem' => 'Pesanan custom salah racik',
                        'detail' => 'Less sugar, extra shot, tanpa es. Saat antrean panjang, catatan tangan di cup sulit dibaca dan minuman dibuat ulang.',
                        'solution' => 'pilihan gula, ukuran, dan topping dipilih di layar kasir dan tercetak di struk untuk barista.',
                    ],
                    [
                        'problem' => 'Biaya naik, harga jual menebak',
                        'detail' => 'Biaya tempat dan bahan terus naik. Tanpa angka modal per produk, menaikkan harga terasa seperti menebak.',
                        'solution' => 'resep dan HPP menghitung modal tiap menu, sehingga harga jual ditetapkan dari angka.',
                        'paid' => true,
                    ],
                ],
                'flow_title' => 'Satu hari di kedai kopi Jakarta',
                'flow_sub' => 'Contoh kedai kecil yang menjual di gerai dan lewat ojek online.',
                'flow' => [
                    ['title' => 'Pagi: pesanan ojol masuk', 'desc' => 'Tiga pesanan dari aplikasi ojek online dicatat di kasir dengan sumber pesanannya masing-masing.'],
                    ['title' => 'Siang: antrean pekerja kantor', 'desc' => 'Kasir memilih menu beserta level gula dan ukuran. Struk untuk barista tercetak dengan detail pilihannya.'],
                    ['title' => 'Sore: bayar campur', 'desc' => 'Seorang pelanggan membayar sebagian tunai dan sisanya QRIS. Keduanya tercatat dalam satu transaksi.'],
                    ['title' => 'Malam: bandingkan sumber penjualan', 'desc' => 'Laporan penjualan memperlihatkan omzet gerai dan omzet pesanan online secara terpisah.'],
                ],
                'bantuan' => 'Kami tidak punya kantor di Jakarta. Tim kami berbasis di Gowa, Sulawesi Selatan, yang waktunya satu jam lebih cepat dari Jakarta, dan melayani lewat WhatsApp.',
                'faq' => [
                    ['q' => 'Apakah Kasir UMKM punya kantor atau teknisi di Jakarta?', 'a' => 'Tidak. Kami berbasis di Gowa, Sulawesi Selatan. Aplikasinya dipasang sendiri dari HP, dan bantuan diberikan lewat WhatsApp pada jam kerja.'],
                    ['q' => 'Apakah terhubung langsung ke GoFood, GrabFood, atau ShopeeFood?', 'a' => 'Belum terhubung otomatis. Penjualan dari aplikasi tersebut dicatat manual sebagai channel penjualan beserta potongan komisinya, sehingga tetap masuk ke laporan.'],
                    ['q' => 'Bisakah dipakai usaha rumahan tanpa toko fisik?', 'a' => 'Bisa. Cukup HP untuk mencatat pesanan dan pembayaran. Pesanan yang dibayar uang muka bisa dicatat dengan fitur pre-order di paket GrowUp.'],
                    ['q' => 'Saya punya gerai di Jakarta dan Depok, bisa dalam satu akun?', 'a' => 'Bisa. Tambahkan gerai kedua sebagai outlet tambahan, yang tersedia sebagai add-on berbayar. Stok dapat dipindahkan dari satu outlet ke outlet lain.'],
                ],
                'nearby' => ['makassar', 'manado', 'gowa'],
                'article_keywords' => ['ojol', 'GoFood', 'kopi', 'HPP', 'warung makan'],
            ],
        ];

        $keyed = [];
        foreach ($pages as $key => $page) {
            $page['key'] = $key;
            $page['slug'] = 'aplikasi-kasir-'.$key;
            $keyed[$page['slug']] = $page;
        }

        return $keyed;
    }
}
