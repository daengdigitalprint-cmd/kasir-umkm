<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Blog — Kasir UMKM by MaccaPOS')</title>
    @yield('meta')
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <style>
        :root {
            --ink: #0f172a; --muted: #64748b; --line: #e2e8f0; --bg: #f8fafc;
            --brand: #e11d48; --brand-soft: #fff1f2; --accent: #059669;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; background: var(--bg); color: var(--ink);
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            line-height: 1.7; -webkit-font-smoothing: antialiased;
        }
        a { color: var(--brand); text-decoration: none; }
        a:hover { text-decoration: underline; }
        .wrap { max-width: 1080px; margin: 0 auto; padding: 0 16px; }
        .narrow { max-width: 760px; }

        header.site {
            background: #fff; border-bottom: 1px solid var(--line);
            position: sticky; top: 0; z-index: 20;
        }
        header.site .wrap { display: flex; align-items: center; justify-content: space-between; height: 60px; }
        .logo { display: flex; align-items: center; gap: 10px; color: var(--ink); font-weight: 800; font-size: 16px; }
        .logo:hover { text-decoration: none; }
        .logo .dot { width: 32px; height: 32px; border-radius: 10px; background: linear-gradient(135deg, #f43f5e, #e11d48); display: inline-flex; align-items: center; justify-content: center; color: #fff; font-size: 15px; font-weight: 900; }
        .nav-cta { display: inline-block; background: var(--brand); color: #fff; font-weight: 700; font-size: 14px; padding: 8px 16px; border-radius: 10px; }
        .nav-cta:hover { background: #be123c; text-decoration: none; }

        footer.site { border-top: 1px solid var(--line); background: #fff; margin-top: 56px; }
        footer.site .wrap { padding: 28px 16px; display: flex; flex-wrap: wrap; gap: 12px; justify-content: space-between; align-items: center; font-size: 14px; color: var(--muted); }
        footer.site nav { display: flex; flex-wrap: wrap; gap: 16px; }
        footer.site a { color: var(--muted); }

        .badge { display: inline-block; background: var(--brand-soft); color: var(--brand); font-size: 12px; font-weight: 700; padding: 3px 10px; border-radius: 999px; }
        .meta-line { color: var(--muted); font-size: 14px; }
        .card {
            background: #fff; border: 1px solid var(--line); border-radius: 16px; overflow: hidden;
            transition: box-shadow .15s ease, transform .15s ease; display: flex; flex-direction: column;
        }
        .card:hover { box-shadow: 0 8px 24px rgba(15, 23, 42, .08); transform: translateY(-2px); }
        .card a.card-link { color: inherit; display: block; padding: 20px; flex: 1; }
        .card a.card-link:hover { text-decoration: none; }
        .card h2 { font-size: 18px; line-height: 1.4; margin: 10px 0 8px; }
        .card p { margin: 0; color: var(--muted); font-size: 14px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 18px; }

        /* Isi artikel */
        article.prose { background: #fff; border: 1px solid var(--line); border-radius: 18px; padding: clamp(20px, 4vw, 44px); }
        .prose h2 { font-size: 26px; line-height: 1.35; margin: 36px 0 12px; }
        .prose h3 { font-size: 20px; margin: 28px 0 10px; }
        .prose p { margin: 0 0 16px; }
        .prose ul, .prose ol { padding-left: 24px; margin: 0 0 16px; }
        .prose li { margin-bottom: 6px; }
        .prose img { max-width: 100%; border-radius: 12px; }
        .prose blockquote { border-left: 4px solid var(--brand); margin: 16px 0; padding: 8px 16px; background: var(--brand-soft); border-radius: 0 10px 10px 0; }
        .prose table { width: 100%; border-collapse: collapse; margin: 16px 0; font-size: 15px; display: block; overflow-x: auto; }
        .prose th, .prose td { border: 1px solid var(--line); padding: 10px 12px; text-align: left; }
        .prose th { background: var(--bg); }
        .prose code { background: var(--bg); border: 1px solid var(--line); border-radius: 6px; padding: 1px 6px; font-size: 14px; }

        .toc { background: var(--bg); border: 1px solid var(--line); border-radius: 14px; padding: 16px 20px; margin: 24px 0; font-size: 15px; }
        .toc strong { display: block; margin-bottom: 8px; }
        .toc ol { margin: 0; padding-left: 20px; }
        .toc a { color: var(--ink); }

        .faq-item { border: 1px solid var(--line); border-radius: 12px; padding: 14px 18px; margin-bottom: 10px; background: #fff; }
        .faq-item summary { font-weight: 700; cursor: pointer; }
        .faq-item p { margin: 10px 0 0; color: #334155; }

        .cta-box {
            margin-top: 32px; border-radius: 16px; padding: 28px;
            background: linear-gradient(135deg, #0f172a, #1e293b); color: #fff; text-align: center;
        }
        .cta-box h2 { margin: 0 0 8px; font-size: 22px; }
        .cta-box p { margin: 0 0 16px; color: #cbd5e1; }
        .cta-box a { display: inline-block; background: var(--brand); color: #fff; font-weight: 700; padding: 10px 22px; border-radius: 10px; }
        .cta-box a:hover { background: #be123c; text-decoration: none; }

        .pagination { display: flex; gap: 6px; justify-content: center; margin-top: 32px; flex-wrap: wrap; }
        .pagination a, .pagination span { padding: 8px 14px; border-radius: 10px; border: 1px solid var(--line); background: #fff; font-size: 14px; color: var(--ink); }
        .pagination .active { background: var(--ink); color: #fff; border-color: var(--ink); }
        .pagination .disabled { color: #cbd5e1; }

        .chipbar { display: flex; gap: 8px; flex-wrap: wrap; margin: 20px 0 26px; }
        .chipbar a { border: 1px solid var(--line); background: #fff; color: var(--ink); font-size: 13px; font-weight: 600; padding: 6px 14px; border-radius: 999px; }
        .chipbar a.on { background: var(--brand); border-color: var(--brand); color: #fff; }
        .chipbar a:hover { text-decoration: none; border-color: var(--brand); }

        .preview-banner { background: #fef3c7; color: #92400e; font-size: 14px; font-weight: 600; text-align: center; padding: 8px 16px; }
        .breadcrumbs { font-size: 13px; color: var(--muted); margin: 20px 0 10px; }
        .breadcrumbs a { color: var(--muted); }
    </style>
</head>
<body>
    <header class="site">
        <div class="wrap">
            <a class="logo" href="{{ url('/') }}">
                <span class="dot">M</span>
                <span>Kasir UMKM <span style="color:var(--muted);font-weight:600">· Blog</span></span>
            </a>
            <nav style="display:flex;align-items:center;gap:18px;">
                <a href="{{ route('blog.index') }}" style="color:var(--ink);font-weight:600;font-size:14px;">Semua Artikel</a>
                <a class="nav-cta" href="{{ url('/register') }}">Coba Gratis</a>
            </nav>
        </div>
    </header>

    @yield('content')

    <footer class="site">
        <div class="wrap">
            <p style="margin:0">© {{ date('Y') }} Kasir UMKM by MaccaPOS — Aplikasi kasir online untuk UMKM Indonesia.</p>
            <nav>
                <a href="{{ url('/') }}">Beranda</a>
                <a href="{{ url('/panduan') }}">Panduan</a>
                <a href="{{ route('blog.index') }}">Blog</a>
                <a href="{{ url('/privacy') }}">Privasi</a>
                <a href="{{ url('/terms') }}">Syarat</a>
            </nav>
        </div>
    </footer>
</body>
</html>
