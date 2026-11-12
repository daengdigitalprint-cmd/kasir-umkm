@extends('blog.layout')

@php
    $pageTitle = $activeCategory
        ? "Artikel {$activeCategory} — Blog Kasir UMKM by MaccaPOS"
        : 'Blog Kasir UMKM — Tips Kasir, Stok & Keuangan untuk UMKM';
    $pageDescription = $activeCategory
        ? "Kumpulan artikel {$activeCategory} untuk pemilik UMKM: praktis, mudah dipahami, dan bisa langsung dipraktikkan."
        : 'Tips praktis seputar aplikasi kasir, manajemen stok, keuangan, dan operasional UMKM Indonesia. Ditulis untuk pemilik usaha, bukan ahli IT.';
    $canonical = $activeCategory ? route('blog.index', ['kategori' => $activeCategory]) : route('blog.index');
@endphp

@section('title', $pageTitle)

@section('meta')
    <meta name="description" content="{{ $pageDescription }}">
    <link rel="canonical" href="{{ $canonical }}">
    @if ($articles->currentPage() > 1)
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
@endsection

@section('content')
    <main class="wrap" style="padding-top:36px;">
        <div style="max-width:640px;">
            <h1 style="font-size:clamp(26px,4vw,36px);line-height:1.25;margin:0 0 10px;">
                {{ $activeCategory ? "Artikel {$activeCategory}" : 'Blog Kasir UMKM' }}
            </h1>
            <p class="meta-line" style="font-size:16px;margin:0;">{{ $pageDescription }}</p>
        </div>

        @if ($categories->isNotEmpty())
            <nav class="chipbar" aria-label="Kategori artikel">
                <a href="{{ route('blog.index') }}" class="{{ $activeCategory ? '' : 'on' }}">Semua</a>
                @foreach ($categories as $cat)
                    <a href="{{ route('blog.index', ['kategori' => $cat]) }}" class="{{ $activeCategory === $cat ? 'on' : '' }}">{{ $cat }}</a>
                @endforeach
            </nav>
        @endif

        @if ($articles->isEmpty())
            <div style="text-align:center;padding:80px 0;color:var(--muted);">
                <p style="font-size:17px;">Belum ada artikel di sini. Segera hadir!</p>
            </div>
        @else
            <div class="grid" style="margin-top:8px;">
                @foreach ($articles as $article)
                    <div class="card">
                        <a class="card-link" href="{{ route('blog.show', $article->slug) }}">
                            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                                @if ($article->category)
                                    <span class="badge">{{ $article->category }}</span>
                                @endif
                                @if ($article->is_pillar)
                                    <span class="badge" style="background:#ecfdf5;color:var(--accent);">Panduan Lengkap</span>
                                @endif
                            </div>
                            <h2>{{ $article->title }}</h2>
                            <p>{{ $article->excerpt }}</p>
                            <p class="meta-line" style="margin-top:14px;">
                                {{ $article->published_at->translatedFormat('d M Y') }}
                                · {{ $article->reading_time }} menit baca
                            </p>
                        </a>
                    </div>
                @endforeach
            </div>

            @if ($articles->hasPages())
                <nav class="pagination" aria-label="Halaman">
                    {{-- Prev --}}
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

                    {{-- Next --}}
                    @if ($articles->hasMorePages())
                        <a href="{{ $articles->nextPageUrl() }}" rel="next">›</a>
                    @else
                        <span class="disabled">›</span>
                    @endif
                </nav>
            @endif
        @endif
    </main>
@endsection
