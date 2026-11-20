@extends('blog.layout')

{{--
    Halaman "Alternatif [kompetitor]". Seluruh isi (fakta kompetitor bertanggal + bersumber)
    ada di App\Support\Kompetitor; placeholder harga Kasir UMKM diisi lewat \App\Support\Kompetitor::fill().
--}}

@php
    /** @var array<string, mixed> $k */
    $fill = fn (string $t): string => \App\Support\Kompetitor::fill($t, $k, $pricing);
    $canonical = url('/'.$k['slug']);
    $title = 'Alternatif '.$k['name'].' untuk UMKM Indonesia | Kasir UMKM';
    $description = $k['description'];
    $jsonFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    $og = \App\Support\LandingSeo::openGraphImage();
    $paid = $pricing['paid'];
    $cost = $k['cost'];

    // Total contoh biaya Kasir UMKM: paket berbayar + outlet tambahan.
    if (! empty($cost['ours_total'])) {
        $oursTotal = $fill($cost['ours_total']);
    } elseif ($paid['monthly_amount'] !== null) {
        $oursTotal = \App\Support\LandingPricing::formatPrice($paid['monthly_amount'] + max(0, $cost['outlets'] - 1) * $paid['outlet_addon_amount']).' / bulan';
    } else {
        $oursTotal = 'Lihat harga';
    }

    // FAQ umum (berlaku untuk semua kompetitor) ditambah FAQ khusus kompetitor.
    $faq = array_merge(
        array_map(fn ($f) => ['q' => $fill($f[0]), 'a' => $fill($f[1])], $k['faq']),
        [
            ['q' => $fill('Bisakah data barang dari {name} dipindahkan?'), 'a' => 'Bisa lewat spreadsheet. Siapkan daftar barang (nama, harga, stok, barcode) dalam file Excel, lalu impor dengan template impor produk di Kasir UMKM. Riwayat transaksi lama tidak ikut dipindahkan.'],
            ['q' => 'Apakah Kasir UMKM punya aplikasi iPhone?', 'a' => 'Kasir UMKM berjalan di browser HP (termasuk iPhone), tablet, dan laptop. Aplikasi yang diinstal dari toko aplikasi saat ini tersedia untuk Android.'],
        ],
    );

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

    // Fitur usaha lokal Kasir UMKM (sama untuk semua halaman alternatif).
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
        .alt-acts { display: flex; flex-wrap: wrap; gap: 10px; }
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
        .alt-table tr:last-child td, .alt-table tr:last-child th { border-bottom: 0; }
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
        .alt-cost .note { margin-top: 10px; font-size: 13px; }

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
        .alt-links { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 16px; }
        .alt-links a { background: #fff; border: 1px solid var(--line); color: var(--ink); font-weight: 600; font-size: 14.5px; padding: 10px 16px; border-radius: 999px; }
        .alt-links a:hover { border-color: var(--primary); color: var(--primary); }
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
            <h1>{{ $k['h1'] }}</h1>
            <p class="lead">{{ $k['lead'] }}</p>
            <div class="alt-acts">
                <a class="btn btn-primary" href="{{ url('/register') }}">Coba Kasir UMKM gratis</a>
                <a class="btn btn-ghost" href="#perbandingan">Lihat perbandingan</a>
            </div>

            <div class="alt-tldr">
                <h2>Ringkasnya</h2>
                <ul>
                    @foreach ($k['tldr'] as $t)
                        <li><strong>{{ $t[0] }}:</strong> {{ $fill($t[1]) }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    </section>

    <section class="alt-sec">
        <div class="wrap">
            <h2>Kenapa pemilik usaha mencari alternatif {{ $k['name'] }}</h2>
            <p class="sub">Alasan yang paling sering muncul saat usaha mulai berkembang.</p>
            <div class="alt-grid three">
                @foreach ($k['reasons'] as $r)
                    <div class="alt-box">
                        <h3>{{ $r[0] }}</h3>
                        <p>{{ $fill($r[1]) }}</p>
                    </div>
                @endforeach
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
                        @foreach ($k['compare'] as $row)
                            <tr>
                                <th scope="row">{{ $row[0] }}</th>
                                <td class="us">{{ $fill($row[1]) }}</td>
                                <td>{{ $fill($row[2]) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="alt-source">
                Sumber data {{ $k['name'] }}:
                @foreach ($k['sources'] as $src)<a href="{{ $src['url'] }}" rel="nofollow noopener" target="_blank">{{ $src['label'] }}</a>{{ $loop->last ? '' : ', ' }}@endforeach,
                dicek {{ $k['checked_label'] }}. {{ $k['source_note'] }} Harga bisa berubah sewaktu-waktu.
            </p>
        </div>
    </section>

    <section class="alt-sec">
        <div class="wrap">
            <h2>{{ $cost['title'] }}</h2>
            <p class="sub">{{ $fill($cost['sub']) }}</p>
            <div class="alt-cost">
                <div class="alt-box">
                    <h3>{{ $cost['theirs_title'] }}</h3>
                    @foreach ($cost['theirs'] as $line)
                        <div class="line"><span>{{ $fill($line[0]) }}</span><span>{{ $fill($line[1]) }}</span></div>
                    @endforeach
                    <div class="total"><span>Total</span><span>{{ $cost['theirs_total'] }}</span></div>
                    <p class="note">{{ $fill($cost['theirs_note']) }}</p>
                </div>
                <div class="alt-box fit">
                    <h3>Kasir UMKM by MaccaPOS</h3>
                    @foreach ($cost['ours'] as $line)
                        <div class="line"><span>{{ $fill($line[0]) }}</span><span>{{ $fill($line[1]) }}</span></div>
                    @endforeach
                    <div class="total"><span>Total</span><span>{{ $oursTotal }}</span></div>
                    <p class="note">{{ $fill($cost['ours_note']) }}</p>
                </div>
            </div>
        </div>
    </section>

    <section class="alt-sec">
        <div class="wrap">
            <h2>Fitur usaha lokal di Kasir UMKM</h2>
            <p class="sub">Fitur khusus jenis usaha yang ada di Kasir UMKM. Bila fitur ini penting bagi Anda, cek juga ketersediaannya langsung di situs {{ $k['name'] }}.</p>
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
                        @foreach ($k['fit_us'] as $item)
                            <li>{{ $fill($item) }}</li>
                        @endforeach
                    </ul>
                </div>
                <div class="alt-box">
                    <h3>{{ $k['name'] }} mungkin lebih cocok bila</h3>
                    <ul>
                        @foreach ($k['strengths'] as $s)
                            <li><strong>{{ $s[0] }}:</strong> {{ $s[1] }}</li>
                        @endforeach
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

            @if (count($others))
                <h2 style="margin-top: 44px">Perbandingan lainnya</h2>
                <div class="alt-links">
                    @foreach ($others as $o)
                        <a href="{{ url('/'.$o['slug']) }}">Alternatif {{ $o['name'] }}</a>
                    @endforeach
                </div>
            @endif

            <p class="alt-disclaimer">
                {{ $k['name'] }} adalah merek milik pemiliknya. Kasir UMKM by MaccaPOS tidak berafiliasi dengan {{ $k['name'] }}.
                Informasi {{ $k['name'] }} diambil dari situs resminya per {{ $k['checked_label'] }}. Jika ada yang tidak akurat,
                hubungi kami di info@umkmkasir.id.
            </p>
        </div>
    </section>
@endsection
