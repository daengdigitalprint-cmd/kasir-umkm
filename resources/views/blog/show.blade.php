@extends('blog.layout')

@php
    $metaTitle = $article->meta_title ?: $article->title;
    $metaDescription = $article->meta_description ?: \Illuminate\Support\Str::limit((string) $article->excerpt, 155);
    $canonical = $article->canonical_url ?: $article->publicUrl();
    $publishedIso = $article->published_at?->toIso8601String();
    $modifiedIso = $article->updated_at?->toIso8601String();

    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'Article',
        'headline' => $article->title,
        'description' => $metaDescription,
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $article->publicUrl()],
        'datePublished' => $publishedIso,
        'dateModified' => $modifiedIso,
        'inLanguage' => 'id-ID',
        'author' => ['@type' => 'Organization', 'name' => 'Kasir UMKM by MaccaPOS', 'url' => url('/')],
        'publisher' => ['@type' => 'Organization', 'name' => 'Kasir UMKM by MaccaPOS', 'url' => url('/')],
    ];
    if ($article->focus_keyword) {
        $schema['keywords'] = implode(', ', array_filter(array_merge([$article->focus_keyword], $article->tags ?? [])));
    }

    $breadcrumbSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Beranda', 'item' => url('/')],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Blog', 'item' => route('blog.index')],
            ['@type' => 'ListItem', 'position' => 3, 'name' => $article->title, 'item' => $article->publicUrl()],
        ],
    ];

    $faqSchema = null;
    if (! empty($article->faq)) {
        $faqSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array_map(fn ($f) => [
                '@type' => 'Question',
                'name' => $f['question'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['answer']],
            ], $article->faq),
        ];
    }

    $jsonFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
@endphp

@section('title', $metaTitle)

@section('meta')
    <meta name="description" content="{{ $metaDescription }}">
    <link rel="canonical" href="{{ $canonical }}">
    @if ($isPreview)
        <meta name="robots" content="noindex, nofollow">
    @endif
    <meta property="og:type" content="article">
    <meta property="og:site_name" content="Kasir UMKM by MaccaPOS">
    <meta property="og:title" content="{{ $metaTitle }}">
    <meta property="og:description" content="{{ $metaDescription }}">
    <meta property="og:url" content="{{ $article->publicUrl() }}">
    @if ($publishedIso)
        <meta property="article:published_time" content="{{ $publishedIso }}">
    @endif
    <meta property="article:modified_time" content="{{ $modifiedIso }}">
    @if ($article->category)
        <meta property="article:section" content="{{ $article->category }}">
    @endif
    @foreach ($article->tags ?? [] as $tag)
        <meta property="article:tag" content="{{ $tag }}">
    @endforeach
    <meta name="twitter:card" content="summary">
    <script type="application/ld+json">{!! json_encode($schema, $jsonFlags) !!}</script>
    <script type="application/ld+json">{!! json_encode($breadcrumbSchema, $jsonFlags) !!}</script>
    @if ($faqSchema)
        <script type="application/ld+json">{!! json_encode($faqSchema, $jsonFlags) !!}</script>
    @endif
@endsection

@section('content')
    @if ($isPreview)
        <div class="preview-banner">Mode pratinjau — artikel ini belum terbit dan hanya terlihat oleh super admin.</div>
    @endif

    <main class="wrap narrow" style="padding-top:10px;">
        <nav class="breadcrumbs" aria-label="Breadcrumb">
            <a href="{{ url('/') }}">Beranda</a> ›
            <a href="{{ route('blog.index') }}">Blog</a>
            @if ($article->category)
                › <a href="{{ route('blog.index', ['kategori' => $article->category]) }}">{{ $article->category }}</a>
            @endif
        </nav>

        <article class="prose">
            <header>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px;">
                    @if ($article->category)
                        <span class="badge">{{ $article->category }}</span>
                    @endif
                    @if ($article->is_pillar)
                        <span class="badge" style="background:#ecfdf5;color:var(--accent);">Panduan Lengkap</span>
                    @endif
                </div>
                <h1 style="font-size:clamp(26px,4.5vw,38px);line-height:1.25;margin:0 0 12px;">{{ $article->title }}</h1>
                <p class="meta-line" style="margin:0 0 4px;">
                    @if ($article->published_at)
                        Dipublikasikan {{ $article->published_at->translatedFormat('d F Y') }}
                        @if ($article->updated_at && $article->published_at && $article->updated_at->diffInDays($article->published_at) > 7)
                            · Diperbarui {{ $article->updated_at->translatedFormat('d F Y') }}
                        @endif
                        ·
                    @endif
                    {{ $article->reading_time }} menit baca
                </p>
            </header>

            @if (count($toc) >= 3)
                <nav class="toc" aria-label="Daftar isi">
                    <strong>Daftar Isi</strong>
                    <ol>
                        @foreach ($toc as $item)
                            <li><a href="#{{ $item['id'] }}">{{ $item['text'] }}</a></li>
                        @endforeach
                    </ol>
                </nav>
            @endif

            {!! $contentHtml !!}

            @if (! empty($article->faq))
                <section style="margin-top:36px;">
                    <h2>Pertanyaan yang Sering Diajukan</h2>
                    @foreach ($article->faq as $faq)
                        <details class="faq-item" @if ($loop->first) open @endif>
                            <summary>{{ $faq['question'] }}</summary>
                            <p>{{ $faq['answer'] }}</p>
                        </details>
                    @endforeach
                </section>
            @endif
        </article>

        <aside class="cta-box">
            <h2>Kelola kasir, stok, dan laporan dalam satu aplikasi</h2>
            <p>Kasir UMKM by MaccaPOS gratis untuk mulai — catat penjualan, cetak struk, dan pantau laba dari HP.</p>
            <a href="{{ url('/register') }}">Coba Gratis Sekarang</a>
        </aside>

        @if ($related->isNotEmpty())
            <section style="margin-top:44px;">
                <h2 style="font-size:22px;margin:0 0 16px;">Artikel Terkait</h2>
                <div class="grid">
                    @foreach ($related as $rel)
                        <div class="card">
                            <a class="card-link" href="{{ route('blog.show', $rel->slug) }}">
                                @if ($rel->category)
                                    <span class="badge">{{ $rel->category }}</span>
                                @endif
                                <h2>{{ $rel->title }}</h2>
                                <p>{{ \Illuminate\Support\Str::limit((string) $rel->excerpt, 110) }}</p>
                            </a>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    </main>
@endsection
