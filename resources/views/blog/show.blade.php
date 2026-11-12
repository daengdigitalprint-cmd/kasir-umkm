@extends('blog.layout')

@php
    $metaTitle = $article->meta_title ?: $article->title;
    $metaDescription = $article->meta_description ?: \Illuminate\Support\Str::limit((string) $article->excerpt, 155);
    $canonical = $article->canonical_url ?: $article->publicUrl();
    $publishedIso = $article->published_at?->toIso8601String();
    $modifiedIso = $article->updated_at?->toIso8601String();

    $imageUrl = $article->featuredImageUrl();
    $imageAlt = $imageUrl ? $article->featuredImageAlt() : null;

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

    if ($imageUrl) {
        $schema['image'] = [[
            '@type' => 'ImageObject',
            'url' => $imageUrl,
            'caption' => $imageAlt,
        ]];
    }
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
    @if ($imageUrl)
        <meta property="og:image" content="{{ $imageUrl }}">
        <meta property="og:image:alt" content="{{ $imageAlt }}">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:image" content="{{ $imageUrl }}">
        <meta name="twitter:image:alt" content="{{ $imageAlt }}">
    @else
        <meta name="twitter:card" content="summary">
    @endif
    <script type="application/ld+json">{!! json_encode($schema, $jsonFlags) !!}</script>
    <script type="application/ld+json">{!! json_encode($breadcrumbSchema, $jsonFlags) !!}</script>
    @if ($faqSchema)
        <script type="application/ld+json">{!! json_encode($faqSchema, $jsonFlags) !!}</script>
    @endif

    <style>
        /* ===== Tipografi isi artikel ===== */
        article.post { background: #fff; border: 1px solid var(--line); border-radius: 24px; padding: clamp(22px, 3.6vw, 48px); }
        .post-head { border-bottom: 1px solid var(--line); padding-bottom: 22px; margin-bottom: 26px; }
        .post-head h1 { font-size: clamp(27px, 4.4vw, 42px); margin: 14px 0 14px; }
        .post-head .pills { display: flex; gap: 8px; flex-wrap: wrap; }
        .post-meta { display: flex; flex-wrap: wrap; gap: 8px 18px; align-items: center; color: var(--muted); font-size: 14px; }
        .post-meta .dot { color: #bdb8d0; }
        /* Pemisah batang hanya relevan saat byline & tanggal sebaris. */
        @media (max-width: 639px) { .post-meta .sep-bar { display: none; } }
        .byline { display: flex; align-items: center; gap: 10px; }
        .byline .av { width: 38px; height: 38px; border-radius: 11px; background: var(--primary-soft); color: var(--primary); display: flex; align-items: center; justify-content: center; }
        .byline strong { display: block; font-size: 14px; color: var(--ink); font-weight: 700; line-height: 1.3; }

        /* Gambar mengikuti proporsi aslinya (height: auto), tidak dipotong.
           Atribut width/height diisi dari dimensi berkas sehingga browser
           sudah memesan ruang yang tepat sebelum gambar selesai dimuat. */
        .post-cover { margin: 0 0 8px; }
        .post-cover img { display: block; width: 100%; height: auto; border-radius: 16px; background: var(--container); }
        /* Gambar yang sangat tinggi (potret) dibatasi agar tidak mendorong
           seluruh isi artikel ke bawah layar. */
        .post-cover img { max-height: 70vh; width: auto; max-width: 100%; margin-inline: auto; }

        .prose { font-size: 16.5px; color: #1e2338; }
        .prose h2 { font-size: clamp(21px, 2.6vw, 27px); margin: 40px 0 14px; scroll-margin-top: 100px; position: relative; padding-left: 16px; }
        .prose h2::before { content: ''; position: absolute; left: 0; top: .18em; bottom: .18em; width: 5px; border-radius: 999px; background: var(--primary); }
        .prose h3 { font-size: 19px; margin: 30px 0 10px; }
        .prose p { margin: 0 0 18px; }
        .prose ul, .prose ol { padding-left: 22px; margin: 0 0 18px; }
        .prose li { margin-bottom: 9px; }
        .prose li::marker { color: var(--primary); font-weight: 700; }
        .prose a { font-weight: 600; text-decoration: underline; text-decoration-color: #c3b9fb; text-underline-offset: 3px; }
        .prose a:hover { text-decoration-color: var(--primary); }
        .prose img { border-radius: 18px; }
        .prose strong { color: var(--ink); font-weight: 700; }
        .prose blockquote { border: 0; border-left: 5px solid var(--primary); margin: 24px 0; padding: 16px 22px; background: var(--primary-muted); border-radius: 0 14px 14px 0; color: #332a60; font-size: 16px; }
        .prose blockquote p:last-child { margin: 0; }
        .prose table { width: 100%; border-collapse: separate; border-spacing: 0; margin: 24px 0; font-size: 15px; display: block; overflow-x: auto; border: 1px solid var(--line); border-radius: 14px; }
        .prose th, .prose td { border-bottom: 1px solid var(--line); padding: 12px 16px; text-align: left; }
        .prose tr:last-child td { border-bottom: 0; }
        .prose th { background: var(--primary-muted); font-family: var(--font-display); font-weight: 700; color: var(--primary-dark); white-space: nowrap; }
        .prose code { background: var(--container); border: 1px solid var(--line); border-radius: 7px; padding: 2px 7px; font-size: 14px; }
        .prose hr { border: 0; border-top: 1px solid var(--line); margin: 32px 0; }

        .toc { background: var(--primary-muted); border: 1px solid var(--line); border-radius: 18px; padding: 16px 24px; margin: 26px 0 30px; }
        .toc > summary {
            margin: 0; font-family: var(--font-display); font-weight: 800; font-size: 15px;
            display: flex; align-items: center; gap: 9px; color: var(--ink);
            cursor: pointer; list-style: none; user-select: none;
        }
        .toc > summary::-webkit-details-marker { display: none; }
        .toc > summary .icon { color: var(--primary); }
        .toc > summary .toc-count { margin-left: auto; font-family: var(--font-sans); font-weight: 600; font-size: 12.5px; color: var(--muted); }
        /* Tanda panah penunjuk buka/tutup */
        .toc > summary::after {
            content: ''; width: 8px; height: 8px; flex: none; margin-left: 10px;
            border-right: 2px solid var(--primary); border-bottom: 2px solid var(--primary);
            transform: rotate(45deg) translate(-2px, -2px); transition: transform .2s ease;
        }
        .toc[open] > summary::after { transform: rotate(-135deg) translate(-2px, -2px); }
        .toc > summary:focus-visible { outline: 2px solid var(--primary); outline-offset: 3px; border-radius: 6px; }
        .toc[open] > summary { margin-bottom: 12px; }
        .toc ol { margin: 0; padding-left: 20px; }
        .toc li { margin-bottom: 7px; }
        .toc li::marker { color: var(--primary); font-weight: 700; }
        .toc a { color: #3b3a4a; font-weight: 500; font-size: 15px; }
        .toc a:hover { color: var(--primary); }

        .faq-wrap { margin-top: 44px; }
        .faq-item { border: 1px solid var(--line); border-radius: 16px; padding: 16px 20px; margin-bottom: 12px; background: #fff; transition: border-color .2s ease; }
        .faq-item[open] { border-color: #c3b9fb; background: var(--primary-muted); }
        .faq-item summary { font-family: var(--font-display); font-weight: 700; font-size: 16px; cursor: pointer; list-style: none; display: flex; justify-content: space-between; gap: 14px; align-items: flex-start; }
        .faq-item summary::-webkit-details-marker { display: none; }
        .faq-item summary::after { content: '+'; color: var(--primary); font-size: 22px; line-height: 1; flex: none; }
        .faq-item[open] summary::after { content: '−'; }
        .faq-item p { margin: 12px 0 0; color: #4a4860; font-size: 15.5px; }

        .tag-row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-top: 36px; padding-top: 24px; border-top: 1px solid var(--line); }
        .tag-row a { background: var(--container); color: #3b3a4a; font-size: 13px; font-weight: 600; padding: 6px 13px; border-radius: 999px; }
        .tag-row a:hover { background: var(--primary); color: #fff; }

        .share { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-top: 22px; }
        .share a { width: 38px; height: 38px; border-radius: 11px; border: 1px solid var(--line); display: inline-flex; align-items: center; justify-content: center; color: #4a4860; transition: border-color .2s ease, color .2s ease, background .2s ease; }
        .share a:hover { border-color: var(--primary); background: var(--primary-muted); color: var(--primary); }
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
                                <span class="av">@include('blog.partials.icon', ['name' => 'store', 'class' => 'ic-md'])</span>
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

                    @if ($imageUrl)
                        @php $dim = $article->featuredImageDimensions(); @endphp
                        <figure class="post-cover">
                            {{-- Gambar utama artikel: tidak lazy agar cepat tampil (LCP). --}}
                            <img
                                src="{{ $imageUrl }}"
                                alt="{{ $imageAlt }}"
                                @if ($dim)
                                    width="{{ $dim['width'] }}"
                                    height="{{ $dim['height'] }}"
                                @endif
                                fetchpriority="high"
                                decoding="async"
                            >
                        </figure>
                    @endif

                    @if (count($toc) >= 3)
                        <details class="toc" open>
                            <summary>
                                @include('blog.partials.icon', ['name' => 'list', 'class' => 'ic-sm'])
                                <span>Daftar Isi</span>
                                <span class="toc-count">{{ count($toc) }} bagian</span>
                            </summary>
                            <ol>
                                @foreach ($toc as $item)
                                    <li><a href="#{{ $item['id'] }}">{{ $item['text'] }}</a></li>
                                @endforeach
                            </ol>
                        </details>
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
                        <a href="https://wa.me/?text={{ $shareText }}%20{{ $shareUrl }}" target="_blank" rel="noopener nofollow" aria-label="Bagikan ke WhatsApp" title="WhatsApp">
                            @include('blog.partials.icon', ['name' => 'whatsapp', 'class' => 'ic-md'])
                        </a>
                        <a href="https://www.facebook.com/sharer/sharer.php?u={{ $shareUrl }}" target="_blank" rel="noopener nofollow" aria-label="Bagikan ke Facebook" title="Facebook">
                            @include('blog.partials.icon', ['name' => 'facebook', 'class' => 'ic-md'])
                        </a>
                        <a href="https://twitter.com/intent/tweet?url={{ $shareUrl }}&text={{ $shareText }}" target="_blank" rel="noopener nofollow" aria-label="Bagikan ke X" title="X">
                            @include('blog.partials.icon', ['name' => 'x', 'class' => 'ic-sm'])
                        </a>
                        <a href="https://t.me/share/url?url={{ $shareUrl }}&text={{ $shareText }}" target="_blank" rel="noopener nofollow" aria-label="Bagikan ke Telegram" title="Telegram">
                            @include('blog.partials.icon', ['name' => 'telegram', 'class' => 'ic-md'])
                        </a>
                    </div>
                </article>

            </main>

            {{-- ============ Sidebar kanan (sticky sampai akhir artikel) ============ --}}
            @include('blog.partials.sidebar', [
                'popularTags' => $popularTags,
                'popularArticles' => $popularArticles,
                'activeTag' => null,
            ])
        </div>

        {{-- Di luar grid: lebar penuh, jadi sidebar berhenti menempel di sini. --}}
        @if ($related->isNotEmpty())
            <section aria-labelledby="artikel-lainnya">
                <div class="swipe-head">
                    <h2 id="artikel-lainnya">Artikel Lainnya</h2>
                    <div class="swipe-nav" data-swipe-nav="related">
                        <button type="button" data-dir="prev" aria-label="Geser ke kiri">
                            @include('blog.partials.icon', ['name' => 'chevron-left', 'class' => 'ic-md'])
                        </button>
                        <button type="button" data-dir="next" aria-label="Geser ke kanan">
                            @include('blog.partials.icon', ['name' => 'chevron-right', 'class' => 'ic-md'])
                        </button>
                    </div>
                </div>

                <div class="swipe" data-swipe="related" tabindex="0" role="group" aria-label="Daftar artikel lainnya, bisa digeser">
                    @foreach ($related as $rel)
                        <div class="card">
                            <a class="card-link" href="{{ route('blog.show', $rel->slug) }}">
                                @if ($rel->featuredImageUrl())
                                    <span class="thumb">
                                        <img
                                            src="{{ $rel->featuredImageUrl() }}"
                                            alt="{{ $rel->featuredImageAlt() }}"
                                            width="600"
                                            height="338"
                                            loading="lazy"
                                            decoding="async"
                                        >
                                    </span>
                                @endif
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

                <p style="margin:18px 0 0;">
                    <a href="{{ route('blog.index') }}" style="font-weight:700;display:inline-flex;align-items:center;gap:7px;">
                        Lihat semua artikel
                        @include('blog.partials.icon', ['name' => 'arrow-right', 'class' => 'ic-sm'])
                    </a>
                </p>
            </section>
        @endif
    </div>
@endsection
