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
        /* Palet sama dengan aplikasi (resources/css/app.css) */
        :root {
            --primary: #7943e5;
            --primary-dark: #5b21b6;
            --primary-soft: #ede9fe;
            --primary-muted: #f5f3ff;
            --secondary: #a0ea71;
            --orange: #f97316;
            --orange-soft: #ffedd5;
            --ink: #0f172a;
            --muted: #64748b;
            --line: #e2e8f0;
            --surface: #f8fafc;
            --container: #f1f5f9;
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

        .wrap { max-width: 1200px; margin: 0 auto; padding: 0 20px; }
        .label { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .12em; color: var(--muted); }

        /* ====== Header (mengikuti landing: putih, logo MaccaPOS, tombol ungu) ====== */
        header.site { background: rgba(255,255,255,.88); backdrop-filter: blur(16px); border-bottom: 1px solid var(--line); position: sticky; top: 0; z-index: 40; }
        header.site .bar { display: flex; align-items: center; justify-content: space-between; gap: 16px; height: 72px; }
        .brand { display: flex; align-items: center; gap: 10px; }
        .brand img { height: 38px; width: auto; }
        .brand .sep { width: 1px; height: 22px; background: var(--line); }
        .brand .tag { font-family: var(--font-display); font-weight: 800; font-size: 15px; color: var(--primary); }
        header.site nav.menu { display: none; gap: 26px; align-items: center; }
        header.site nav.menu a { color: #334155; font-weight: 600; font-size: 15px; }
        header.site nav.menu a:hover { color: var(--primary); }
        .hdr-actions { display: flex; align-items: center; gap: 10px; }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; font-family: var(--font-display); font-weight: 700; font-size: 14px; padding: 11px 22px; border-radius: 999px; border: 1px solid transparent; transition: transform .2s ease, box-shadow .2s ease, background .2s ease; white-space: nowrap; }
        .btn:hover { transform: translateY(-1px); }
        .btn-primary { background: var(--primary); color: #fff; box-shadow: 0 10px 24px -10px rgba(121,67,229,.7); }
        .btn-primary:hover { background: var(--primary-dark); color: #fff; }
        .btn-ghost { background: #fff; color: var(--ink); border-color: var(--line); }
        .btn-ghost:hover { border-color: var(--primary); color: var(--primary); }
        .btn-lime { background: var(--secondary); color: #1a3a0a; }
        .btn-lime:hover { background: #8fd95f; color: #1a3a0a; }
        @media (min-width: 992px) { header.site nav.menu { display: flex; } }
        @media (max-width: 575px) { .hdr-hide-sm { display: none; } }

        /* ====== Hero halaman ====== */
        .page-hero { background: linear-gradient(180deg, var(--primary-muted) 0%, var(--surface) 100%); padding: 44px 0 36px; border-bottom: 1px solid var(--line); }
        .page-hero h1 { font-size: clamp(28px, 4.4vw, 44px); margin: 10px 0 12px; }
        .page-hero p.lead { color: var(--muted); font-size: 17px; margin: 0; max-width: 620px; }

        /* ====== Kartu ====== */
        .card { background: #fff; border: 1px solid var(--line); border-radius: 24px; overflow: hidden; display: flex; flex-direction: column; transition: box-shadow .3s ease, transform .3s ease, border-color .3s ease; }
        .card:hover { box-shadow: 0 18px 40px -22px rgba(121,67,229,.45); transform: translateY(-3px); border-color: #d8ccf7; }
        .card a.card-link { color: inherit; display: block; padding: 24px; flex: 1; }
        .card h2, .card h3 { font-size: 18px; line-height: 1.45; margin: 12px 0 8px; }
        .card a.card-link:hover h2, .card a.card-link:hover h3 { color: var(--primary); }
        .card p { margin: 0; color: var(--muted); font-size: 14.5px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(290px, 1fr)); gap: 22px; }

        .pill { display: inline-flex; align-items: center; gap: 5px; background: var(--primary-soft); color: var(--primary-dark); font-size: 12px; font-weight: 700; padding: 4px 12px; border-radius: 999px; }
        .pill-lime { background: #eefbe3; color: #3f6212; }
        .pill-orange { background: var(--orange-soft); color: #c2410c; }
        .meta { color: var(--muted); font-size: 13.5px; }

        /* ====== Layout isi + sidebar ====== */
        .layout { display: grid; grid-template-columns: 1fr; gap: 28px; align-items: start; padding: 28px 0 10px; }
        /* Sidebar sengaja tidak sticky: isinya lebih tinggi dari viewport,
           sticky akan memaku CTA di bawah layar sehingga tak pernah terlihat. */
        @media (min-width: 1024px) { .layout { grid-template-columns: minmax(0,1fr) 340px; gap: 32px; } }
        .sidebar { display: flex; flex-direction: column; gap: 20px; }
        .widget { background: #fff; border: 1px solid var(--line); border-radius: 24px; padding: 22px; }
        .widget > h2 { font-size: 16px; margin: 0 0 14px; display: flex; align-items: center; gap: 8px; }
        .widget > h2 .ic { width: 28px; height: 28px; border-radius: 9px; background: var(--primary-soft); display: inline-flex; align-items: center; justify-content: center; font-size: 14px; }

        .tagcloud { display: flex; flex-wrap: wrap; gap: 8px; }
        .tagcloud a { display: inline-flex; align-items: center; gap: 5px; background: var(--container); color: #334155; border: 1px solid transparent; font-size: 13px; font-weight: 600; padding: 6px 13px; border-radius: 999px; transition: all .2s ease; }
        .tagcloud a:hover, .tagcloud a.on { background: var(--primary); color: #fff; }
        .tagcloud a .n { font-size: 11px; opacity: .65; font-weight: 700; }

        .poplist { list-style: none; margin: 0; padding: 0; }
        .poplist li + li { margin-top: 6px; padding-top: 14px; border-top: 1px solid var(--line); }
        .poplist a { display: flex; gap: 12px; align-items: flex-start; color: inherit; }
        .poplist .num { flex: none; width: 28px; height: 28px; border-radius: 9px; background: var(--primary-muted); color: var(--primary); font-family: var(--font-display); font-weight: 800; font-size: 13px; display: flex; align-items: center; justify-content: center; }
        .poplist a:hover .num { background: var(--primary); color: #fff; }
        .poplist .t { font-size: 14.5px; font-weight: 600; line-height: 1.5; margin: 0 0 3px; }
        .poplist a:hover .t { color: var(--primary); }
        .poplist .m { font-size: 12px; color: var(--muted); }

        /* ====== CTA sidebar ====== */
        .cta-side { border-radius: 24px; padding: 26px 24px; background: linear-gradient(160deg, #7943e5 0%, #5b21b6 100%); color: #fff; text-align: center; box-shadow: 0 22px 44px -24px rgba(91,33,182,.9); }
        .cta-side .ic-big { width: 54px; height: 54px; margin: 0 auto 14px; border-radius: 18px; background: rgba(255,255,255,.16); display: flex; align-items: center; justify-content: center; font-size: 26px; }
        .cta-side h2 { color: #fff; font-size: 20px; margin: 0 0 8px; }
        .cta-side p { color: #e9e2fb; font-size: 14px; margin: 0 0 18px; }
        .cta-side ul { list-style: none; margin: 0 0 18px; padding: 0; text-align: left; display: inline-block; }
        .cta-side ul li { font-size: 13.5px; color: #efeaff; margin-bottom: 7px; display: flex; gap: 8px; align-items: center; }
        .cta-side ul li span.ck { color: var(--secondary); font-weight: 900; }
        .cta-side .btn { width: 100%; }
        .cta-side .fineprint { margin: 12px 0 0; font-size: 12px; color: #cdc1f0; }

        /* ====== Footer (mengikuti landing: lavender + CTA) ====== */
        footer.site { margin-top: 56px; background: linear-gradient(180deg, var(--surface) 0%, var(--primary-muted) 45%); border-top: 1px solid var(--line); }
        .foot-cta { background: #fff; border: 1px solid #e4dafb; border-radius: 28px; padding: clamp(24px, 4vw, 40px); margin-top: -28px; box-shadow: 0 24px 48px -30px rgba(91,33,182,.5); display: grid; gap: 22px; }
        @media (min-width: 768px) { .foot-cta { grid-template-columns: 1.4fr 1fr; align-items: center; } }
        .foot-cta h2 { font-size: clamp(22px, 3vw, 30px); margin: 8px 0 10px; }
        .foot-cta p { color: var(--muted); margin: 0 0 20px; }
        .foot-cta .acts { display: flex; flex-wrap: wrap; gap: 10px; }
        .foot-contact { display: grid; gap: 12px; }
        .foot-contact a, .foot-contact span { display: flex; gap: 10px; align-items: flex-start; color: #334155; font-size: 14px; }
        .foot-contact .ic { flex: none; width: 34px; height: 34px; border-radius: 11px; background: var(--primary-muted); display: flex; align-items: center; justify-content: center; font-size: 15px; }
        .foot-bottom { display: flex; flex-wrap: wrap; gap: 10px 20px; justify-content: space-between; align-items: center; padding: 26px 0; margin-top: 32px; border-top: 1px solid #ddd0f7; font-size: 13.5px; color: var(--muted); }
        .foot-bottom nav { display: flex; flex-wrap: wrap; gap: 8px 18px; }
        .foot-bottom a { color: var(--muted); }
        .foot-bottom a:hover { color: var(--primary); }

        .preview-banner { background: var(--orange-soft); color: #9a3412; font-size: 14px; font-weight: 700; text-align: center; padding: 10px 16px; }
        .crumbs { font-size: 13px; color: var(--muted); padding: 18px 0 0; display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
        .crumbs a { color: var(--muted); font-weight: 500; }
        .crumbs a:hover { color: var(--primary); }
        .crumbs .s { color: #cbd5e1; }
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
            <section class="foot-cta">
                <div>
                    <p class="label" style="margin:0">Mulai sekarang</p>
                    <h2>Siap Kelola Usaha Lebih Rapi?</h2>
                    <p>Daftar gratis dalam 1 menit dan mulai catat penjualan hari ini juga. Tanpa kartu kredit.</p>
                    <div class="acts">
                        <a class="btn btn-primary" href="{{ url('/register') }}">Mulai Gratis</a>
                        <a class="btn btn-ghost" href="{{ url('/panduan') }}">Lihat Panduan</a>
                    </div>
                </div>
                <div class="foot-contact">
                    <p class="label" style="margin:0 0 2px">Hubungi kami</p>
                    <a href="https://wa.me/6285242766676" target="_blank" rel="noopener">
                        <span class="ic" aria-hidden="true">📱</span>
                        <span>+62 852-4276-6676</span>
                    </a>
                    <a href="mailto:info@umkmkasir.id">
                        <span class="ic" aria-hidden="true">✉️</span>
                        <span>info@umkmkasir.id</span>
                    </a>
                    <span>
                        <span class="ic" aria-hidden="true">🕗</span>
                        <span>Senin – Jumat, 08.00 – 17.00 WITA</span>
                    </span>
                </div>
            </section>

            <div class="foot-bottom">
                <p style="margin:0">Hak Cipta © {{ date('Y') }} Kasir UMKM by MaccaPOS. Semua Hak Dilindungi.</p>
                <nav>
                    <a href="{{ url('/') }}">Beranda</a>
                    <a href="{{ route('blog.index') }}">Blog</a>
                    <a href="{{ url('/panduan') }}">Panduan</a>
                    <a href="{{ url('/terms') }}">Syarat &amp; Ketentuan</a>
                    <a href="{{ url('/privacy') }}">Kebijakan Privasi</a>
                    <a href="{{ url('/refund') }}">Kebijakan Refund</a>
                </nav>
            </div>
        </div>
    </footer>
</body>
</html>
