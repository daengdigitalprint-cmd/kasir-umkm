<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Kasir UMKM by MaccaPOS')</title>
    <meta name="description" content="@yield('description', 'Aplikasi kasir untuk UMKM: catat penjualan, stok, dan laporan dari HP. Cek kecocokannya untuk usaha Anda dalam 1 menit.')">
    {{-- Halaman iklan: tidak ikut diindeks agar tidak bersaing dengan beranda di hasil pencarian. --}}
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#f5f2fc">
    <meta property="og:title" content="@yield('title', 'Kasir UMKM by MaccaPOS')">
    <meta property="og:site_name" content="Kasir UMKM by MaccaPOS">
    <meta property="og:image" content="{{ asset('og-share.jpg') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32.png') }}">

    {{-- Tanpa JavaScript semua langkah form tampil sekaligus; dengan JavaScript satu langkah per layar. --}}
    <script>document.documentElement.classList.add('js');</script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    {{-- Semua huruf dari Google Fonts agar tampil sama di setiap HP:
         Montserrat (judul & tombol — lebar dan tegas, senada bentuk logo),
         Inter (isi — paling terbaca di layar kecil), DM Mono (label struk). --}}
    <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@500&family=Inter:wght@400;500;600;700;800&family=Montserrat:wght@700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --bg: #f5f2fc;
            --surface: #ffffff;
            --ink: #17122e;
            --muted: #5d5873;
            --line: #e3def2;
            --purple: #7943e5;
            --purple-deep: #5a2bc0;
            --purple-soft: #efe8ff;
            --lime: #a0ea71;
            --lime-ink: #1d4a08;
            --danger: #b42318;
            --wa: #128c4a;
            --radius: 16px;
            --font: 'Inter', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
            --display: 'Montserrat', var(--font);
            --mono: 'DM Mono', ui-monospace, 'Roboto Mono', Menlo, Consolas, monospace;
        }
        *, *::before, *::after { box-sizing: border-box; }
        html { scroll-behavior: smooth; -webkit-text-size-adjust: 100%; }
        body {
            margin: 0; background: var(--bg); color: var(--ink);
            font-family: var(--font); font-size: 16px; font-weight: 500; line-height: 1.6;
            -webkit-font-smoothing: antialiased; overflow-wrap: break-word;
        }
        img { max-width: 100%; height: auto; display: block; }
        a { color: var(--purple-deep); }
        h1, h2, h3 { margin: 0; font-family: var(--display); font-weight: 800; letter-spacing: -0.015em; line-height: 1.15; text-wrap: balance; }
        p { margin: 0; }
        .wrap { max-width: 1120px; margin: 0 auto; padding: 0 20px; }
        .mono { font-family: var(--mono); font-size: 12px; font-weight: 500; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); }
        .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }

        :focus-visible { outline: 3px solid var(--purple); outline-offset: 2px; border-radius: 6px; }

        /* ===== Tombol ===== */
        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 10px;
            min-height: 54px; padding: 14px 26px; border: 0; border-radius: 14px; cursor: pointer;
            font-family: var(--display); font-weight: 700; font-size: 17px; line-height: 1.2; text-decoration: none; text-align: center;
            transition: background-color .15s ease, transform .15s ease;
        }
        .btn:active { transform: translateY(1px); }
        .btn-primary { background: var(--purple); color: #fff; }
        .btn-primary:hover { background: var(--purple-deep); }
        .btn-lime { background: var(--lime); color: var(--lime-ink); }
        .btn-lime:hover { background: #8fdd5c; }
        .btn-wa { background: var(--wa); color: #fff; }
        .btn-wa:hover { background: #0e743d; }
        .btn-ghost { background: transparent; color: var(--ink); border: 1.5px solid var(--line); min-height: 54px; }
        .btn-ghost:hover { border-color: var(--purple); color: var(--purple-deep); }
        .btn-block { width: 100%; }
        .btn[disabled] { opacity: .6; cursor: progress; }
        .btn svg { width: 20px; height: 20px; flex: none; }

        /* ===== Bar atas: hanya logo, tanpa menu keluar ===== */
        .top { padding: 18px 0 6px; }
        .top .wrap { display: flex; align-items: center; justify-content: space-between; gap: 16px; }
        .top img { height: 38px; width: auto; }
        .top .mono { text-align: right; }
        /* Di HP label ini mengulang baris kecil di atas judul dan memakan dua baris. */
        @media (max-width: 520px) { .top .mono { display: none; } }

        /* ===== Struk: kartu putih bertepi gerigi, diambil dari bentuk logo ===== */
        .struk-shadow { filter: drop-shadow(0 18px 30px rgba(54, 28, 120, .16)) drop-shadow(0 2px 4px rgba(54, 28, 120, .08)); }
        .struk {
            --tooth: 9px;
            background: var(--surface); border-radius: var(--radius) var(--radius) 0 0;
            padding: 22px 20px calc(26px + var(--tooth));
            -webkit-mask: conic-gradient(from -45deg at bottom, #0000, #000 1deg 89deg, #0000 90deg) 50% / calc(2 * var(--tooth)) 100%;
            mask: conic-gradient(from -45deg at bottom, #0000, #000 1deg 89deg, #0000 90deg) 50% / calc(2 * var(--tooth)) 100%;
        }
        .struk-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .struk-rule { border: 0; border-top: 2px dashed var(--line); margin: 16px 0; }

        footer.foot { padding: 28px 0 40px; color: var(--muted); font-size: 13.5px; }
        footer.foot .wrap { display: flex; flex-wrap: wrap; gap: 8px 20px; justify-content: space-between; }
        footer.foot nav { display: flex; flex-wrap: wrap; gap: 6px 18px; }
        footer.foot a { color: var(--muted); }

        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }
            *, *::before, *::after { animation: none !important; transition: none !important; }
        }
        @stack('styles')
    </style>

    {{-- ID Pixel diatur di Super Admin > Lead Iklan (cadangan: META_PIXEL_ID di .env). --}}
    @php $pixelId = \App\Models\AdLandingSetting::pixelId(); @endphp
    @if (filled($pixelId))
        {{-- Meta Pixel. Section pixel_match memberi data pencocokan lanjutan di halaman terima kasih. --}}
        <script>
            !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
            fbq('init', @json((string) $pixelId), @yield('pixel_match', '{}'));
            fbq('track', 'PageView');
        </script>
        <noscript><img height="1" width="1" style="display:none" alt="" src="https://www.facebook.com/tr?id={{ urlencode((string) $pixelId) }}&ev=PageView&noscript=1"></noscript>
    @endif
    <script>
        /* Aman dipanggil walau Pixel belum dipasang atau diblokir. */
        window.adTrack = function (method, name, params, options) {
            if (typeof window.fbq === 'function') window.fbq(method, name, params || {}, options || {});
        };
    </script>
</head>
<body>
    <header class="top">
        <div class="wrap">
            <img src="{{ asset('logo-kasir-umkm-sm.webp') }}" width="360" height="238" alt="MaccaPOS — Kasir UMKM">
            <span class="mono">@yield('top_note', 'Aplikasi kasir untuk UMKM')</span>
        </div>
    </header>

    @yield('content')

    <footer class="foot">
        <div class="wrap">
            <span>&copy; {{ date('Y') }} Kasir UMKM by MaccaPOS</span>
            <nav aria-label="Informasi hukum">
                <a href="{{ url('/privacy') }}">Kebijakan Privasi</a>
                <a href="{{ url('/terms') }}">Syarat &amp; Ketentuan</a>
            </nav>
        </div>
    </footer>

    @stack('scripts')
</body>
</html>
