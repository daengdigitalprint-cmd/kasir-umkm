@extends('blog.layout')

@php
    if ($activeTag) {
        $pageTitle = "Artikel bertopik {$activeTag} — Blog Kasir UMKM by MaccaPOS";
        $pageDescription = "Kumpulan artikel seputar {$activeTag} untuk pemilik UMKM Indonesia.";
        $canonical = route('blog.index', ['tag' => $activeTag]);
        $heading = "Tag: {$activeTag}";
    } elseif ($activeCategory) {
        $pageTitle = "Artikel {$activeCategory} — Blog Kasir UMKM by MaccaPOS";
        $pageDescription = "Kumpulan artikel {$activeCategory} untuk pemilik UMKM: praktis, mudah dipahami, dan bisa langsung dipraktikkan.";
        $canonical = route('blog.index', ['kategori' => $activeCategory]);
        $heading = $activeCategory;
    } else {
        $pageTitle = 'Blog Kasir UMKM — Tips Kasir, Stok & Keuangan untuk UMKM';
        $pageDescription = 'Tips praktis seputar aplikasi kasir, manajemen stok, keuangan, dan operasional UMKM Indonesia. Ditulis untuk pemilik usaha, bukan ahli IT.';
        $canonical = route('blog.index');
        $heading = 'Blog Kasir UMKM';
    }
@endphp

@section('title', $pageTitle)

@section('meta')
    <meta name="description" content="{{ $pageDescription }}">
    <link rel="canonical" href="{{ $canonical }}">
    @if ($articles->currentPage() > 1 || $activeTag)
        <meta name="robots" content="noindex, follow">
    @endif
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Kasir UMKM by MaccaPOS">
    <meta property="og:title" content="{{ $pageTitle }}">
    <meta property="og:description" content="{{ $pageDescription }}">
    <meta property="og:url" content="{{ $canonical }}">
    <meta name="twitter:card" content="summary">
    <script type="application/ld+json">{!! json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Blog',
        'name' => 'Blog Kasir UMKM by MaccaPOS',
        'description' => $pageDescription,
        'url' => route('blog.index'),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>

    <style>
        .chipbar { display: flex; gap: 9px; flex-wrap: wrap; margin: 22px 0 0; }
        .chipbar a { border: 1px solid var(--line); background: #fff; color: #334155; font-size: 13.5px; font-weight: 600; padding: 8px 17px; border-radius: 999px; transition: all .2s ease; }
        .chipbar a.on { background: var(--primary); border-color: var(--primary); color: #fff; }
        .chipbar a:hover { border-color: var(--primary); color: var(--primary); }
        .chipbar a.on:hover { color: #fff; }

        .pagination { display: flex; gap: 7px; justify-content: center; margin-top: 40px; flex-wrap: wrap; }
        .pagination a, .pagination span { min-width: 42px; text-align: center; padding: 10px 15px; border-radius: 14px; border: 1px solid var(--line); background: #fff; font-size: 14px; font-weight: 600; color: var(--ink); }
        .pagination a:hover { border-color: var(--primary); color: var(--primary); }
        .pagination .active { background: var(--primary); color: #fff; border-color: var(--primary); }
        .pagination .disabled { color: #cbd5e1; }

        .feature-card { grid-column: 1 / -1; }
        @media (min-width: 700px) { .feature-card a.card-link { padding: 32px; } .feature-card h2 { font-size: 24px; } }
    </style>
@endsection

@section('content')
    <section class="page-hero">
        <div class="wrap">
            <p class="label" style="margin:0">Blog &amp; Tips UMKM</p>
            <h1>{{ $heading }}</h1>
            <p class="lead">{{ $pageDescription }}</p>

            @if ($categories->isNotEmpty())
                <nav class="chipbar" aria-label="Kategori artikel">
                    <a href="{{ route('blog.index') }}" class="{{ ! $activeCategory && ! $activeTag ? 'on' : '' }}">Semua</a>
                    @foreach ($categories as $cat)
                        <a href="{{ route('blog.index', ['kategori' => $cat]) }}" class="{{ $activeCategory === $cat ? 'on' : '' }}">{{ $cat }}</a>
                    @endforeach
                </nav>
            @endif
        </div>
    </section>

    <div class="wrap">
        <div class="layout">
            <main>
                @if ($articles->isEmpty())
                    <div style="background:#fff;border:1px solid var(--line);border-radius:24px;text-align:center;padding:72px 24px;">
                        <p style="font-size:34px;margin:0 0 10px;" aria-hidden="true">📝</p>
                        <h2 style="font-size:20px;margin:0 0 8px;">Belum ada artikel di sini</h2>
                        <p style="color:var(--muted);margin:0 0 20px;">Artikel baru akan segera hadir. Sementara itu, coba aplikasinya dulu.</p>
                        <a class="btn btn-primary" href="{{ url('/register') }}">Coba Gratis</a>
                    </div>
                @else
                    <div class="grid">
                        @foreach ($articles as $article)
                            <div class="card {{ $loop->first && $articles->currentPage() === 1 && ! $activeTag ? 'feature-card' : '' }}">
                                <a class="card-link" href="{{ route('blog.show', $article->slug) }}">
                                    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                                        @if ($article->category)
                                            <span class="pill">{{ $article->category }}</span>
                                        @endif
                                        @if ($article->is_pillar)
                                            <span class="pill pill-lime">Panduan Lengkap</span>
                                        @endif
                                        @if ($loop->first && $articles->currentPage() === 1 && ! $activeTag)
                                            <span class="pill pill-orange">Terbaru</span>
                                        @endif
                                    </div>
                                    <h2>{{ $article->title }}</h2>
                                    <p>{{ $article->excerpt }}</p>
                                    <p class="meta" style="margin-top:16px;">
                                        {{ $article->published_at->translatedFormat('d M Y') }}
                                        · {{ $article->reading_time }} menit baca
                                    </p>
                                </a>
                            </div>
                        @endforeach
                    </div>

                    @if ($articles->hasPages())
                        <nav class="pagination" aria-label="Halaman">
                            @if ($articles->onFirstPage())
                                <span class="disabled">‹</span>
                            @else
                                <a href="{{ $articles->previousPageUrl() }}" rel="prev">‹</a>
                            @endif

                            @foreach ($articles->getUrlRange(max(1, $articles->currentPage() - 2), min($articles->lastPage(), $articles->currentPage() + 2)) as $page => $url)
                                @if ($page === $articles->currentPage())
                                    <span class="active">{{ $page }}</span>
                                @else
                                    <a href="{{ $url }}">{{ $page }}</a>
                                @endif
                            @endforeach

                            @if ($articles->hasMorePages())
                                <a href="{{ $articles->nextPageUrl() }}" rel="next">›</a>
                            @else
                                <span class="disabled">›</span>
                            @endif
                        </nav>
                    @endif
                @endif
            </main>

            @include('blog.partials.sidebar', [
                'popularTags' => $popularTags,
                'popularArticles' => $popularArticles,
                'activeTag' => $activeTag,
            ])
        </div>
    </div>
@endsection
