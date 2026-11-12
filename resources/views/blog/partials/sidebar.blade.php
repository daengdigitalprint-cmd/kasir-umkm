@php
    $popularTags = $popularTags ?? [];
    $popularArticles = $popularArticles ?? null;
    $activeTag = $activeTag ?? null;
@endphp

<aside class="sidebar">
    {{-- ============ Tag Populer ============ --}}
    @if (! empty($popularTags))
        <section class="widget">
            <h2>
                <span class="ic-wrap">@include('blog.partials.icon', ['name' => 'tag', 'class' => 'ic-sm'])</span>
                Tag Populer
            </h2>
            <div class="tagcloud">
                @foreach ($popularTags as $tag)
                    <a
                        href="{{ route('blog.index', ['tag' => $tag['name']]) }}"
                        class="{{ $activeTag === $tag['name'] ? 'on' : '' }}"
                        rel="tag"
                    >
                        {{ $tag['name'] }}<span class="n">{{ $tag['count'] }}</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    {{-- ============ Artikel Populer ============ --}}
    @if ($popularArticles && $popularArticles->isNotEmpty())
        <section class="widget">
            <h2>
                <span class="ic-wrap">@include('blog.partials.icon', ['name' => 'flame', 'class' => 'ic-sm'])</span>
                Artikel Populer
            </h2>
            <ul class="poplist">
                @foreach ($popularArticles as $pop)
                    <li>
                        <a href="{{ route('blog.show', $pop->slug) }}">
                            <span class="num" aria-hidden="true">{{ $loop->iteration }}</span>
                            <span>
                                <span class="t">{{ $pop->title }}</span>
                                <span class="m">{{ $pop->category ?? 'Artikel' }} · {{ $pop->reading_time }} menit baca</span>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- ============ CTA Coba Gratis ============ --}}
    <section class="cta-side">
        <div class="ic-big">@include('blog.partials.icon', ['name' => 'receipt', 'class' => 'ic-xl'])</div>
        <h2>Coba Aplikasi Kasir Gratis</h2>
        <p>Catat penjualan, kelola stok, dan pantau laba usaha langsung dari HP.</p>
        <ul>
            <li>@include('blog.partials.icon', ['name' => 'check', 'class' => 'ic-sm']) Gratis selamanya untuk toko kecil</li>
            <li>@include('blog.partials.icon', ['name' => 'check', 'class' => 'ic-sm']) Cetak struk &amp; laporan otomatis</li>
            <li>@include('blog.partials.icon', ['name' => 'check', 'class' => 'ic-sm']) Tanpa kartu kredit</li>
        </ul>
        <a class="btn btn-lime" href="{{ url('/register') }}">Coba Gratis Sekarang</a>
        <p class="fineprint">Daftar cuma 1 menit · Langsung bisa dipakai</p>
    </section>
</aside>
