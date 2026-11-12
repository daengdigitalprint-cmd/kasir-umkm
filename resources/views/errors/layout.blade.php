{{--
    Layout halaman error — sengaja berdiri sendiri:
    tanpa Vite, tanpa query database, dan hanya memakai url() (bukan named
    route) supaya tetap tampil walau aset atau rute sedang bermasalah.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Terjadi Kesalahan') — Kasir UMKM by MaccaPOS</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@600;700;800;900&display=swap" rel="stylesheet">
    <style>
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
            --container: #f4f4f8;
            --font-sans: 'Inter', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
            --font-display: 'Outfit', var(--font-sans);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: flex; flex-direction: column;
            background: #fff; color: var(--ink); font-family: var(--font-sans);
            line-height: 1.75; -webkit-font-smoothing: antialiased;
        }
        h1, h2, h3 { font-family: var(--font-display); font-weight: 800; letter-spacing: -0.02em; line-height: 1.25; margin: 0; }
        a { color: var(--primary); text-decoration: none; }
        a:hover { color: var(--primary-dark); }
        .wrap { max-width: 1100px; margin: 0 auto; padding: 0 20px; width: 100%; }
        .icon { width: 1em; height: 1em; display: inline-block; vertical-align: -0.125em; flex: none; }
        .ic-sm { font-size: 16px; }
        .ic-md { font-size: 18px; }

        /* Header ringkas */
        header.site { border-bottom: 1px solid var(--line); background: #fff; }
        header.site .bar { display: flex; align-items: center; justify-content: space-between; gap: 16px; height: 72px; }
        .brand { display: flex; align-items: center; gap: 10px; }
        .brand img { height: 36px; width: auto; }
        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            font-family: var(--font-display); font-weight: 700; font-size: 14px;
            padding: 11px 22px; border-radius: 8px; border: 1px solid transparent;
            transition: background .2s ease, color .2s ease, border-color .2s ease; white-space: nowrap;
        }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { background: var(--primary-dark); color: #fff; }
        .btn-ghost { background: #fff; color: var(--ink); border-color: var(--line); }
        .btn-ghost:hover { border-color: var(--primary); color: var(--primary); }

        /* Isi */
        main { flex: 1; display: flex; align-items: center; padding: 48px 0 64px; background: linear-gradient(180deg, var(--primary-muted) 0%, #fff 420px); }
        .panel { display: grid; gap: 36px; align-items: center; }
        @media (min-width: 900px) { .panel { grid-template-columns: 1.05fr .95fr; gap: 48px; } }

        .code {
            font-family: var(--font-display); font-weight: 900; line-height: .9;
            font-size: clamp(90px, 17vw, 168px); letter-spacing: -0.05em;
            color: var(--primary); margin: 0 0 6px;
        }
        .eyebrow { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .14em; color: var(--muted); margin: 0 0 10px; }
        .title { font-size: clamp(25px, 3.6vw, 38px); margin: 0 0 14px; }
        .desc { color: var(--muted); font-size: 16.5px; margin: 0 0 26px; max-width: 480px; }
        .acts { display: flex; flex-wrap: wrap; gap: 10px; }

        /* Kartu tautan bantuan */
        .helps { background: #fff; border: 1px solid var(--line); border-radius: 22px; overflow: hidden; }
        .helps > p { margin: 0; padding: 16px 22px; background: var(--nav-strip); border-bottom: 1px solid var(--line); font-family: var(--font-display); font-weight: 800; font-size: 14px; }
        .helps a {
            display: flex; align-items: center; gap: 14px; padding: 15px 22px;
            color: var(--ink); border-bottom: 1px solid var(--line); transition: background .2s ease;
        }
        .helps a:last-child { border-bottom: 0; }
        .helps a:hover { background: var(--primary-muted); }
        .helps .badge-ic { flex: none; width: 38px; height: 38px; border-radius: 11px; background: var(--primary-soft); color: var(--primary); display: flex; align-items: center; justify-content: center; }
        .helps .t { font-family: var(--font-display); font-weight: 700; font-size: 14.5px; display: block; line-height: 1.35; }
        .helps .m { font-size: 12.5px; color: var(--muted); display: block; line-height: 1.4; }
        .helps .go { margin-left: auto; color: var(--muted); }
        .helps a:hover .go { color: var(--primary); }

        /* Footer ringkas */
        footer.site { border-top: 1px solid var(--line); background: var(--nav-strip); }
        footer.site .bar { display: flex; flex-wrap: wrap; gap: 10px 20px; justify-content: space-between; align-items: center; padding: 20px 0; font-size: 13.5px; color: var(--muted); }
        footer.site nav { display: flex; flex-wrap: wrap; gap: 8px 18px; }
        footer.site a { color: var(--muted); }
        footer.site a:hover { color: var(--primary); }
    </style>
</head>
<body>
    <header class="site">
        <div class="wrap bar">
            <a class="brand" href="{{ url('/') }}" aria-label="Kasir UMKM by MaccaPOS">
                <img src="{{ asset('logo-macca-sm.webp') }}" alt="Logo Kasir UMKM by MaccaPOS" width="144" height="36"
                     onerror="this.onerror=null;this.src='{{ asset('logo-macca.png') }}'">
            </a>
            <a class="btn btn-primary" href="{{ url('/') }}">Ke Beranda</a>
        </div>
    </header>

    <main>
        <div class="wrap">
            <div class="panel">
                <div>
                    <p class="eyebrow">@yield('eyebrow', 'Kesalahan')</p>
                    <p class="code">@yield('code', '500')</p>
                    <h1 class="title">@yield('heading', 'Terjadi Kesalahan')</h1>
                    <p class="desc">@yield('message')</p>
                    <div class="acts">
                        @yield('actions')
                    </div>
                </div>

                <div class="helps">
                    <p>Mungkin yang Anda cari</p>

                    <a href="{{ url('/') }}">
                        <span class="badge-ic">@include('blog.partials.icon', ['name' => 'store', 'class' => 'ic-md'])</span>
                        <span>
                            <span class="t">Beranda</span>
                            <span class="m">Kenali aplikasi kasir untuk UMKM</span>
                        </span>
                        <span class="go">@include('blog.partials.icon', ['name' => 'arrow-right', 'class' => 'ic-sm'])</span>
                    </a>

                    <a href="{{ url('/blog') }}">
                        <span class="badge-ic">@include('blog.partials.icon', ['name' => 'list', 'class' => 'ic-md'])</span>
                        <span>
                            <span class="t">Blog &amp; Tips UMKM</span>
                            <span class="m">Artikel seputar kasir, stok, dan keuangan</span>
                        </span>
                        <span class="go">@include('blog.partials.icon', ['name' => 'arrow-right', 'class' => 'ic-sm'])</span>
                    </a>

                    <a href="{{ url('/panduan') }}">
                        <span class="badge-ic">@include('blog.partials.icon', ['name' => 'book', 'class' => 'ic-md'])</span>
                        <span>
                            <span class="t">Panduan Pemakaian</span>
                            <span class="m">Langkah memulai dari nol</span>
                        </span>
                        <span class="go">@include('blog.partials.icon', ['name' => 'arrow-right', 'class' => 'ic-sm'])</span>
                    </a>

                    <a href="https://wa.me/6285242766676" target="_blank" rel="noopener">
                        <span class="badge-ic">@include('blog.partials.icon', ['name' => 'whatsapp', 'class' => 'ic-md'])</span>
                        <span>
                            <span class="t">Hubungi Kami</span>
                            <span class="m">Senin – Jumat, 08.00 – 17.00 WITA</span>
                        </span>
                        <span class="go">@include('blog.partials.icon', ['name' => 'arrow-up-right', 'class' => 'ic-sm'])</span>
                    </a>
                </div>
            </div>
        </div>
    </main>

    <footer class="site">
        <div class="wrap bar">
            <p style="margin:0">Hak Cipta © {{ date('Y') }} Kasir UMKM by MaccaPOS.</p>
            <nav>
                <a href="{{ url('/') }}">Beranda</a>
                <a href="{{ url('/blog') }}">Blog</a>
                <a href="{{ url('/panduan') }}">Panduan</a>
                <a href="{{ url('/terms') }}">Syarat &amp; Ketentuan</a>
                <a href="{{ url('/privacy') }}">Kebijakan Privasi</a>
            </nav>
        </div>
    </footer>
</body>
</html>
