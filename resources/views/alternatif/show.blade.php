@extends('blog.layout')

{{--
    Halaman "Alternatif [kompetitor]". Fakta kompetitor diambil dari App\Support\Kompetitor
    (bertanggal + bersumber). Fakta Kasir UMKM harus sesuai fitur yang benar-benar ada.
--}}

@php
    /** @var array<string, mixed> $k */
    $canonical = url('/'.$k['slug']);
    $title = 'Alternatif '.$k['name'].' untuk UMKM Indonesia | Kasir UMKM';
    $description = 'Mencari alternatif '.$k['name'].'? Bandingkan harga dan fitur dengan Kasir UMKM by MaccaPOS: harga dalam Rupiah, tanpa biaya per karyawan, fitur usaha lokal.';
    $jsonFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    $og = \App\Support\LandingSeo::openGraphImage();
    $paid = $pricing['paid'];

    $faq = [
        ['q' => 'Apa alternatif '.$k['name'].' untuk UMKM di Indonesia?', 'a' => 'Kasir UMKM by MaccaPOS adalah aplikasi kasir buatan Indonesia dengan harga dalam Rupiah. Paket Starter gratis, dan paket '.$paid['name'].' '.($paid['monthly_label'] !== '' ? $paid['monthly_label'].' per bulan ' : '').'per outlet tanpa biaya tambahan per karyawan.'],
        ['q' => 'Apakah '.$k['name'].' gratis?', 'a' => 'Menurut halaman harga '.$k['name'].' (per '.$k['checked_label'].'), Loyverse POS, Dashboard, layar dapur, dan layar pelanggan gratis. Riwayat penjualan tanpa batas, manajemen karyawan, dan manajemen stok lanjutan adalah fasilitas tambahan berbayar dalam dolar AS.'],
        ['q' => 'Bisakah data barang dari '.$k['name'].' dipindahkan?', 'a' => 'Bisa lewat spreadsheet. Siapkan daftar barang (nama, harga, stok, barcode) dalam file Excel, lalu impor dengan template impor produk di Kasir UMKM. Riwayat transaksi lama tidak ikut dipindahkan.'],
        ['q' => 'Apakah Kasir UMKM punya aplikasi iPhone?', 'a' => 'Kasir UMKM berjalan di browser HP (termasuk iPhone), tablet, dan laptop. Aplikasi yang diinstal dari toko aplikasi saat ini tersedia untuk Android.'],
        ['q' => 'Bagaimana kalau usaha saya punya beberapa cabang?', 'a' => 'Di Kasir UMKM, setiap outlet tambahan adalah add-on berbayar'.($paid['outlet_addon_label'] ? ' '.$paid['outlet_addon_label'].' per bulan' : '').', dan stok bisa ditransfer antar outlet. Di '.$k['name'].', mengelola beberapa toko tercantum gratis, tetapi fasilitas tambahan dihitung per toko.'],
    ];

    $schema = [
        '@context' => 'https://schema.org',
        '@graph' => [
            [
                '@type' => 'WebPage',
                '@id' => $canonical.'#webpage',
                'url' => $canonical,
                'name' => $title,
                'description' => $description,
                'inLanguage' => 'id-ID',
                'dateModified' => $k['checked_at'],
                'isPartOf' => ['@type' => 'WebSite', 'name' => 'Kasir UMKM by MaccaPOS', 'url' => url('/')],
                'about' => ['@id' => url('/').'/#software'],
            ],
            [
                '@type' => 'BreadcrumbList',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Beranda', 'item' => url('/')],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => 'Alternatif '.$k['name'], 'item' => $canonical],
                ],
            ],
            [
                '@type' => 'FAQPage',
                'mainEntity' => array_map(fn ($f) => [
                    '@type' => 'Question',
                    'name' => $f['q'],
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']],
                ], $faq),
            ],
        ],
    ];

    // Baris perbandingan: [aspek, Kasir UMKM, kompetitor]. Kolom kompetitor hanya berisi yang tercantum di situs mereka.
    $rows = [
        ['Kasir (POS) & struk', 'Gratis (paket '.$pricing['free']['name'].')', 'Gratis'],
        ['Batas paket gratis', 'Transaksi tanpa batas, '.$pricing['free']['max_stores'].' outlet'.($pricing['free']['max_products'] ? ', '.$pricing['free']['max_products'].' produk' : '').($pricing['free']['max_customers'] ? ', '.$pricing['free']['max_customers'].' pelanggan' : ''), 'Lihat situs '.$k['name']],
        ['Mata uang tagihan', 'Rupiah', 'Dolar AS (USD), belum termasuk pajak'],
        ['Karyawan & hak akses', 'Termasuk paket '.$paid['name'].' (kasir, gudang, manajer, admin), tanpa biaya per karyawan', 'Add-on $5 USD / bulan per karyawan'],
        ['Stok lanjutan (pembelian, opname)', 'Termasuk paket '.$paid['name'], 'Add-on $25 USD / bulan per toko'],
        ['Rentang laporan penjualan', 'Hingga 1 tahun per laporan (paket '.$paid['name'].')', 'Riwayat tanpa batas: add-on $9 USD / bulan per toko'],
        ['Beberapa toko / outlet', 'Add-on'.($paid['outlet_addon_label'] ? ' '.$paid['outlet_addon_label'].' / bulan' : '').' per outlet', 'Gratis (add-on dihitung per toko)'],
        ['Layar dapur (KDS)', 'Termasuk paket '.$paid['name'], 'Gratis'],
        ['Layar pelanggan (CDS)', 'Belum tersedia', 'Gratis'],
    ];

    // Fitur usaha lokal di Kasir UMKM. Kolom kompetitor sengaja tidak diisi "tidak ada":
    // kami hanya bisa menyatakan tidak menemukannya di situs mereka.
    $local = [
        ['title' => 'Utang pelanggan & cicilan', 'desc' => 'Catat transaksi bayar belakangan, cicilan, dan jatuh tempo per pelanggan.', 'href' => '/aplikasi-kasir-toko-kelontong'],
        ['title' => 'Apotek: batch, FEFO, nomor resep', 'desc' => 'Stok per batch dengan kedaluwarsa, batch terdekat terjual dulu, obat resep meminta nomor resep.', 'href' => '/aplikasi-kasir-apotek'],
        ['title' => 'Order laundry kiloan', 'desc' => 'Order per kg/pcs, status cucian, DP dan pelunasan, otomatis masuk laporan.', 'href' => '/aplikasi-kasir-laundry'],
        ['title' => 'Antrean & komisi kapster', 'desc' => 'Nomor antrean, pilih kapster, dan komisi otomatis per layanan.', 'href' => '/aplikasi-kasir-barbershop'],
        ['title' => 'Produksi, resep & HPP', 'desc' => 'Catat produksi dari bahan baku dan hitung modal per produk.', 'href' => '/aplikasi-kasir-toko-roti'],
        ['title' => 'Pesan lewat QR meja', 'desc' => 'Tamu memesan dari QR di meja, pesanan masuk ke kasir.', 'href' => '/aplikasi-kasir-restoran'],
    ];
@endphp

@section('title', $title)
@section('brand_tag', 'Perbandingan')

@section('meta')
    <meta name="description" content="{{ $description }}">
    <link rel="canonical" href="{{ $canonical }}">
    <meta name="robots" content="index, follow">
    <meta property="og:type" content="article">
    <meta property="og:site_name" content="Kasir UMKM by MaccaPOS">
    <meta property="og:locale" content="id_ID">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $canonical }}">
    <meta property="og:image" content="{{ $og['url'] }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:image" content="{{ $og['url'] }}">
    <script type="application/ld+json">{!! json_encode($schema, $jsonFlags) !!}</script>

    <style>
        .alt-hero { background: var(--primary-muted); border-bottom: 1px solid var(--line); padding: 8px 0 44px; }
        .alt-hero h1 { font-size: clamp(30px, 4.6vw, 46px); margin: 14px 0 14px; max-width: 860px; }
        .alt-hero .lead { color: var(--muted); font-size: 18px; margin: 0 0 22px; max-width: 760px; }
        .alt-tldr { background: #fff; border: 1px solid var(--line); border-radius: 20px; padding: 22px 24px; margin-top: 26px; max-width: 860px; }
        .alt-tldr h2 { font-size: 16px; margin: 0 0 10px; }
        .alt-tldr ul { margin: 0; padding-left: 20px; color: #3b3a4a; }
        .alt-tldr li { margin-bottom: 6px; }

        .alt-sec { padding: 52px 0 0; }
        .alt-sec h2 { font-size: clamp(23px, 3vw, 32px); margin: 0 0 8px; }
        .alt-sec .sub { color: var(--muted); margin: 0 0 22px; max-width: 760px; }

        .alt-table-wrap { overflow-x: auto; border: 1px solid var(--line); border-radius: 18px; background: #fff; }
        .alt-table { width: 100%; border-collapse: collapse; min-width: 640px; font-size: 15px; }
        .alt-table th, .alt-table td { text-align: left; padding: 14px 18px; border-bottom: 1px solid var(--line); vertical-align: top; line-height: 1.55; }
        .alt-table thead th { background: var(--container); font-family: var(--font-display); font-size: 14px; }
        .alt-table thead th.us { background: var(--primary-soft); color: var(--primary-dark); }
        .alt-table td.us { background: #faf9ff; font-weight: 600; }
        .alt-table tr:last-child td { border-bottom: 0; }
        .alt-source { font-size: 13px; color: var(--muted); margin: 10px 0 0; }

        .alt-grid { display: grid; gap: 18px; grid-template-columns: minmax(0, 1fr); }
        @media (min-width: 720px) { .alt-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (min-width: 1024px) { .alt-grid.three { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        .alt-box { background: #fff; border: 1px solid var(--line); border-radius: 20px; padding: 22px 24px; }
        .alt-box h3 { font-size: 18px; margin: 0 0 8px; }
        .alt-box p { margin: 0; color: #4a4860; font-size: 15px; line-height: 1.65; }
        .alt-box a.more { display: inline-block; margin-top: 10px; font-weight: 600; font-size: 14px; }
        .alt-box.fit { border-color: #c3b9fb; background: var(--primary-muted); }
        .alt-box ul { margin: 6px 0 0; padding-left: 20px; color: #4a4860; font-size: 15px; }
        .alt-box li { margin-bottom: 6px; }

        .alt-cost { display: grid; gap: 18px; grid-template-columns: minmax(0, 1fr); }
        @media (min-width: 760px) { .alt-cost { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        .alt-cost .line { display: flex; justify-content: space-between; gap: 12px; padding: 8px 0; border-bottom: 1px dashed var(--line); font-size: 15px; color: #3b3a4a; }
        .alt-cost .total { display: flex; justify-content: space-between; gap: 12px; padding-top: 12px; font-family: var(--font-display); font-weight: 800; font-size: 20px; }

        .alt-steps { counter-reset: s; list-style: none; margin: 0; padding: 0; display: grid; gap: 12px; }
        .alt-steps li { counter-increment: s; display: flex; gap: 14px; align-items: flex-start; background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: 16px 18px; color: #3b3a4a; }
        .alt-steps li::before { content: counter(s); flex: none; width: 30px; height: 30px; border-radius: 9px; background: var(--primary); color: #fff; font-family: var(--font-display); font-weight: 800; display: flex; align-items: center; justify-content: center; }

        .faq-item { border: 1px solid var(--line); border-radius: 16px; padding: 16px 20px; margin-bottom: 12px; background: #fff; }
        .faq-item[open] { border-color: #c3b9fb; background: var(--primary-muted); }
        .faq-item summary { font-family: var(--font-display); font-weight: 700; font-size: 16px; cursor: pointer; list-style: none; display: flex; justify-content: space-between; gap: 14px; }
        .faq-item summary::-webkit-details-marker { display: none; }
        .faq-item summary::after { content: '+'; color: var(--primary); font-size: 22px; line-height: 1; flex: none; }
        .faq-item[open] summary::after { content: '−'; }
        .faq-item p { margin: 12px 0 0; color: #4a4860; font-size: 15.5px; }
        .alt-disclaimer { margin-top: 30px; font-size: 13px; color: var(--muted); }
    </style>
@endsection

@section('content')
    <section class="alt-hero">
        <div class="wrap">
            <nav class="crumbs" aria-label="Breadcrumb">
                <a href="{{ url('/') }}">Beranda</a><span class="s">/</span>
                <span>Alternatif {{ $k['name'] }}</span>
            </nav>
            <span class="pill" style="margin-top: 18px">Diperbarui {{ $k['checked_label'] }}</span>
            <h1>Alternatif {{ $k['name'] }}: aplikasi kasir dengan harga Rupiah untuk UMKM Indonesia</h1>
            <p class="lead">
                {{ $k['name'] }} adalah aplikasi kasir gratis yang populer. Banyak pemilik usaha mulai mencari alternatif saat butuh fitur
                berbayarnya, karena biaya tambahan dihitung dalam dolar per toko dan per karyawan. Berikut perbandingan yang jujur,
                termasuk kapan {{ $k['name'] }} justru lebih cocok.
            </p>
            <div class="ju-acts" style="display:flex;flex-wrap:wrap;gap:10px">
                <a class="btn btn-primary" href="{{ url('/register') }}">Coba Kasir UMKM gratis</a>
                <a class="btn btn-ghost" href="#perbandingan">Lihat perbandingan</a>
            </div>

            <div class="alt-tldr">
                <h2>Ringkasnya</h2>
                <ul>
                    <li><strong>Harga:</strong> paket {{ $paid['name'] }} Kasir UMKM {{ $paid['monthly_label'] !== '' ? $paid['monthly_label'].' per bulan' : '' }} dalam Rupiah, sudah termasuk karyawan dan stok lanjutan. Di {{ $k['name'] }}, keduanya add-on berbayar dalam USD.</li>
                    <li><strong>Fitur lokal:</strong> Kasir UMKM punya utang pelanggan, mode apotek, order laundry, dan komisi kapster.</li>
                    <li><strong>{{ $k['name'] }} lebih unggul</strong> bila Anda butuh banyak toko gratis, layar pelanggan, atau aplikasi iPhone.</li>
                </ul>
            </div>
        </div>
    </section>

    <section class="alt-sec">
        <div class="wrap">
            <h2>Kenapa pemilik usaha mencari alternatif {{ $k['name'] }}</h2>
            <p class="sub">Alasan yang paling sering muncul saat usaha mulai berkembang.</p>
            <div class="alt-grid three">
                <div class="alt-box">
                    <h3>Biaya tambahan dalam dolar</h3>
                    <p>Fasilitas tambahan {{ $k['name'] }} ditagih dalam USD dan belum termasuk pajak, sehingga biaya bulanan dalam Rupiah ikut kurs.</p>
                </div>
                <div class="alt-box">
                    <h3>Biaya naik seiring jumlah karyawan</h3>
                    <p>Manajemen karyawan {{ $k['name'] }} dihitung per karyawan. Semakin banyak kasir, semakin besar biayanya.</p>
                </div>
                <div class="alt-box">
                    <h3>Butuh fitur khas usaha Indonesia</h3>
                    <p>Seperti catatan utang pelanggan warung, nomor resep di apotek, atau order laundry kiloan dengan DP.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="alt-sec" id="perbandingan">
        <div class="wrap">
            <h2>Perbandingan Kasir UMKM vs {{ $k['name'] }}</h2>
            <p class="sub">Kolom {{ $k['name'] }} hanya berisi yang tercantum di situs resminya per {{ $k['checked_label'] }}.</p>
            <div class="alt-table-wrap">
                <table class="alt-table">
                    <thead>
                        <tr>
                            <th scope="col">Aspek</th>
                            <th scope="col" class="us">Kasir UMKM by MaccaPOS</th>
                            <th scope="col">{{ $k['name'] }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <th scope="row">{{ $row[0] }}</th>
                                <td class="us">{{ $row[1] }}</td>
                                <td>{{ $row[2] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="alt-source">
                Sumber data {{ $k['name'] }}: <a href="{{ $k['source'] }}" rel="nofollow noopener" target="_blank">halaman harga {{ $k['name'] }}</a>,
                dicek {{ $k['checked_label'] }}. {{ $k['addon_trial'] }} Harga bisa berubah sewaktu-waktu.
            </p>
        </div>
    </section>

    <section class="alt-sec">
        <div class="wrap">
            <h2>Contoh biaya bulanan: 1 toko, 3 karyawan</h2>
            <p class="sub">Toko yang ingin akun terpisah untuk 3 karyawan, laporan penjualan panjang, dan pengelolaan stok lengkap (pembelian & opname).</p>
            <div class="alt-cost">
                <div class="alt-box">
                    <h3>{{ $k['name'] }} (add-on yang dibutuhkan)</h3>
                    <div class="line"><span>Loyverse POS</span><span>Gratis</span></div>
                    <div class="line"><span>Riwayat penjualan tanpa batas</span><span>$9 USD</span></div>
                    <div class="line"><span>Manajemen karyawan × 3</span><span>$15 USD</span></div>
                    <div class="line"><span>Manajemen stok barang</span><span>$25 USD</span></div>
                    <div class="total"><span>Total</span><span>$49 USD / bulan</span></div>
                    <p style="margin-top:10px;font-size:13px">Belum termasuk pajak, ditagih dalam USD (nilai Rupiah mengikuti kurs).</p>
                </div>
                <div class="alt-box fit">
                    <h3>Kasir UMKM (paket {{ $paid['name'] }})</h3>
                    <div class="line"><span>Kasir & struk</span><span>Termasuk</span></div>
                    <div class="line"><span>Laporan penjualan (rentang hingga 1 tahun)</span><span>Termasuk</span></div>
                    <div class="line"><span>Akun karyawan & hak akses</span><span>Termasuk</span></div>
                    <div class="line"><span>Pembelian & stok opname</span><span>Termasuk</span></div>
                    <div class="total"><span>Total</span><span>{{ $paid['monthly_label'] !== '' ? $paid['monthly_label'] : 'Lihat harga' }} / bulan</span></div>
                    <p style="margin-top:10px;font-size:13px">Dalam Rupiah. Absensi & payroll karyawan tersedia sebagai add-on terpisah.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="alt-sec">
        <div class="wrap">
            <h2>Fitur usaha lokal di Kasir UMKM</h2>
            <p class="sub">Fitur ini tidak kami temukan di halaman fitur dan harga {{ $k['name'] }} per {{ $k['checked_label'] }}. Silakan cek langsung bila fitur ini penting bagi Anda.</p>
            <div class="alt-grid three">
                @foreach ($local as $f)
                    <div class="alt-box">
                        <h3>{{ $f['title'] }}</h3>
                        <p>{{ $f['desc'] }}</p>
                        <a class="more" href="{{ url($f['href']) }}">Selengkapnya &rarr;</a>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="alt-sec">
        <div class="wrap">
            <h2>Mana yang cocok untuk Anda?</h2>
            <div class="alt-grid" style="margin-top: 22px">
                <div class="alt-box fit">
                    <h3>Pilih Kasir UMKM bila</h3>
                    <ul>
                        <li>Ingin biaya tetap dalam Rupiah, tanpa hitungan per karyawan</li>
                        <li>Punya beberapa kasir atau staf gudang dalam satu outlet</li>
                        <li>Butuh utang pelanggan, mode apotek, laundry, atau komisi kapster</li>
                        <li>Ingin menghitung HPP dan produksi dari resep</li>
                    </ul>
                </div>
                <div class="alt-box">
                    <h3>{{ $k['name'] }} mungkin lebih cocok bila</h3>
                    <ul>
                        @foreach ($k['strengths'] as $s)
                            <li><strong>{{ $s['title'] }}:</strong> {{ $s['desc'] }}</li>
                        @endforeach
                        <li><strong>Hanya butuh fitur dasar:</strong> kasir dan stok dasar ({{ $k['free_inventory'] }}) tanpa add-on.</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <section class="alt-sec">
        <div class="wrap">
            <h2>Cara pindah dari {{ $k['name'] }}</h2>
            <p class="sub">Yang dipindahkan adalah data barang. Riwayat transaksi lama tetap tersimpan di akun {{ $k['name'] }} Anda.</p>
            <ol class="alt-steps">
                <li>Daftar Kasir UMKM gratis dan pilih jenis usaha Anda.</li>
                <li>Unduh template impor produk dari menu Produk.</li>
                <li>Salin daftar barang Anda (nama, harga, stok, barcode) ke template, lalu impor.</li>
                <li>Atur printer struk dan metode pembayaran (tunai, QRIS, transfer), lalu mulai berjualan.</li>
            </ol>
        </div>
    </section>

    <section class="alt-sec" id="faq">
        <div class="wrap">
            <h2>Pertanyaan seputar alternatif {{ $k['name'] }}</h2>
            <div style="margin-top: 20px">
                @foreach ($faq as $f)
                    <details class="faq-item" @if ($loop->first) open @endif>
                        <summary>{{ $f['q'] }}</summary>
                        <p>{{ $f['a'] }}</p>
                    </details>
                @endforeach
            </div>
            <p class="alt-disclaimer">
                {{ $k['name'] }} adalah merek milik pemiliknya. Kasir UMKM by MaccaPOS tidak berafiliasi dengan {{ $k['name'] }}.
                Informasi {{ $k['name'] }} diambil dari situs resminya per {{ $k['checked_label'] }}. Jika ada yang tidak akurat,
                hubungi kami di info@umkmkasir.id.
            </p>
        </div>
    </section>
@endsection
