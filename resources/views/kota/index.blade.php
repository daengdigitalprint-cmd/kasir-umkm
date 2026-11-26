@extends('blog.layout')

{{--
    Indeks halaman kota (SEO lokal): /aplikasi-kasir/kota.
    Menaut ke setiap halaman kota di App\Support\KotaLayanan, dikelompokkan per
    provinsi. Tampilan mengikuti halaman Program Afiliasi.
--}}

@php
    /** @var array<string, list<array<string, mixed>>> $groups */
    $canonical = url('/'.\App\Support\KotaLayanan::INDEX_PATH);
    $jsonFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    $og = \App\Support\LandingSeo::openGraphImage();
    $title = 'Aplikasi Kasir per Kota di Indonesia | Kasir UMKM';
    $description = 'Aplikasi kasir Kasir UMKM untuk usaha di berbagai kota: contoh jenis usaha, tantangan kasir, dan alur harian yang khas tiap kota. Bisa dipakai di seluruh Indonesia.';
    $jamLayanan = \App\Support\KotaLayanan::jamLayanan('WITA');

    $faq = [
        [
            'q' => 'Apakah Kasir UMKM hanya bisa dipakai di kota yang ada di daftar ini?',
            'a' => 'Tidak. Kasir UMKM berjalan di browser dan aplikasi Android, jadi bisa dipakai di kota mana pun di Indonesia. Daftar ini hanya memuat kota yang sudah kami tuliskan contoh usahanya.',
        ],
        [
            'q' => 'Di mana kantor Kasir UMKM?',
            'a' => 'Alamat kami hanya satu: Jl. Benteng Somba Opu, Tanjung Permai No 30 Gowa, Sulawesi Selatan. Kami tidak punya kantor cabang di kota lain, dan bantuan diberikan lewat WhatsApp.',
        ],
        [
            'q' => 'Apakah perlu teknisi datang untuk memasang aplikasinya?',
            'a' => 'Tidak. Anda mendaftar dan memasang sendiri dari HP, tablet, atau laptop. Bila ada yang membingungkan, tim kami membantu lewat WhatsApp pada jam layanan.',
        ],
    ];

    $position = 0;
    $itemList = [];
    foreach ($groups as $kotaList) {
        foreach ($kotaList as $k) {
            $itemList[] = [
                '@type' => 'ListItem',
                'position' => ++$position,
                'name' => 'Aplikasi kasir '.$k['name'],
                'url' => url('/'.$k['slug']),
            ];
        }
    }

    $schema = [
        '@context' => 'https://schema.org',
        '@graph' => [
            [
                '@type' => 'CollectionPage',
                '@id' => $canonical.'#collection',
                'url' => $canonical,
                'name' => $title,
                'description' => $description,
                'inLanguage' => 'id-ID',
                'isPartOf' => ['@type' => 'WebSite', 'name' => 'Kasir UMKM by MaccaPOS', 'url' => url('/')],
                'about' => ['@id' => url('/').'/#software'],
                'breadcrumb' => ['@id' => $canonical.'#breadcrumb'],
                'mainEntity' => ['@id' => $canonical.'#daftar-kota'],
            ],
            [
                '@type' => 'ItemList',
                '@id' => $canonical.'#daftar-kota',
                'numberOfItems' => count($itemList),
                'itemListElement' => $itemList,
            ],
            [
                '@type' => 'BreadcrumbList',
                '@id' => $canonical.'#breadcrumb',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Beranda', 'item' => url('/')],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => 'Aplikasi Kasir per Kota', 'item' => $canonical],
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
@section('brand_tag', 'Per Kota')

@section('meta')
    <meta name="description" content="{{ $description }}">
    <link rel="canonical" href="{{ $canonical }}">
    <meta name="robots" content="index, follow">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Kasir UMKM by MaccaPOS">
    <meta property="og:locale" content="id_ID">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $canonical }}">
    <meta property="og:image" content="{{ $og['url'] }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:image" content="{{ $og['url'] }}">
    <script type="application/ld+json">{!! json_encode($schema, $jsonFlags) !!}</script>
    @include('kota.partials.style')
@endsection

@section('content')
    <div class="kt-page">
        <div class="kt-wrap">
            <nav class="crumbs" aria-label="Breadcrumb">
                <a href="{{ url('/') }}">Beranda</a><span class="s">/</span>
                <span>Aplikasi Kasir per Kota</span>
            </nav>

            <div class="kt-stack">
                {{-- Hero --}}
                <section class="kt-hero">
                    @include('kota.partials.shapes', ['variant' => 'hero'])
                    <div class="kt-hero-body">
                        <span class="kt-pill">
                            @include('blog.partials.icon', ['name' => 'map-pin', 'class' => 'ic-sm'])
                            {{ $total }} kota, bisa dipakai di seluruh Indonesia
                        </span>
                        <h1>Aplikasi Kasir untuk UMKM di Berbagai Kota</h1>
                        <p class="lead">
                            Warkop di Makassar, toko roti di Maros, dan kedai kopi di Jakarta tidak mencatat penjualan dengan cara yang sama.
                            Pilih kota Anda untuk melihat jenis usaha, tantangan kasir, dan contoh alur harian yang khas di sana.
                        </p>
                        <div class="kt-acts">
                            <a class="kt-btn" href="{{ url('/register') }}">
                                Coba gratis sekarang
                                @include('blog.partials.icon', ['name' => 'arrow-right', 'class' => 'ic-md'])
                            </a>
                            <a class="kt-link" href="#daftar-kota">Lihat daftar kota</a>
                        </div>
                        <p class="kt-note">Paket {{ $pricing['free']['name'] }} gratis selamanya. Tanpa kartu kredit. Jalan di HP, tablet, dan laptop.</p>
                    </div>
                </section>

                {{-- Daftar kota per provinsi --}}
                <div id="daftar-kota" class="kt-stack">
                    @foreach ($groups as $province => $kotaList)
                        <section aria-labelledby="prov-{{ \Illuminate\Support\Str::slug($province) }}">
                            <div class="kt-prov">
                                <h2 id="prov-{{ \Illuminate\Support\Str::slug($province) }}" class="kt-h2" style="margin: 0">{{ $province }}</h2>
                                <span>{{ count($kotaList) }} kota</span>
                            </div>
                            <div class="kt-grid two">
                                @foreach ($kotaList as $k)
                                    <a class="kt-city" href="{{ url('/'.$k['slug']) }}">
                                        <div class="kt-chip-row">
                                            <span class="kt-chip">@include('blog.partials.icon', ['name' => 'map-pin', 'class' => ''])</span>
                                            <span class="kt-step-label">{{ $k['province'] }}</span>
                                        </div>
                                        <h3>Aplikasi kasir {{ $k['name'] }}</h3>
                                        <p>{{ $k['tagline'] }}</p>
                                        <span class="kt-more">
                                            Lihat halaman {{ $k['name'] }}
                                            @include('blog.partials.icon', ['name' => 'arrow-right', 'class' => 'ic-sm'])
                                        </span>
                                    </a>
                                @endforeach
                            </div>
                        </section>
                    @endforeach
                </div>

                {{-- Kota belum ada + harga --}}
                <section class="kt-card roomy" aria-labelledby="belum-ada">
                    <h2 id="belum-ada" class="kt-h2">Kota Anda belum ada di daftar?</h2>
                    <p class="kt-sub">
                        Aplikasinya tetap bisa dipakai. Kasir UMKM berjalan di browser dan aplikasi Android, jadi tidak bergantung pada lokasi toko.
                        Harganya juga sama di semua kota.
                    </p>
                    @include('kota.partials.harga')
                </section>

                {{-- Yang sama di semua kota --}}
                <section aria-labelledby="sama">
                    <h2 id="sama" class="kt-h2 alone">Yang sama di semua kota</h2>
                    <div class="kt-grid three">
                        <div class="kt-card">
                            <div class="kt-chip-row">
                                <span class="kt-chip">@include('blog.partials.icon', ['name' => 'check', 'class' => ''])</span>
                                <span class="kt-step-label">Pemasangan</span>
                            </div>
                            <h3>Pasang sendiri dari HP</h3>
                            <p>Daftar, isi barang atau menu, lalu mulai mencatat penjualan. Tidak perlu menunggu teknisi datang ke toko.</p>
                        </div>
                        <div class="kt-card">
                            <div class="kt-chip-row">
                                <span class="kt-chip">@include('blog.partials.icon', ['name' => 'whatsapp', 'class' => ''])</span>
                                <span class="kt-step-label">Bantuan</span>
                            </div>
                            <h3>Dibantu lewat WhatsApp</h3>
                            <p>Tim kami berbasis di Gowa, Sulawesi Selatan, dan menjawab pertanyaan pada Senin – Jumat, {{ $jamLayanan }}.</p>
                        </div>
                        <div class="kt-card">
                            <div class="kt-chip-row">
                                <span class="kt-chip">@include('blog.partials.icon', ['name' => 'receipt', 'class' => ''])</span>
                                <span class="kt-step-label">Saat offline</span>
                            </div>
                            <h3>Tetap mencatat tanpa internet</h3>
                            <p>Penjualan di kasir tetap bisa dicatat saat sinyal hilang, lalu tersinkron otomatis ketika internet tersambung lagi.</p>
                        </div>
                    </div>
                </section>

                {{-- FAQ --}}
                <section class="kt-card roomy" id="faq" aria-labelledby="faq-judul">
                    <h2 id="faq-judul" class="kt-h2 alone">Pertanyaan seputar wilayah layanan</h2>
                    <div class="kt-faq">
                        @foreach ($faq as $f)
                            <details @if ($loop->first) open @endif>
                                <summary>{{ $f['q'] }}</summary>
                                <p>{{ $f['a'] }}</p>
                            </details>
                        @endforeach
                    </div>
                </section>

                {{-- Tautan ke halaman jenis usaha --}}
                <section class="kt-card roomy" aria-labelledby="jenis-usaha">
                    <h2 id="jenis-usaha" class="kt-h2">Cari berdasarkan jenis usaha</h2>
                    <div class="kt-links">
                        @foreach ($jenisUsaha as $ju)
                            <a href="{{ url('/'.$ju['slug']) }}">Aplikasi kasir {{ \Illuminate\Support\Str::lower($ju['name']) }}</a>
                        @endforeach
                    </div>
                </section>
            </div>
        </div>
    </div>
@endsection
