<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Blog — Kasir UMKM by MaccaPOS')</title>
    @yield('meta')
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@600;700;800;900&display=swap" rel="stylesheet">
    <style>
        /* Palet diambil dari landing page (header & footer) agar blog menyatu. */
        :root {
            --primary: #533afd;
            --primary-dark: #3f2bd4;
            --primary-soft: #e9e5ff;
            --primary-muted: #f4f2ff;
            --lavender: #d4ceff;
            --nav-strip: #fbfaff;
            --lime: #a0ea71;
            --ink: #0d1738;
            --muted: #5a5869;
            --line: #e6e3f2;
            --surface: #ffffff;
            --container: #f4f4f8;
            --font-sans: 'Inter', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
            --font-display: 'Outfit', var(--font-sans);
        }
        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            margin: 0; background: var(--surface); color: var(--ink);
            font-family: var(--font-sans); line-height: 1.75; -webkit-font-smoothing: antialiased;
        }
        h1, h2, h3, h4 { font-family: var(--font-display); font-weight: 800; letter-spacing: -0.02em; line-height: 1.25; color: var(--ink); }
        a { color: var(--primary); text-decoration: none; }
        a:hover { color: var(--primary-dark); }
        img { max-width: 100%; }

        /* Ikon SVG (menggantikan emoji) */
        .icon { width: 1em; height: 1em; display: inline-block; vertical-align: -0.125em; flex: none; }
        .ic-sm { font-size: 16px; }
        .ic-md { font-size: 18px; }
        .ic-lg { font-size: 22px; }
        .ic-xl { font-size: 26px; }

        .wrap { max-width: 1200px; margin: 0 auto; padding: 0 20px; }
        .label { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .12em; color: var(--muted); }

        /* ====== Header ====== */
        header.site { background: rgba(255,255,255,.92); backdrop-filter: blur(16px); border-bottom: 1px solid var(--line); position: sticky; top: 0; z-index: 40; }
        header.site .bar { display: flex; align-items: center; justify-content: space-between; gap: 16px; height: 72px; }
        .brand { display: flex; align-items: center; gap: 10px; }
        .brand img { height: 38px; width: auto; }
        .brand .sep { width: 1px; height: 22px; background: var(--line); }
        .brand .tag { font-family: var(--font-display); font-weight: 800; font-size: 15px; color: var(--primary); }
        header.site nav.menu { display: none; gap: 26px; align-items: center; }
        header.site nav.menu a { color: #3b3a4a; font-weight: 600; font-size: 15px; }
        header.site nav.menu a:hover { color: var(--primary); }
        .hdr-actions { display: flex; align-items: center; gap: 10px; }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; font-family: var(--font-display); font-weight: 700; font-size: 14px; padding: 11px 22px; border-radius: 8px; border: 1px solid transparent; transition: background .2s ease, color .2s ease, border-color .2s ease; white-space: nowrap; cursor: pointer; }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { background: var(--primary-dark); color: #fff; }
        .btn-ghost { background: #fff; color: var(--ink); border-color: var(--line); }
        .btn-ghost:hover { border-color: var(--primary); color: var(--primary); }
        .btn-lime { background: var(--lime); color: #15300a; }
        .btn-lime:hover { background: #8fd95f; color: #15300a; }
        @media (min-width: 992px) { header.site nav.menu { display: flex; } }
        @media (max-width: 575px) { .hdr-hide-sm { display: none; } }

        /* ====== Hero halaman ====== */
        .page-hero { background: var(--primary-muted); padding: 44px 0 36px; border-bottom: 1px solid var(--line); }
        .page-hero h1 { font-size: clamp(28px, 4.4vw, 44px); margin: 10px 0 12px; }
        .page-hero p.lead { color: var(--muted); font-size: 17px; margin: 0; max-width: 620px; }

        /* ====== Kartu (tanpa bayangan) ====== */
        .card { background: #fff; border: 1px solid var(--line); border-radius: 20px; overflow: hidden; display: flex; flex-direction: column; transition: border-color .2s ease, background .2s ease; }
        .card:hover { border-color: #c3b9fb; background: #fdfdff; }
        .card a.card-link { color: inherit; display: block; padding: 24px; flex: 1; }
        /* Thumbnail berada di dalam tautan kartu (satu tautan untuk gambar +
           judul), lalu ditarik keluar padding agar rata dengan tepi kartu. */
        .card .thumb { display: block; overflow: hidden; background: var(--container); border-bottom: 1px solid var(--line); margin: -24px -24px 18px; }
        .card .thumb img { display: block; width: 100%; aspect-ratio: 16 / 9; object-fit: cover; transition: transform .35s ease; }
        .card:hover .thumb img { transform: scale(1.04); }
        .card h2, .card h3 { font-size: 18px; line-height: 1.45; margin: 12px 0 8px; }
        .card a.card-link:hover h2, .card a.card-link:hover h3 { color: var(--primary); }
        .card p { margin: 0; color: var(--muted); font-size: 14.5px; }
        /* Dua kartu per baris di layar lebar, satu kolom di layar sempit. */
        .grid { display: grid; grid-template-columns: 1fr; gap: 20px; }
        @media (min-width: 640px) { .grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }

        .pill { display: inline-flex; align-items: center; gap: 5px; background: var(--primary-soft); color: var(--primary-dark); font-size: 12px; font-weight: 700; padding: 4px 12px; border-radius: 999px; }
        .pill-lime { background: #eefbe3; color: #3f6212; }
        .pill-orange { background: #ffeede; color: #c2410c; }
        .meta { color: var(--muted); font-size: 13.5px; }

        /* ====== Layout isi + sidebar ====== */
        .layout { display: grid; grid-template-columns: 1fr; gap: 28px; padding: 28px 0 10px; }
        .sidebar { display: flex; flex-direction: column; gap: 18px; }
        @media (min-width: 1024px) {
            .layout { grid-template-columns: minmax(0,1fr) 340px; gap: 32px; align-items: start; }
            /* Sticky: sidebar ikut diam saat scroll sampai kolom artikel habis.
               Nilai `top` diatur skrip di bawah karena sidebar bisa lebih
               tinggi dari layar (maka yang ditempelkan adalah sisi bawahnya). */
            .sidebar { position: sticky; top: 92px; }
        }
        .widget { background: #fff; border: 1px solid var(--line); border-radius: 20px; padding: 22px; }
        .widget > h2 { font-size: 16px; margin: 0 0 14px; display: flex; align-items: center; gap: 9px; }
        .widget > h2 .ic-wrap { width: 30px; height: 30px; border-radius: 9px; background: var(--primary-soft); color: var(--primary); display: inline-flex; align-items: center; justify-content: center; }

        .tagcloud { display: flex; flex-wrap: wrap; gap: 8px; }
        .tagcloud a { display: inline-flex; align-items: center; gap: 6px; background: var(--container); color: #3b3a4a; border: 1px solid transparent; font-size: 13px; font-weight: 600; padding: 6px 13px; border-radius: 999px; transition: background .2s ease, color .2s ease; }
        .tagcloud a:hover, .tagcloud a.on { background: var(--primary); color: #fff; }
        .tagcloud a .n { font-size: 11px; opacity: .6; font-weight: 700; }

        .poplist { list-style: none; margin: 0; padding: 0; }
        .poplist li + li { margin-top: 6px; padding-top: 14px; border-top: 1px solid var(--line); }
        .poplist a { display: flex; gap: 12px; align-items: flex-start; color: inherit; }
        .poplist .num { flex: none; width: 28px; height: 28px; border-radius: 9px; background: var(--primary-muted); color: var(--primary); font-family: var(--font-display); font-weight: 800; font-size: 13px; display: flex; align-items: center; justify-content: center; }
        .poplist a:hover .num { background: var(--primary); color: #fff; }
        .poplist .t { font-size: 14.5px; font-weight: 600; line-height: 1.5; margin: 0 0 3px; display: block; }
        .poplist a:hover .t { color: var(--primary); }
        .poplist .m { font-size: 12px; color: var(--muted); display: block; }

        /* ====== CTA sidebar ====== */
        .cta-side { border-radius: 20px; padding: 26px 24px; background: var(--primary); color: #fff; text-align: center; }
        .cta-side .ic-big { width: 52px; height: 52px; margin: 0 auto 14px; border-radius: 14px; background: rgba(255,255,255,.16); display: flex; align-items: center; justify-content: center; color: #fff; }
        .cta-side h2 { color: #fff; font-size: 20px; margin: 0 0 8px; }
        .cta-side p { color: #ddd7ff; font-size: 14px; margin: 0 0 18px; }
        .cta-side ul { list-style: none; margin: 0 0 18px; padding: 0; text-align: left; display: inline-block; }
        .cta-side ul li { font-size: 13.5px; color: #eceaff; margin-bottom: 8px; display: flex; gap: 9px; align-items: center; }
        .cta-side ul li .icon { color: var(--lime); }
        .cta-side .btn { width: 100%; }
        .cta-side .fineprint { margin: 12px 0 0; font-size: 12px; color: #c9c1fb; }

        /* ====== Footer (mengikuti landing) ====== */
        footer.site { margin-top: 56px; padding-bottom: 8px; }
        .foot-block { background: var(--lavender); border-radius: 24px; overflow: hidden; }
        .foot-nav { background: var(--nav-strip); display: flex; flex-wrap: wrap; }
        .foot-nav a { flex: 1 1 auto; text-align: center; padding: 14px 18px; font-size: 14px; font-weight: 600; color: var(--ink); border-right: 1px solid var(--line); }
        .foot-nav a:last-child { border-right: 0; }
        .foot-nav a:hover { color: var(--primary); background: #fff; }
        /* Di layar sempit dibuat dua kolom agar barisnya rata, tidak menyisakan
           satu item melebar sendiri di baris terakhir. */
        @media (max-width: 767px) {
            .foot-nav { display: grid; grid-template-columns: 1fr 1fr; }
            .foot-nav a { border-bottom: 1px solid var(--line); }
            .foot-nav a:nth-child(2n) { border-right: 0; }
            .foot-nav a:nth-last-child(-n+2) { border-bottom: 0; }
        }
        .foot-main { display: grid; gap: 28px; padding: clamp(26px, 4vw, 44px); }
        @media (min-width: 900px) { .foot-main { grid-template-columns: 1.3fr 1fr; } }
        .foot-main h2 { font-size: clamp(24px, 3.2vw, 36px); margin: 10px 0 12px; }
        .foot-main > div > p { color: #4a4860; margin: 0 0 22px; max-width: 420px; }
        .foot-acts { display: flex; flex-wrap: wrap; gap: 10px; }
        .foot-contact { display: grid; gap: 16px; align-content: start; }
        .foot-contact .row { display: flex; gap: 13px; align-items: flex-start; color: var(--ink); font-size: 16px; font-weight: 700; font-family: var(--font-display); }
        .foot-contact a.row:hover { color: var(--primary); }
        .foot-contact .badge-ic { flex: none; width: 38px; height: 38px; border-radius: 999px; background: #fff; color: var(--primary); display: flex; align-items: center; justify-content: center; }
        .foot-links { display: flex; flex-wrap: wrap; background: var(--nav-strip); border-top: 1px solid var(--line); }
        .foot-links a { flex: 1 1 180px; display: flex; align-items: center; justify-content: space-between; gap: 14px; padding: 16px 22px; font-size: 12px; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; color: var(--ink); border-right: 1px solid var(--line); }
        .foot-links a:last-child { border-right: 0; }
        .foot-links a:hover { color: var(--primary); background: #fff; }
        .foot-links a .lbl { display: inline-flex; align-items: center; gap: 9px; }
        .foot-bottom { display: flex; flex-wrap: wrap; gap: 10px 20px; justify-content: space-between; align-items: center; padding: 24px 0 8px; font-size: 13.5px; color: var(--muted); }
        .foot-bottom nav { display: flex; flex-wrap: wrap; gap: 8px 18px; }
        .foot-bottom a { color: var(--muted); }
        .foot-bottom a:hover { color: var(--primary); }

        .preview-banner { background: #ffeede; color: #9a3412; font-size: 14px; font-weight: 700; text-align: center; padding: 10px 16px; }
        .crumbs { font-size: 13px; color: var(--muted); padding: 18px 0 0; display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
        .crumbs a { color: var(--muted); font-weight: 500; }
        .crumbs a:hover { color: var(--primary); }
        .crumbs .s { color: #bdb8d0; }

        /* ====== Carousel geser (artikel lainnya) ====== */
        .swipe-head { display: flex; align-items: center; justify-content: space-between; gap: 14px; margin: 48px 0 18px; }
        .swipe-head h2 { font-size: 23px; margin: 0; }
        .swipe-nav { display: none; gap: 8px; }
        .swipe-nav button { width: 38px; height: 38px; border-radius: 999px; border: 1px solid var(--line); background: #fff; color: var(--ink); display: inline-flex; align-items: center; justify-content: center; transition: border-color .2s ease, color .2s ease; }
        .swipe-nav button:hover:not(:disabled) { border-color: var(--primary); color: var(--primary); }
        .swipe-nav button:disabled { opacity: .35; cursor: default; }
        @media (min-width: 768px) { .swipe-nav { display: flex; } }

        .swipe {
            display: flex; gap: 18px; overflow-x: auto; scroll-snap-type: x mandatory;
            -webkit-overflow-scrolling: touch; scrollbar-width: none;
            padding: 2px 20px 6px; margin: 0 -20px; scroll-padding-left: 20px;
        }
        .swipe::-webkit-scrollbar { display: none; }
        .swipe > .card { flex: 0 0 86%; scroll-snap-align: start; }
        @media (min-width: 640px) { .swipe > .card { flex-basis: 48%; } }
        @media (min-width: 1024px) { .swipe > .card { flex-basis: 31.5%; } }
    </style>
</head>
<body>
    <header class="site">
        <div class="wrap bar">
            <a class="brand" href="{{ url('/') }}" aria-label="Kasir UMKM by MaccaPOS">
                <img src="{{ \App\Support\LandingSeo::maskotAssetUrl() }}" alt="Logo Kasir UMKM by MaccaPOS" width="150" height="38">
                <span class="sep" aria-hidden="true"></span>
                <span class="tag">Blog</span>
            </a>

            <nav class="menu" aria-label="Menu utama">
                <a href="{{ url('/') }}">Beranda</a>
                <a href="{{ url('/#fitur') }}">Fitur</a>
                <a href="{{ url('/#harga') }}">Harga</a>
                <a href="{{ url('/panduan') }}">Panduan</a>
                <a href="{{ route('blog.index') }}">Blog</a>
            </nav>

            <div class="hdr-actions">
                <a class="btn btn-ghost hdr-hide-sm" href="{{ url('/login') }}">Masuk</a>
                <a class="btn btn-primary" href="{{ url('/register') }}">Coba Gratis</a>
            </div>
        </div>
    </header>

    @yield('content')

    <footer class="site">
        <div class="wrap">
            <div class="foot-block">
                <nav class="foot-nav" aria-label="Menu footer">
                    <a href="{{ url('/#fitur') }}">Fitur</a>
                    <a href="{{ url('/#harga') }}">Harga</a>
                    <a href="{{ route('blog.index') }}">Blog</a>
                    <a href="{{ url('/refund') }}">Kebijakan Refund</a>
                    <a href="{{ url('/terms') }}">Syarat &amp; Ketentuan</a>
                    <a href="{{ url('/panduan') }}">Panduan</a>
                </nav>

                <div class="foot-main">
                    <div>
                        <p class="label" style="margin:0">Mulai sekarang</p>
                        <h2>Siap Kelola Usaha Lebih Rapi?</h2>
                        <p>Daftar gratis dalam 1 menit dan mulai catat penjualan hari ini juga.</p>
                        <div class="foot-acts">
                            <a class="btn btn-primary" href="{{ url('/register') }}">Mulai Gratis</a>
                            <a class="btn btn-ghost" href="{{ url('/panduan') }}">Lihat Panduan</a>
                        </div>
                    </div>

                    <div class="foot-contact">
                        <p class="label" style="margin:0">Hubungi kami</p>
                        <a class="row" href="https://wa.me/6285242766676" target="_blank" rel="noopener">
                            <span class="badge-ic">@include('blog.partials.icon', ['name' => 'phone', 'class' => 'ic-md'])</span>
                            <span>+62 852-4276-6676</span>
                        </a>
                        <a class="row" href="mailto:info@umkmkasir.id">
                            <span class="badge-ic">@include('blog.partials.icon', ['name' => 'mail', 'class' => 'ic-md'])</span>
                            <span>info@umkmkasir.id</span>
                        </a>
                        <span class="row">
                            <span class="badge-ic">@include('blog.partials.icon', ['name' => 'map-pin', 'class' => 'ic-md'])</span>
                            <span>Jl. Benteng Somba Opu, Tanjung Permai No 30 Gowa, Sulawesi Selatan</span>
                        </span>
                        <span class="row">
                            <span class="badge-ic">@include('blog.partials.icon', ['name' => 'clock', 'class' => 'ic-md'])</span>
                            <span>Senin – Jumat, 08.00 – 17.00 WITA</span>
                        </span>
                    </div>
                </div>

                <div class="foot-links">
                    <a href="https://wa.me/6285242766676" target="_blank" rel="noopener">
                        <span class="lbl">@include('blog.partials.icon', ['name' => 'whatsapp', 'class' => 'ic-sm']) WhatsApp</span>
                        @include('blog.partials.icon', ['name' => 'arrow-up-right', 'class' => 'ic-sm'])
                    </a>
                    <a href="mailto:info@umkmkasir.id">
                        <span class="lbl">@include('blog.partials.icon', ['name' => 'mail', 'class' => 'ic-sm']) Email</span>
                        @include('blog.partials.icon', ['name' => 'arrow-up-right', 'class' => 'ic-sm'])
                    </a>
                    <a href="{{ url('/panduan') }}">
                        <span class="lbl">@include('blog.partials.icon', ['name' => 'book', 'class' => 'ic-sm']) Panduan</span>
                        @include('blog.partials.icon', ['name' => 'arrow-up-right', 'class' => 'ic-sm'])
                    </a>
                    <a href="{{ route('blog.index') }}">
                        <span class="lbl">@include('blog.partials.icon', ['name' => 'list', 'class' => 'ic-sm']) Semua Artikel</span>
                        @include('blog.partials.icon', ['name' => 'arrow-up-right', 'class' => 'ic-sm'])
                    </a>
                </div>
            </div>

            <div class="foot-bottom">
                <p style="margin:0">Hak Cipta © {{ date('Y') }} Kasir UMKM by MaccaPOS. Semua Hak Dilindungi.</p>
                <nav>
                    <a href="{{ url('/terms') }}">Syarat &amp; Ketentuan</a>
                    <a href="{{ url('/privacy') }}">Kebijakan Privasi</a>
                    <a href="{{ url('/refund') }}">Kebijakan Refund</a>
                    <a href="{{ url('/panduan') }}">Panduan</a>
                </nav>
            </div>
        </div>
    </footer>

    <script>
        /**
         * Sidebar sticky untuk desktop.
         * Sidebar bisa lebih tinggi dari layar; kalau `top` dibiarkan positif,
         * bagian bawahnya (CTA) tidak pernah terlihat. Jadi saat sidebar lebih
         * tinggi dari viewport, yang ditempelkan adalah sisi BAWAHNYA.
         */
        (function () {
            var sidebar = document.querySelector('.sidebar');
            if (!sidebar) return;

            var HEADER = 92;
            var GAP = 20;

            function apply() {
                if (window.innerWidth < 1024) {
                    sidebar.style.top = '';
                    return;
                }
                var h = sidebar.offsetHeight;
                var vh = window.innerHeight;
                sidebar.style.top = (h + HEADER + GAP <= vh)
                    ? HEADER + 'px'
                    : (vh - h - GAP) + 'px';
            }

            apply();
            window.addEventListener('resize', apply);
            if (window.ResizeObserver) {
                new ResizeObserver(apply).observe(sidebar);
            }
        })();

        /** Tombol panah untuk carousel "Artikel Lainnya" (geser/swipe di sentuh). */
        (function () {
            document.querySelectorAll('[data-swipe]').forEach(function (track) {
                var nav = document.querySelector('[data-swipe-nav="' + track.dataset.swipe + '"]');
                if (!nav) return;

                var prev = nav.querySelector('[data-dir="prev"]');
                var next = nav.querySelector('[data-dir="next"]');

                function step() {
                    var card = track.querySelector('.card');
                    return card ? card.offsetWidth + 18 : track.clientWidth * 0.8;
                }

                function sync() {
                    // scroll-snap berhenti sejauh padding kiri track, bukan di 0,
                    // jadi ambang "sudah paling kiri" dihitung dari padding itu.
                    var pad = parseFloat(getComputedStyle(track).paddingLeft) || 0;
                    var max = track.scrollWidth - track.clientWidth - 2;
                    prev.disabled = track.scrollLeft <= pad + 4;
                    next.disabled = track.scrollLeft >= max;
                }

                prev.addEventListener('click', function () { track.scrollBy({ left: -step(), behavior: 'smooth' }); });
                next.addEventListener('click', function () { track.scrollBy({ left: step(), behavior: 'smooth' }); });
                track.addEventListener('scroll', sync, { passive: true });
                window.addEventListener('resize', sync);
                sync();
            });
        })();
    </script>
</body>
</html>
