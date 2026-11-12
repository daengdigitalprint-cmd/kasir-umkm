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

    <style>
        /* ===== Tipografi isi artikel ===== */
        article.post { background: #fff; border: 1px solid var(--line); border-radius: 28px; padding: clamp(22px, 3.6vw, 48px); }
        .post-head { border-bottom: 1px solid var(--line); padding-bottom: 22px; margin-bottom: 26px; }
        .post-head h1 { font-size: clamp(27px, 4.4vw, 42px); margin: 14px 0 14px; }
        .post-head .pills { display: flex; gap: 8px; flex-wrap: wrap; }
        .post-meta { display: flex; flex-wrap: wrap; gap: 8px 18px; align-items: center; color: var(--muted); font-size: 14px; }
        .post-meta .dot { color: #cbd5e1; }
        /* Pemisah batang hanya relevan saat byline & tanggal sebaris. */
        @media (max-width: 639px) { .post-meta .sep-bar { display: none; } }
        .byline { display: flex; align-items: center; gap: 10px; }
        .byline .av { width: 38px; height: 38px; border-radius: 12px; background: var(--primary-soft); display: flex; align-items: center; justify-content: center; font-size: 17px; }
        .byline strong { display: block; font-size: 14px; color: var(--ink); font-weight: 700; line-height: 1.3; }

        .prose { font-size: 16.5px; color: #1e293b; }
        .prose h2 { font-size: clamp(21px, 2.6vw, 27px); margin: 40px 0 14px; scroll-margin-top: 100px; position: relative; padding-left: 16px; }
        .prose h2::before { content: ''; position: absolute; left: 0; top: .18em; bottom: .18em; width: 5px; border-radius: 999px; background: linear-gradient(180deg, var(--primary), var(--secondary)); }
        .prose h3 { font-size: 19px; margin: 30px 0 10px; }
        .prose p { margin: 0 0 18px; }
        .prose ul, .prose ol { padding-left: 22px; margin: 0 0 18px; }
        .prose li { margin-bottom: 9px; }
        .prose li::marker { color: var(--primary); font-weight: 700; }
        .prose a { font-weight: 600; text-decoration: underline; text-decoration-color: #d8ccf7; text-underline-offset: 3px; }
        .prose a:hover { text-decoration-color: var(--primary); }
        .prose img { border-radius: 18px; }
        .prose strong { color: var(--ink); font-weight: 700; }
        .prose blockquote { border: 0; border-left: 5px solid var(--primary); margin: 24px 0; padding: 16px 22px; background: var(--primary-muted); border-radius: 0 16px 16px 0; color: #3b2a60; font-size: 16px; }
        .prose blockquote p:last-child { margin: 0; }
        .prose table { width: 100%; border-collapse: separate; border-spacing: 0; margin: 24px 0; font-size: 15px; display: block; overflow-x: auto; border: 1px solid var(--line); border-radius: 16px; }
        .prose th, .prose td { border-bottom: 1px solid var(--line); padding: 12px 16px; text-align: left; }
        .prose tr:last-child td { border-bottom: 0; }
        .prose th { background: var(--primary-muted); font-family: var(--font-display); font-weight: 700; color: var(--primary-dark); white-space: nowrap; }
        .prose code { background: var(--container); border: 1px solid var(--line); border-radius: 7px; padding: 2px 7px; font-size: 14px; }
        .prose hr { border: 0; border-top: 1px solid var(--line); margin: 32px 0; }

        .toc { background: var(--primary-muted); border: 1px solid #e4dafb; border-radius: 20px; padding: 20px 24px; margin: 26px 0 30px; }
        .toc > p { margin: 0 0 10px; font-family: var(--font-display); font-weight: 800; font-size: 15px; display: flex; align-items: center; gap: 8px; }
        .toc ol { margin: 0; padding-left: 20px; }
        .toc li { margin-bottom: 7px; }
        .toc li::marker { color: var(--primary); font-weight: 700; }
        .toc a { color: #334155; font-weight: 500; font-size: 15px; }
        .toc a:hover { color: var(--primary); }

        .faq-wrap { margin-top: 44px; }
        .faq-item { border: 1px solid var(--line); border-radius: 18px; padding: 16px 20px; margin-bottom: 12px; background: #fff; transition: border-color .2s ease; }
        .faq-item[open] { border-color: #d8ccf7; background: var(--primary-muted); }
        .faq-item summary { font-family: var(--font-display); font-weight: 700; font-size: 16px; cursor: pointer; list-style: none; display: flex; justify-content: space-between; gap: 14px; align-items: flex-start; }
        .faq-item summary::-webkit-details-marker { display: none; }
        .faq-item summary::after { content: '+'; color: var(--primary); font-size: 22px; line-height: 1; flex: none; }
        .faq-item[open] summary::after { content: '−'; }
        .faq-item p { margin: 12px 0 0; color: #475569; font-size: 15.5px; }

        .tag-row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-top: 36px; padding-top: 24px; border-top: 1px solid var(--line); }
        .tag-row a { background: var(--container); color: #334155; font-size: 13px; font-weight: 600; padding: 6px 13px; border-radius: 999px; }
        .tag-row a:hover { background: var(--primary); color: #fff; }

        .share { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-top: 22px; }
        .share a { width: 38px; height: 38px; border-radius: 12px; border: 1px solid var(--line); display: inline-flex; align-items: center; justify-content: center; font-size: 15px; color: #475569; }
        .share a:hover { border-color: var(--primary); background: var(--primary-muted); }

        .related-head { display: flex; align-items: baseline; justify-content: space-between; gap: 14px; margin: 48px 0 18px; }
        .related-head h2 { font-size: 23px; margin: 0; }
    </style>
@endsection

@section('content')
    @if ($isPreview)
        <div class="preview-banner">Mode pratinjau — artikel ini belum terbit dan hanya terlihat oleh super admin.</div>
    @endif

    <div class="wrap">
        <nav class="crumbs" aria-label="Breadcrumb">
            <a href="{{ url('/') }}">Beranda</a>
            <span class="s">/</span>
            <a href="{{ route('blog.index') }}">Blog</a>
            @if ($article->category)
                <span class="s">/</span>
                <a href="{{ route('blog.index', ['kategori' => $article->category]) }}">{{ $article->category }}</a>
            @endif
        </nav>

        <div class="layout">
            {{-- ============ Kolom artikel ============ --}}
            <main>
                <article class="post">
                    <header class="post-head">
                        <div class="pills">
                            @if ($article->category)
                                <span class="pill">{{ $article->category }}</span>
                            @endif
                            @if ($article->is_pillar)
                                <span class="pill pill-lime">Panduan Lengkap</span>
                            @endif
                        </div>

                        <h1>{{ $article->title }}</h1>

                        @if ($article->excerpt)
                            <p style="color:var(--muted);font-size:17px;margin:0 0 20px;">{{ $article->excerpt }}</p>
                        @endif

                        <div class="post-meta">
                            <span class="byline">
                                <span class="av" aria-hidden="true">🏪</span>
                                <span>
                                    <strong>Tim Kasir UMKM</strong>
                                    <span style="font-size:12.5px;">by MaccaPOS</span>
                                </span>
                            </span>
                            <span class="dot sep-bar" aria-hidden="true">|</span>
                            @if ($article->published_at)
                                <span>{{ $article->published_at->translatedFormat('d F Y') }}</span>
                                <span class="dot" aria-hidden="true">·</span>
                            @endif
                            <span>{{ $article->reading_time }} menit baca</span>
                            @if ($article->updated_at && $article->published_at && $article->updated_at->diffInDays($article->published_at) > 7)
                                <span class="dot" aria-hidden="true">·</span>
                                <span>Diperbarui {{ $article->updated_at->translatedFormat('d M Y') }}</span>
                            @endif
                        </div>
                    </header>

                    @if (count($toc) >= 3)
                        <nav class="toc" aria-label="Daftar isi">
                            <p><span aria-hidden="true">📑</span> Daftar Isi</p>
                            <ol>
                                @foreach ($toc as $item)
                                    <li><a href="#{{ $item['id'] }}">{{ $item['text'] }}</a></li>
                                @endforeach
                            </ol>
                        </nav>
                    @endif

                    <div class="prose">
                        {!! $contentHtml !!}
                    </div>

                    @if (! empty($article->faq))
                        <section class="faq-wrap">
                            <h2 style="font-size:23px;margin:0 0 16px;">Pertanyaan yang Sering Diajukan</h2>
                            @foreach ($article->faq as $faq)
                                <details class="faq-item" @if ($loop->first) open @endif>
                                    <summary>{{ $faq['question'] }}</summary>
                                    <p>{{ $faq['answer'] }}</p>
                                </details>
                            @endforeach
                        </section>
                    @endif

                    @if (! empty($article->tags))
                        <div class="tag-row">
                            <span class="label" style="margin-right:4px;">Tag</span>
                            @foreach ($article->tags as $tag)
                                <a href="{{ route('blog.index', ['tag' => $tag]) }}" rel="tag">{{ $tag }}</a>
                            @endforeach
                        </div>
                    @endif

                    @php
                        $shareUrl = urlencode($article->publicUrl());
                        $shareText = urlencode($article->title);
                    @endphp
                    <div class="share">
                        <span class="label" style="margin-right:4px;">Bagikan</span>
                        <a href="https://wa.me/?text={{ $shareText }}%20{{ $shareUrl }}" target="_blank" rel="noopener nofollow" aria-label="Bagikan ke WhatsApp" title="WhatsApp">💬</a>
                        <a href="https://www.facebook.com/sharer/sharer.php?u={{ $shareUrl }}" target="_blank" rel="noopener nofollow" aria-label="Bagikan ke Facebook" title="Facebook">f</a>
                        <a href="https://twitter.com/intent/tweet?url={{ $shareUrl }}&text={{ $shareText }}" target="_blank" rel="noopener nofollow" aria-label="Bagikan ke X" title="X">𝕏</a>
                        <a href="https://t.me/share/url?url={{ $shareUrl }}&text={{ $shareText }}" target="_blank" rel="noopener nofollow" aria-label="Bagikan ke Telegram" title="Telegram">✈️</a>
                    </div>
                </article>

                @if ($related->isNotEmpty())
                    <section>
                        <div class="related-head">
                            <h2>Artikel Terkait</h2>
                            <a href="{{ route('blog.index') }}" style="font-size:14px;font-weight:700;">Lihat semua →</a>
                        </div>
                        <div class="grid">
                            @foreach ($related as $rel)
                                <div class="card">
                                    <a class="card-link" href="{{ route('blog.show', $rel->slug) }}">
                                        @if ($rel->category)
                                            <span class="pill">{{ $rel->category }}</span>
                                        @endif
                                        <h3>{{ $rel->title }}</h3>
                                        <p>{{ \Illuminate\Support\Str::limit((string) $rel->excerpt, 100) }}</p>
                                        <p class="meta" style="margin-top:14px;">{{ $rel->reading_time }} menit baca</p>
                                    </a>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif
            </main>

            {{-- ============ Sidebar kanan ============ --}}
            @include('blog.partials.sidebar', [
                'popularTags' => $popularTags,
                'popularArticles' => $popularArticles,
                'activeTag' => null,
            ])
        </div>
    </div>
@endsection
