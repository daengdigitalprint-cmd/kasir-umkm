@extends('blog.layout')

{{--
    Halaman "Aplikasi Kasir di [kota]" (SEO lokal).
    Isi tiap kota ditulis manual di App\Support\KotaLayanan, bukan template yang
    hanya mengganti nama kota. Tampilan mengikuti halaman Program Afiliasi
    (kartu-kartu membulat di atas latar abu muda).
--}}

@php
    /** @var array<string, mixed> $page */
    $canonical = url('/'.$page['slug']);
    $indexUrl = url('/'.\App\Support\KotaLayanan::INDEX_PATH);
    $jsonFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    $og = \App\Support\LandingSeo::openGraphImage();
    $kota = $page['name'];

    $schema = [
        '@context' => 'https://schema.org',
        '@graph' => [
            [
                '@type' => 'WebPage',
                '@id' => $canonical.'#webpage',
                'url' => $canonical,
                'name' => $page['title'],
                'description' => $page['description'],
                'inLanguage' => 'id-ID',
                'isPartOf' => ['@type' => 'WebSite', 'name' => 'Kasir UMKM by MaccaPOS', 'url' => url('/')],
                'about' => ['@id' => url('/').'/#software'],
                'breadcrumb' => ['@id' => $canonical.'#breadcrumb'],
                // Wilayah yang dibahas halaman ini. Sengaja bukan LocalBusiness:
                // kami tidak punya kantor di kota ini (alamat usaha hanya di Gowa).
                'spatialCoverage' => [
                    '@type' => 'AdministrativeArea',
                    'name' => $kota,
                    'containedInPlace' => ['@type' => 'AdministrativeArea', 'name' => $page['province']],
                ],
            ],
            [
                '@type' => 'BreadcrumbList',
                '@id' => $canonical.'#breadcrumb',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Beranda', 'item' => url('/')],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => 'Aplikasi Kasir per Kota', 'item' => $indexUrl],
                    ['@type' => 'ListItem', 'position' => 3, 'name' => $kota, 'item' => $canonical],
                ],
            ],
            [
                '@type' => 'FAQPage',
                'mainEntity' => array_map(fn ($f) => [
                    '@type' => 'Question',
                    'name' => $f['q'],
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']],
                ], $page['faq']),
            ],
        ],
    ];
@endphp

@section('title', $page['title'])
@section('brand_tag', $kota)

@section('meta')
    <meta name="description" content="{{ $page['description'] }}">
    <link rel="canonical" href="{{ $canonical }}">
    <meta name="robots" content="index, follow">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Kasir UMKM by MaccaPOS">
    <meta property="og:locale" content="id_ID">
    <meta property="og:title" content="{{ $page['title'] }}">
    <meta property="og:description" content="{{ $page['description'] }}">
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
                <a href="{{ $indexUrl }}">Aplikasi Kasir per Kota</a><span class="s">/</span>
                <span>{{ $kota }}</span>
            </nav>

            <div class="kt-stack">
                {{-- Hero --}}
                <section class="kt-hero">
                    @include('kota.partials.shapes', ['variant' => 'hero'])
                    <div class="kt-hero-body">
                        <span class="kt-pill">
                            @include('blog.partials.icon', ['name' => 'map-pin', 'class' => 'ic-sm'])
                            {{ $kota }}, {{ $page['province'] }}
                        </span>
                        <h1>{{ $page['h1'] }}</h1>
                        <p class="lead">{{ $page['lead'] }}</p>
                        <div class="kt-acts">
                            <a class="kt-btn" href="{{ url('/register') }}">
                                Coba gratis sekarang
                                @include('blog.partials.icon', ['name' => 'arrow-right', 'class' => 'ic-md'])
                            </a>
                            <a class="kt-link" href="#harga">Lihat harga paket</a>
                        </div>
                        <p class="kt-note">Paket {{ $pricing['free']['name'] }} gratis selamanya. Tanpa kartu kredit. Jalan di HP, tablet, dan laptop.</p>
                    </div>
                </section>

                {{-- Jenis usaha khas kota ini --}}
                <section aria-labelledby="usaha">
                    <h2 id="usaha" class="kt-h2">Usaha yang banyak di {{ $kota }}</h2>
                    <p class="kt-sub">{{ $page['usaha_sub'] }}</p>
                    <div class="kt-grid two">
                        @foreach ($page['usaha'] as $usaha)
                            <div class="kt-card">
                                <div class="kt-chip-row">
                                    <span class="kt-chip">@include('blog.partials.icon', ['name' => 'store', 'class' => ''])</span>
                                    <span class="kt-step-label">Usaha {{ $loop->iteration }}</span>
                                </div>
                                <h3>{{ $usaha['title'] }}</h3>
                                <p>{{ $usaha['desc'] }}</p>
                                @if ($usaha['jenis_page'])
                                    <a class="kt-more" href="{{ url('/'.$usaha['jenis_page']['slug']) }}">
                                        Aplikasi kasir {{ \Illuminate\Support\Str::lower($usaha['jenis_page']['name']) }}
                                        @include('blog.partials.icon', ['name' => 'arrow-right', 'class' => 'ic-sm'])
                                    </a>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </section>

                {{-- Tantangan lokal + fitur yang menjawabnya --}}
                <section aria-labelledby="tantangan">
                    <h2 id="tantangan" class="kt-h2">{{ $page['tantangan_title'] }}</h2>
                    <p class="kt-sub">{{ $page['tantangan_sub'] }}</p>
                    <div class="kt-grid three">
                        @foreach ($page['tantangan'] as $t)
                            <div class="kt-card">
                                <div class="kt-chip-row">
                                    <span class="kt-chip">{{ $loop->iteration }}</span>
                                    <span class="kt-step-label">Tantangan</span>
                                </div>
                                <h3>{{ $t['problem'] }}</h3>
                                <p>{{ $t['detail'] }}</p>
                                <p class="kt-solusi"><strong>Solusinya:</strong> {{ $t['solution'] }}</p>
                                @if (! empty($t['paid']))
                                    <span class="kt-plan">Paket {{ $pricing['paid']['name'] }}</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </section>

                {{-- Contoh alur satu hari --}}
                <section class="kt-card roomy" aria-labelledby="alur">
                    <h2 id="alur" class="kt-h2">{{ $page['flow_title'] }}</h2>
                    <p class="kt-sub">{{ $page['flow_sub'] }}</p>
                    <ol class="kt-steps">
                        @foreach ($page['flow'] as $step)
                            <li>
                                <div class="kt-chip-row">
                                    <span class="kt-chip">{{ $loop->iteration }}</span>
                                    <span class="kt-step-label">Langkah {{ $loop->iteration }}</span>
                                </div>
                                <strong>{{ $step['title'] }}</strong>
                                <span class="t">{{ $step['desc'] }}</span>
                            </li>
                        @endforeach
                    </ol>
                </section>

                {{-- Harga --}}
                <section class="kt-card roomy" id="harga" aria-labelledby="harga-judul">
                    <h2 id="harga-judul" class="kt-h2">Harga aplikasi kasir di {{ $kota }}</h2>
                    <p class="kt-sub">Harganya sama di semua kota. Mulai dari paket gratis, lalu pindah ke paket berbayar saat usaha Anda membutuhkan fitur lanjutannya.</p>
                    @include('kota.partials.harga')
                    <p class="kt-fine">Fitur bertanda "Paket {{ $pricing['paid']['name'] }}" di halaman ini termasuk dalam paket berbayar.</p>
                </section>

                {{-- Bantuan (jujur: tanpa kantor cabang) --}}
                <section class="kt-card roomy" aria-labelledby="bantuan">
                    <h2 id="bantuan" class="kt-h2">Bantuan untuk pengguna di {{ $kota }}</h2>
                    <p>{{ $page['bantuan'] }}</p>
                    <div class="kt-rows">
                        <div class="kt-row">
                            <span class="kt-chip">@include('blog.partials.icon', ['name' => 'clock', 'class' => ''])</span>
                            <span>
                                <span class="k">Jam layanan</span>
                                <span class="v">Senin – Jumat, {{ $jamLayanan }}</span>
                            </span>
                        </div>
                        <a class="kt-row" href="https://wa.me/6285242766676" target="_blank" rel="noopener">
                            <span class="kt-chip">@include('blog.partials.icon', ['name' => 'whatsapp', 'class' => ''])</span>
                            <span>
                                <span class="k">WhatsApp</span>
                                <span class="v">+62 852-4276-6676</span>
                            </span>
                        </a>
                        <div class="kt-row">
                            <span class="kt-chip">@include('blog.partials.icon', ['name' => 'map-pin', 'class' => ''])</span>
                            <span>
                                <span class="k">Alamat tim</span>
                                <span class="v">Jl. Benteng Somba Opu, Tanjung Permai No 30 Gowa, Sulawesi Selatan</span>
                            </span>
                        </div>
                    </div>
                </section>

                {{-- FAQ --}}
                <section class="kt-card roomy" id="faq" aria-labelledby="faq-judul">
                    <h2 id="faq-judul" class="kt-h2 alone">Pertanyaan seputar aplikasi kasir di {{ $kota }}</h2>
                    <div class="kt-faq">
                        @foreach ($page['faq'] as $f)
                            <details @if ($loop->first) open @endif>
                                <summary>{{ $f['q'] }}</summary>
                                <p>{{ $f['a'] }}</p>
                            </details>
                        @endforeach
                    </div>
                    <a class="kt-btn flat" style="margin-top: 20px" href="{{ url('/register') }}">
                        Daftar gratis
                        @include('blog.partials.icon', ['name' => 'arrow-right', 'class' => 'ic-md'])
                    </a>
                </section>

                {{-- Artikel blog terkait --}}
                @if ($articles->isNotEmpty())
                    <section aria-labelledby="bacaan">
                        <h2 id="bacaan" class="kt-h2 alone">Bacaan untuk pemilik usaha di {{ $kota }}</h2>
                        <div class="kt-grid two">
                            @foreach ($articles as $article)
                                <a class="kt-city" href="{{ $article->publicUrl() }}">
                                    <h3 style="margin-top: 0">{{ $article->title }}</h3>
                                    @if ($article->excerpt)
                                        <p>{{ \Illuminate\Support\Str::limit(strip_tags((string) $article->excerpt), 140) }}</p>
                                    @endif
                                    <span class="kt-more">Baca artikel @include('blog.partials.icon', ['name' => 'arrow-right', 'class' => 'ic-sm'])</span>
                                </a>
                            @endforeach
                        </div>
                    </section>
                @endif

                {{-- Tautan internal: kota lain + jenis usaha --}}
                <section class="kt-card roomy" aria-labelledby="kota-lain">
                    <h2 id="kota-lain" class="kt-h2">Aplikasi kasir di kota lain</h2>
                    <div class="kt-links">
                        @foreach ($others as $other)
                            <a href="{{ url('/'.$other['slug']) }}">Aplikasi kasir {{ $other['name'] }}</a>
                        @endforeach
                        <a class="all" href="{{ $indexUrl }}">Semua kota</a>
                    </div>
                    <p class="kt-links-label">Per jenis usaha</p>
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
