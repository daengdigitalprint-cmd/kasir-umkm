@extends('blog.layout')

{{--
    Halaman "Aplikasi Kasir untuk [jenis usaha]" (SEO per jenis usaha).
    Konten tiap halaman ditulis manual di App\Support\JenisUsaha, bukan template
    yang hanya mengganti nama usaha, supaya tiap halaman punya nilai sendiri.
--}}

@php
    /** @var array<string, mixed> $page */
    $canonical = url('/'.$page['slug']);
    $jsonFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    $og = \App\Support\LandingSeo::openGraphImage();

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
            ],
            [
                '@type' => 'BreadcrumbList',
                '@id' => $canonical.'#breadcrumb',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Beranda', 'item' => url('/')],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => 'Aplikasi Kasir '.$page['name'], 'item' => $canonical],
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
@section('brand_tag', 'Jenis Usaha')

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

    <style>
        .ju-hero { background: var(--primary-muted); border-bottom: 1px solid var(--line); padding: 8px 0 48px; }
        .ju-hero-grid { display: grid; gap: 32px; align-items: center; padding-top: 22px; }
        @media (min-width: 960px) { .ju-hero-grid { grid-template-columns: minmax(0, 1.25fr) minmax(0, 1fr); } }
        .ju-hero h1 { font-size: clamp(30px, 4.6vw, 48px); margin: 12px 0 14px; }
        .ju-hero .lead { color: var(--muted); font-size: 18px; margin: 0 0 24px; max-width: 640px; }
        .ju-acts { display: flex; flex-wrap: wrap; gap: 10px; }
        .ju-note { margin: 14px 0 0; font-size: 13.5px; color: var(--muted); }
        .ju-cocok { background: #fff; border: 1px solid var(--line); border-radius: 20px; padding: 24px; }
        .ju-cocok h2 { font-size: 17px; margin: 0 0 12px; }
        .ju-cocok ul { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
        .ju-cocok li { display: flex; gap: 10px; align-items: flex-start; font-size: 15px; color: #3b3a4a; line-height: 1.55; }
        .ju-cocok li::before { content: ''; flex: none; width: 8px; height: 8px; margin-top: 8px; border-radius: 999px; background: var(--lime); }

        .ju-sec { padding: 56px 0 0; }
        .ju-sec > .wrap > h2 { font-size: clamp(24px, 3.2vw, 34px); margin: 0 0 8px; }
        .ju-sec .sub { color: var(--muted); margin: 0 0 26px; max-width: 720px; }

        .ju-grid { display: grid; gap: 18px; grid-template-columns: minmax(0, 1fr); }
        @media (min-width: 680px) { .ju-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (min-width: 1024px) { .ju-grid.three { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        .ju-box { background: #fff; border: 1px solid var(--line); border-radius: 20px; padding: 24px; }
        .ju-box h3 { font-size: 18px; margin: 0 0 8px; }
        .ju-box p { margin: 0; color: #4a4860; font-size: 15px; line-height: 1.65; }
        .ju-problem .before { font-size: 12px; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; color: #c2410c; margin: 0 0 6px; }
        .ju-problem .after { margin-top: 14px; padding-top: 14px; border-top: 1px dashed var(--line); }
        .ju-problem .after strong { color: var(--primary-dark); }
        .ju-plan { display: inline-block; margin-top: 12px; font-size: 12px; font-weight: 700; color: var(--primary-dark); background: var(--primary-soft); padding: 3px 10px; border-radius: 999px; }

        .ju-steps { counter-reset: s; list-style: none; margin: 0; padding: 0; display: grid; gap: 14px; }
        .ju-steps li { counter-increment: s; display: flex; gap: 16px; align-items: flex-start; background: #fff; border: 1px solid var(--line); border-radius: 18px; padding: 18px 20px; }
        .ju-steps li::before { content: counter(s); flex: none; width: 34px; height: 34px; border-radius: 10px; background: var(--primary); color: #fff; font-family: var(--font-display); font-weight: 800; display: flex; align-items: center; justify-content: center; }
        .ju-steps strong { display: block; font-family: var(--font-display); font-size: 16.5px; margin-bottom: 2px; }
        .ju-steps span { color: #4a4860; font-size: 15px; line-height: 1.6; }

        .ju-plans { display: grid; gap: 18px; grid-template-columns: minmax(0, 1fr); }
        @media (min-width: 760px) { .ju-plans { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        .ju-plans .ju-box.paid { border-color: #c3b9fb; background: var(--primary-muted); }
        .ju-price { font-family: var(--font-display); font-weight: 800; font-size: 30px; color: var(--ink); margin: 6px 0 10px; }
        .ju-price small { font-size: 15px; font-weight: 600; color: var(--muted); }

        .faq-item { border: 1px solid var(--line); border-radius: 16px; padding: 16px 20px; margin-bottom: 12px; background: #fff; }
        .faq-item[open] { border-color: #c3b9fb; background: var(--primary-muted); }
        .faq-item summary { font-family: var(--font-display); font-weight: 700; font-size: 16px; cursor: pointer; list-style: none; display: flex; justify-content: space-between; gap: 14px; align-items: flex-start; }
        .faq-item summary::-webkit-details-marker { display: none; }
        .faq-item summary::after { content: '+'; color: var(--primary); font-size: 22px; line-height: 1; flex: none; }
        .faq-item[open] summary::after { content: '−'; }
        .faq-item p { margin: 12px 0 0; color: #4a4860; font-size: 15.5px; }

        .ju-links { display: flex; flex-wrap: wrap; gap: 10px; }
        .ju-links a { background: #fff; border: 1px solid var(--line); color: var(--ink); font-weight: 600; font-size: 14.5px; padding: 10px 16px; border-radius: 999px; }
        .ju-links a:hover { border-color: var(--primary); color: var(--primary); }
    </style>
@endsection

@section('content')
    <section class="ju-hero">
        <div class="wrap">
            <nav class="crumbs" aria-label="Breadcrumb">
                <a href="{{ url('/') }}">Beranda</a><span class="s">/</span>
                <span>Aplikasi Kasir {{ $page['name'] }}</span>
            </nav>
            <div class="ju-hero-grid">
                <div>
                    <span class="pill">{{ $page['eyebrow'] }}</span>
                    <h1>{{ $page['h1'] }}</h1>
                    <p class="lead">{{ $page['lead'] }}</p>
                    <div class="ju-acts">
                        <a class="btn btn-primary" href="{{ url('/register') }}">Coba gratis sekarang</a>
                        <a class="btn btn-ghost" href="{{ url('/panduan') }}">Lihat panduan</a>
                    </div>
                    <p class="ju-note">Paket {{ $pricing['free']['name'] }} gratis selamanya. Tanpa kartu kredit. Jalan di HP, tablet, dan laptop.</p>
                </div>
                <div class="ju-cocok">
                    <h2>Cocok untuk</h2>
                    <ul>
                        @foreach ($page['cocok'] as $item)
                            <li>{{ $item }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <section class="ju-sec">
        <div class="wrap">
            <h2>{{ $page['problems_title'] }}</h2>
            <p class="sub">{{ $page['problems_sub'] }}</p>
            <div class="ju-grid three">
                @foreach ($page['problems'] as $p)
                    <div class="ju-box ju-problem">
                        <p class="before">Masalah</p>
                        <h3>{{ $p['problem'] }}</h3>
                        <p>{{ $p['detail'] }}</p>
                        <p class="after"><strong>Solusinya:</strong> {{ $p['solution'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="ju-sec" id="fitur">
        <div class="wrap">
            <h2>Fitur kasir yang dipakai {{ $page['audience'] }}</h2>
            <p class="sub">{{ $page['features_sub'] }}</p>
            <div class="ju-grid three">
                @foreach ($page['features'] as $f)
                    <div class="ju-box">
                        <h3>{{ $f['title'] }}</h3>
                        <p>{{ $f['desc'] }}</p>
                        @if (! empty($f['paid']))
                            <span class="ju-plan">Paket {{ $pricing['paid']['name'] }}</span>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="ju-sec">
        <div class="wrap">
            <h2>{{ $page['flow_title'] }}</h2>
            <p class="sub">{{ $page['flow_sub'] }}</p>
            <ol class="ju-steps">
                @foreach ($page['flow'] as $step)
                    <li><div><strong>{{ $step['title'] }}</strong><span>{{ $step['desc'] }}</span></div></li>
                @endforeach
            </ol>
        </div>
    </section>

    <section class="ju-sec" id="harga">
        <div class="wrap">
            <h2>Harga aplikasi kasir {{ \Illuminate\Support\Str::lower($page['name']) }}</h2>
            <p class="sub">{{ $page['pricing_note'] }}</p>
            <div class="ju-plans">
                <div class="ju-box">
                    <h3>Paket {{ $pricing['free']['name'] }}</h3>
                    <p class="ju-price">{{ $pricing['free']['price_label'] }}</p>
                    @php
                        $batas = array_filter([
                            $pricing['free']['max_products'] ? $pricing['free']['max_products'].' produk' : null,
                            $pricing['free']['max_customers'] ? $pricing['free']['max_customers'].' pelanggan' : null,
                        ]);
                    @endphp
                    <p>
                        Transaksi tanpa batas untuk {{ $pricing['free']['max_stores'] }} outlet{{ $batas ? ', hingga '.implode(' dan ', $batas) : '' }}.
                        Cocok untuk mulai mencoba.
                    </p>
                </div>
                <div class="ju-box paid">
                    <h3>Paket {{ $pricing['paid']['name'] }}</h3>
                    @if ($pricing['paid']['monthly_label'] !== '')
                        <p class="ju-price">{{ $pricing['paid']['monthly_label'] }} <small>/ bulan</small></p>
                    @endif
                    <p>{{ $pricing['paid']['description'] }}</p>
                </div>
            </div>
        </div>
    </section>

    <section class="ju-sec" id="faq">
        <div class="wrap">
            <h2>Pertanyaan seputar aplikasi kasir {{ \Illuminate\Support\Str::lower($page['name']) }}</h2>
            <div style="margin-top: 22px">
                @foreach ($page['faq'] as $f)
                    <details class="faq-item" @if ($loop->first) open @endif>
                        <summary>{{ $f['q'] }}</summary>
                        <p>{{ $f['a'] }}</p>
                    </details>
                @endforeach
            </div>
        </div>
    </section>

    @if ($articles->isNotEmpty())
        <section class="ju-sec">
            <div class="wrap">
                <h2>Bacaan untuk {{ $page['audience'] }}</h2>
                <div class="grid" style="margin-top: 22px">
                    @foreach ($articles as $article)
                        <div class="card">
                            <a class="card-link" href="{{ $article->publicUrl() }}">
                                <h3>{{ $article->title }}</h3>
                                @if ($article->excerpt)
                                    <p>{{ \Illuminate\Support\Str::limit(strip_tags((string) $article->excerpt), 150) }}</p>
                                @endif
                            </a>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    <section class="ju-sec">
        <div class="wrap">
            <h2>Aplikasi kasir untuk jenis usaha lain</h2>
            <div class="ju-links" style="margin-top: 18px">
                @foreach ($others as $other)
                    <a href="{{ url('/'.$other['slug']) }}">Aplikasi kasir {{ \Illuminate\Support\Str::lower($other['name']) }}</a>
                @endforeach
            </div>
        </div>
    </section>

    <section class="ju-sec">
        <div class="wrap">
            <h2>Aplikasi kasir per kota</h2>
            <p class="sub">Lihat contoh usaha dan alur kasir yang khas di tiap kota. Aplikasinya sendiri bisa dipakai di seluruh Indonesia.</p>
            <div class="ju-links">
                @foreach (\App\Support\KotaLayanan::all() as $kt)
                    <a href="{{ url('/'.$kt['slug']) }}">{{ $kt['name'] }}</a>
                @endforeach
                <a href="{{ url('/'.\App\Support\KotaLayanan::INDEX_PATH) }}">Semua kota</a>
            </div>
        </div>
    </section>
@endsection
