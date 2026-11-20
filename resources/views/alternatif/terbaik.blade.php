@extends('blog.layout')

{{--
    Halaman hub "Aplikasi Kasir Terbaik untuk UMKM": ringkasan semua aplikasi di App\Support\Kompetitor
    + tautan ke setiap halaman alternatif. Disusun per kebutuhan, bukan peringkat sepihak, dan
    menyebut terang bahwa halaman ini dibuat oleh Kasir UMKM.
--}}

@php
    $ctx = ['name' => 'Kasir UMKM'];
    $fill = fn (string $t): string => \App\Support\Kompetitor::fill($t, $ctx, $pricing);
    $year = substr($checkedAt, 0, 4);
    $count = count($apps);
    $canonical = url('/aplikasi-kasir-terbaik');
    $title = $count.' Aplikasi Kasir Terbaik untuk UMKM '.$year.' | Kasir UMKM';
    $description = 'Perbandingan '.$count.' aplikasi kasir untuk UMKM Indonesia: paket gratis, harga, cara hitung biaya, dan cocok untuk usaha apa. Data dari situs resmi.';
    $jsonFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    $og = \App\Support\LandingSeo::openGraphImage();
    $appUrl = fn (array $a): string => $a['slug'] ? url('/'.$a['slug']) : url('/');

    // Rekomendasi per kebutuhan (kunci = key di Kompetitor::summaries()).
    $byNeed = [
        ['need' => 'Ingin mulai gratis tanpa batas waktu', 'keys' => ['kasir-umkm', 'kasir-pintar', 'kasir-aja', 'loyverse', 'ireap'], 'note' => 'Pawoon juga punya paket gratis, tetapi dibatasi 7 transaksi per hari.'],
        ['need' => 'Siap bayar tahunan dengan biaya paling hemat', 'keys' => ['qasir', 'ireap', 'kasir-pintar'], 'note' => 'Cocok untuk kebutuhan kasir umum dengan satu perangkat atau satu outlet.'],
        ['need' => 'Punya banyak staf atau beberapa perangkat kasir', 'keys' => ['kasir-umkm', 'qasir'], 'note' => 'Perhatikan aplikasi yang menghitung biaya per karyawan atau per perangkat.'],
        ['need' => 'Butuh integrasi ojol, pembayaran digital, atau akuntansi', 'keys' => ['majoo', 'esb', 'pawoon', 'moka', 'olsera', 'accurate-pos'], 'note' => 'Integrasi biasanya ada di paket menengah ke atas.'],
        ['need' => 'Apotek, laundry, atau barbershop', 'keys' => ['kasir-umkm'], 'note' => 'Mode apotek (batch, kedaluwarsa, nomor resep), order laundry, dan komisi kapster.'],
    ];
    $appByKey = collect($apps)->keyBy('key');

    $faq = [
        ['q' => 'Apa aplikasi kasir terbaik untuk UMKM?', 'a' => 'Tergantung kebutuhan. Untuk mulai gratis, pilih aplikasi dengan paket gratis tanpa batas waktu seperti Kasir UMKM, Kasir Pintar, KasirAja, Loyverse, atau iReap Lite. Untuk integrasi ojol dan pembayaran digital, Majoo, ESB, Pawoon, Moka, dan Olsera mencantumkannya di paket mereka.'],
        ['q' => 'Aplikasi kasir apa yang gratis selamanya?', 'a' => 'Kasir UMKM (paket Starter), Kasir Pintar Free, KasirAja Gratis, Loyverse POS, dan iREAP POS Lite punya versi gratis. Pawoon punya paket gratis dengan batas 7 transaksi per hari, dan ESB Basic mulai dari Rp0 dengan syarat & ketentuan. Moka, Majoo, Qasir, Olsera, dan Accurate POS mencantumkan uji coba gratis.'],
        ['q' => 'Aplikasi kasir mana yang paling murah?', 'a' => 'Untuk bayar tahunan, Qasir Pro (Rp699.000 per 12 bulan), iReap PRO (Rp500.000 per 12 bulan per perangkat), dan Kasir Pintar Pro (Rp666.000 per tahun) termasuk yang paling hemat. Untuk bayar bulanan per outlet tanpa biaya per karyawan atau perangkat, paket '.$pricing['paid']['name'].' Kasir UMKM '.($pricing['paid']['monthly_label'] ?: '').' per bulan.'],
        ['q' => 'Apa yang perlu diperhatikan saat memilih aplikasi kasir?', 'a' => 'Perhatikan cara hitung biayanya (per outlet, per perangkat, atau per karyawan), batas paket gratis, apakah bisa bayar bulanan, perangkat yang didukung, dan fitur khusus jenis usaha Anda.'],
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
                'dateModified' => $checkedAt,
                'isPartOf' => ['@type' => 'WebSite', 'name' => 'Kasir UMKM by MaccaPOS', 'url' => url('/')],
            ],
            [
                '@type' => 'ItemList',
                'name' => 'Aplikasi kasir untuk UMKM',
                'itemListElement' => array_map(fn ($a, $i) => [
                    '@type' => 'ListItem',
                    'position' => $i + 1,
                    'name' => $a['name'],
                    'url' => $appUrl($a),
                ], $apps, array_keys($apps)),
            ],
            [
                '@type' => 'BreadcrumbList',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Beranda', 'item' => url('/')],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => 'Aplikasi Kasir Terbaik untuk UMKM', 'item' => $canonical],
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
        .tb-hero { background: var(--primary-muted); border-bottom: 1px solid var(--line); padding: 8px 0 44px; }
        .tb-hero h1 { font-size: clamp(30px, 4.6vw, 46px); margin: 14px 0 14px; max-width: 900px; }
        .tb-hero .lead { color: var(--muted); font-size: 18px; margin: 0 0 18px; max-width: 780px; }
        .tb-disclosure { background: #fff; border: 1px solid var(--line); border-left: 4px solid var(--primary); border-radius: 12px; padding: 14px 18px; font-size: 14.5px; color: #3b3a4a; max-width: 860px; }

        .tb-sec { padding: 52px 0 0; }
        .tb-sec h2 { font-size: clamp(23px, 3vw, 32px); margin: 0 0 8px; }
        .tb-sec .sub { color: var(--muted); margin: 0 0 22px; max-width: 760px; }

        .tb-criteria { display: grid; gap: 14px; grid-template-columns: minmax(0, 1fr); }
        @media (min-width: 720px) { .tb-criteria { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (min-width: 1024px) { .tb-criteria { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        .tb-box { background: #fff; border: 1px solid var(--line); border-radius: 18px; padding: 20px 22px; }
        .tb-box h3 { font-size: 17px; margin: 0 0 6px; }
        .tb-box p { margin: 0; color: #4a4860; font-size: 15px; line-height: 1.6; }

        .tb-table-wrap { overflow-x: auto; border: 1px solid var(--line); border-radius: 18px; background: #fff; }
        .tb-table { width: 100%; border-collapse: collapse; min-width: 860px; font-size: 14.5px; }
        .tb-table th, .tb-table td { text-align: left; padding: 13px 16px; border-bottom: 1px solid var(--line); vertical-align: top; line-height: 1.5; }
        .tb-table thead th { background: var(--container); font-family: var(--font-display); font-size: 13.5px; white-space: nowrap; }
        .tb-table tbody th { font-family: var(--font-display); font-size: 15px; min-width: 150px; }
        .tb-table tr.us th, .tb-table tr.us td { background: #faf9ff; }
        .tb-table tr:last-child th, .tb-table tr:last-child td { border-bottom: 0; }
        .tb-table a { font-weight: 600; white-space: nowrap; }
        .tb-note { font-size: 13px; color: var(--muted); margin: 10px 0 0; }

        .tb-needs { display: grid; gap: 14px; }
        .tb-need { background: #fff; border: 1px solid var(--line); border-radius: 18px; padding: 18px 22px; }
        .tb-need h3 { font-size: 17px; margin: 0 0 10px; }
        .tb-need .apps { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 8px; }
        .tb-need .apps a { background: var(--primary-soft); color: var(--primary-dark); font-weight: 700; font-size: 13.5px; padding: 5px 12px; border-radius: 999px; }
        .tb-need p { margin: 0; color: var(--muted); font-size: 14.5px; }

        .tb-cards { display: grid; gap: 18px; grid-template-columns: minmax(0, 1fr); }
        @media (min-width: 860px) { .tb-cards { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        .tb-card { background: #fff; border: 1px solid var(--line); border-radius: 20px; padding: 22px 24px; display: flex; flex-direction: column; }
        .tb-card.us { border-color: #c3b9fb; background: var(--primary-muted); }
        .tb-card h3 { font-size: 20px; margin: 0 0 4px; display: flex; gap: 10px; align-items: baseline; }
        .tb-card h3 .no { color: var(--primary); font-size: 16px; }
        .tb-card dl { margin: 12px 0 0; display: grid; gap: 8px; }
        .tb-card dt { font-size: 12px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); }
        .tb-card dd { margin: 0; color: #3b3a4a; font-size: 15px; line-height: 1.55; }
        .tb-card .more { margin-top: 14px; font-weight: 700; font-size: 14.5px; }

        .faq-item { border: 1px solid var(--line); border-radius: 16px; padding: 16px 20px; margin-bottom: 12px; background: #fff; }
        .faq-item[open] { border-color: #c3b9fb; background: var(--primary-muted); }
        .faq-item summary { font-family: var(--font-display); font-weight: 700; font-size: 16px; cursor: pointer; list-style: none; display: flex; justify-content: space-between; gap: 14px; }
        .faq-item summary::-webkit-details-marker { display: none; }
        .faq-item summary::after { content: '+'; color: var(--primary); font-size: 22px; line-height: 1; flex: none; }
        .faq-item[open] summary::after { content: '−'; }
        .faq-item p { margin: 12px 0 0; color: #4a4860; font-size: 15.5px; }
        .tb-disclaimer { margin-top: 30px; font-size: 13px; color: var(--muted); }
    </style>
@endsection

@section('content')
    <section class="tb-hero">
        <div class="wrap">
            <nav class="crumbs" aria-label="Breadcrumb">
                <a href="{{ url('/') }}">Beranda</a><span class="s">/</span>
                <span>Aplikasi Kasir Terbaik untuk UMKM</span>
            </nav>
            <span class="pill" style="margin-top: 18px">Diperbarui {{ $checkedLabel }}</span>
            <h1>{{ $count }} Aplikasi Kasir Terbaik untuk UMKM {{ $year }}: Harga &amp; Perbandingan</h1>
            <p class="lead">
                Tidak ada satu aplikasi kasir yang paling cocok untuk semua usaha. Halaman ini membandingkan {{ $count }} aplikasi kasir
                yang banyak dipakai UMKM Indonesia dari sisi paket gratis, harga, cara hitung biaya, dan jenis usaha yang paling cocok,
                supaya Anda bisa memilih sesuai kebutuhan.
            </p>
            <div class="tb-disclosure">
                <strong>Keterbukaan:</strong> halaman ini dibuat oleh tim Kasir UMKM by MaccaPOS, salah satu aplikasi yang dibandingkan.
                Data aplikasi lain diambil dari situs resmi masing-masing per {{ $checkedLabel }}, termasuk bagian di mana mereka lebih unggul
                atau lebih murah dari kami.
            </div>
        </div>
    </section>

    <section class="tb-sec">
        <div class="wrap">
            <h2>Cara memilih aplikasi kasir untuk UMKM</h2>
            <p class="sub">Lima hal yang paling memengaruhi biaya dan kenyamanan sehari-hari.</p>
            <div class="tb-criteria">
                <div class="tb-box"><h3>1. Paket gratis atau uji coba?</h3><p>Paket gratis bisa dipakai terus. Uji coba hanya 7–30 hari, setelah itu harus berlangganan.</p></div>
                <div class="tb-box"><h3>2. Cara hitung biaya</h3><p>Per outlet, per perangkat, atau per karyawan. Toko dengan banyak kasir bisa membayar jauh lebih mahal di model per perangkat atau per karyawan.</p></div>
                <div class="tb-box"><h3>3. Bulanan atau tahunan</h3><p>Paket tahunan biasanya lebih hemat, tetapi harus dibayar di muka. Bayar bulanan lebih ringan untuk usaha baru.</p></div>
                <div class="tb-box"><h3>4. Perangkat yang dipakai</h3><p>Ada aplikasi khusus Android, ada yang bisa di iOS dan Windows, dan ada yang berjalan di browser apa pun.</p></div>
                <div class="tb-box"><h3>5. Fitur jenis usaha</h3><p>Restoran butuh meja dan layar dapur, apotek butuh batch dan kedaluwarsa, laundry butuh status cucian.</p></div>
            </div>
        </div>
    </section>

    <section class="tb-sec" id="perbandingan">
        <div class="wrap">
            <h2>Tabel perbandingan {{ $count }} aplikasi kasir</h2>
            <p class="sub">Geser ke samping di HP untuk melihat semua kolom.</p>
            <div class="tb-table-wrap">
                <table class="tb-table">
                    <thead>
                        <tr>
                            <th scope="col">Aplikasi</th>
                            <th scope="col">Paket gratis</th>
                            <th scope="col">Harga berbayar mulai</th>
                            <th scope="col">Cara hitung biaya</th>
                            <th scope="col">Detail</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($apps as $a)
                            <tr @class(['us' => $a['key'] === 'kasir-umkm'])>
                                <th scope="row">{{ $a['name'] }}</th>
                                <td>{{ $fill($a['free']) }}</td>
                                <td>{{ $fill($a['price']) }}</td>
                                <td>{{ $fill($a['billing']) }}</td>
                                <td>
                                    @if ($a['slug'])
                                        <a href="{{ $appUrl($a) }}">Bandingkan &rarr;</a>
                                    @else
                                        <a href="{{ url('/register') }}">Coba gratis &rarr;</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="tb-note">Harga dari situs resmi masing-masing per {{ $checkedLabel }} dan bisa berubah. Sumber lengkap ada di halaman perbandingan tiap aplikasi.</p>
        </div>
    </section>

    <section class="tb-sec">
        <div class="wrap">
            <h2>Rekomendasi sesuai kebutuhan</h2>
            <div class="tb-needs" style="margin-top: 20px">
                @foreach ($byNeed as $n)
                    <div class="tb-need">
                        <h3>{{ $n['need'] }}</h3>
                        <div class="apps">
                            @foreach ($n['keys'] as $key)
                                @php $a = $appByKey[$key]; @endphp
                                <a href="{{ $a['slug'] ? $appUrl($a) : url('/register') }}">{{ $a['name'] }}</a>
                            @endforeach
                        </div>
                        <p>{{ $n['note'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="tb-sec">
        <div class="wrap">
            <h2>Ulasan singkat tiap aplikasi</h2>
            <p class="sub">Urutan bukan peringkat. Kasir UMKM ditaruh pertama karena halaman ini milik kami.</p>
            <div class="tb-cards">
                @foreach ($apps as $i => $a)
                    <article @class(['tb-card', 'us' => $a['key'] === 'kasir-umkm'])>
                        <h3><span class="no">{{ $i + 1 }}.</span> {{ $a['name'] }}</h3>
                        <dl>
                            <dt>Cocok untuk</dt><dd>{{ $fill($a['best_for']) }}</dd>
                            <dt>Harga</dt><dd>{{ $fill($a['price']) }}</dd>
                            <dt>Pertimbangkan</dt><dd>{{ $fill($a['consider']) }}</dd>
                        </dl>
                        @if ($a['slug'])
                            <a class="more" href="{{ $appUrl($a) }}">Bandingkan {{ $a['name'] }} dengan Kasir UMKM &rarr;</a>
                        @else
                            <a class="more" href="{{ url('/register') }}">Coba Kasir UMKM gratis &rarr;</a>
                        @endif
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    <section class="tb-sec" id="faq">
        <div class="wrap">
            <h2>Pertanyaan seputar aplikasi kasir UMKM</h2>
            <div style="margin-top: 20px">
                @foreach ($faq as $f)
                    <details class="faq-item" @if ($loop->first) open @endif>
                        <summary>{{ $f['q'] }}</summary>
                        <p>{{ $f['a'] }}</p>
                    </details>
                @endforeach
            </div>
            <p class="tb-disclaimer">
                Semua merek adalah milik pemiliknya masing-masing. Kasir UMKM by MaccaPOS tidak berafiliasi dengan aplikasi lain di halaman ini.
                Jika ada informasi yang tidak akurat, hubungi kami di info@umkmkasir.id.
            </p>
        </div>
    </section>
@endsection
