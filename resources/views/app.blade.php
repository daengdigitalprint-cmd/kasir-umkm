<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        @if(filled(config('services.google.site_verification')))
        <meta name="google-site-verification" content="{{ config('services.google.site_verification') }}">
        @endif

        @php
            $landingServerSeo = null;
            if (isset($page) && is_array($page)) {
                $component = $page['component'] ?? '';
                $props = is_array($page['props'] ?? null) ? $page['props'] : [];
                $landingServerSeo = \App\Support\LandingSeo::serverSeoForPage($component, $props);
            }
        @endphp

        {{-- PWA Meta Tags --}}
        <meta name="application-name" content="UMKM Kasir">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        <meta name="apple-mobile-web-app-title" content="Kasir UMKM">
        <meta name="format-detection" content="telephone=no">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="msapplication-config" content="/browserconfig.xml">
        <meta name="msapplication-TileColor" content="#0891b2">
        <meta name="msapplication-tap-highlight" content="no">
        <meta name="theme-color" content="#0891b2">
        
        {{-- PWA Manifest --}}
        <link rel="manifest" href="/manifest.json">

        {{-- Favicon: urutan favicon.png → logo.png → favicon.ico → favicon.svg (Linux VPS case-sensitive: nama file harus tepat) --}}
        @php
            $faviconPublic = null;
            foreach (['favicon.png', 'logo.png', 'favicon.ico', 'favicon.svg'] as $faviconName) {
                $abs = public_path($faviconName);
                if ($abs !== false && is_file($abs)) {
                    $faviconPublic = $faviconName;
                    break;
                }
            }
            $faviconV = $faviconPublic ? '?v=' . filemtime(public_path($faviconPublic)) : '';
        @endphp
        @if ($faviconPublic !== null && str_ends_with(strtolower($faviconPublic), '.png'))
            <link rel="icon" type="image/png" sizes="32x32" href="/{{ $faviconPublic }}{{ $faviconV }}">
            <link rel="icon" type="image/png" sizes="16x16" href="/{{ $faviconPublic }}{{ $faviconV }}">
            <link rel="shortcut icon" type="image/png" href="/{{ $faviconPublic }}{{ $faviconV }}">
        @elseif ($faviconPublic !== null && str_ends_with(strtolower($faviconPublic), '.ico'))
            <link rel="icon" href="/{{ $faviconPublic }}{{ $faviconV }}" sizes="any">
            <link rel="shortcut icon" href="/{{ $faviconPublic }}{{ $faviconV }}">
        @elseif ($faviconPublic !== null && str_ends_with(strtolower($faviconPublic), '.svg'))
            <link rel="icon" href="/{{ $faviconPublic }}{{ $faviconV }}" type="image/svg+xml">
            <link rel="shortcut icon" href="/{{ $faviconPublic }}{{ $faviconV }}" type="image/svg+xml">
        @else
            {{-- Tidak ada favicon.png / logo.png / favicon.ico / favicon.svg di public/ — unggah salah satunya ke VPS (deploy jangan mengabaikan public/*). --}}
        @endif

        {{-- PWA / layar beranda: ikon install pakai logo.png jika ada --}}
        @php
            $pwaLogoPath = public_path('logo.png');
            $pwaLogoOk = $pwaLogoPath !== false && is_file($pwaLogoPath);
            $pwaLogoV = $pwaLogoOk ? '?v=' . filemtime($pwaLogoPath) : '';
        @endphp
        @if ($pwaLogoOk)
            <link rel="apple-touch-icon" href="/logo.png{{ $pwaLogoV }}">
            <link rel="apple-touch-icon" sizes="152x152" href="/logo.png{{ $pwaLogoV }}">
            <link rel="apple-touch-icon" sizes="180x180" href="/logo.png{{ $pwaLogoV }}">
            <link rel="apple-touch-icon" sizes="167x167" href="/logo.png{{ $pwaLogoV }}">
        @else
            <link rel="apple-touch-icon" href="/icons/icon-192x192.png">
            <link rel="apple-touch-icon" sizes="152x152" href="/icons/icon-152x152.png">
            <link rel="apple-touch-icon" sizes="180x180" href="/icons/icon-180x180.png">
            <link rel="apple-touch-icon" sizes="167x167" href="/icons/icon-167x167.png">
        @endif
        
        {{-- PWA Splash Screens --}}
        <link rel="apple-touch-startup-image" href="/icons/apple-splash-2048-2732.png" media="(device-width: 1024px) and (device-height: 1366px) and (-webkit-device-pixel-ratio: 2) and (orientation: portrait)">
        <link rel="apple-touch-startup-image" href="/icons/apple-splash-1668-2388.png" media="(device-width: 834px) and (device-height: 1194px) and (-webkit-device-pixel-ratio: 2) and (orientation: portrait)">
        <link rel="apple-touch-startup-image" href="/icons/apple-splash-1536-2048.png" media="(device-width: 768px) and (device-height: 1024px) and (-webkit-device-pixel-ratio: 2) and (orientation: portrait)">
        <link rel="apple-touch-startup-image" href="/icons/apple-splash-1125-2436.png" media="(device-width: 375px) and (device-height: 812px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)">
        <link rel="apple-touch-startup-image" href="/icons/apple-splash-1242-2688.png" media="(device-width: 414px) and (device-height: 896px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)">
        <link rel="apple-touch-startup-image" href="/icons/apple-splash-750-1334.png" media="(device-width: 375px) and (device-height: 667px) and (-webkit-device-pixel-ratio: 2) and (orientation: portrait)">
        <link rel="apple-touch-startup-image" href="/icons/apple-splash-828-1792.png" media="(device-width: 414px) and (device-height: 896px) and (-webkit-device-pixel-ratio: 2) and (orientation: portrait)">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline critical CSS untuk mencegah FOUC --}}
        <style>
            /* Base theme colors */
            html {
                background-color: #F8FAFC;
            }

            html.dark {
                background-color: #0F172A;
            }

            /* Critical CSS untuk landing page - mencegah flash of unstyled content */
            html.lp-landing,
            body.lp-landing {
                margin: 0;
                padding: 0;
                overflow-x: hidden;
            }

            body.body-bg-2 {
                background-color: #EDF1FF;
                font-family: "Plus Jakarta Sans", ui-sans-serif, system-ui, -apple-system, sans-serif;
                font-size: 16px;
                font-weight: 500;
                line-height: 28px;
                color: #05111A;
            }

            /* Hide content until fonts and styles are ready */
            .lp-landing {
                visibility: visible;
            }
        </style>

        <title inertia>{{ ! empty($landingServerSeo) ? $landingServerSeo['title'] : config('app.name', 'Laravel') }}</title>

        @if (! empty($landingServerSeo))
        <link rel="canonical" href="{{ $landingServerSeo['canonical'] }}">
        <meta name="description" content="{{ $landingServerSeo['description'] }}">
        <meta name="robots" content="index, follow">

        <meta property="og:type" content="{{ $landingServerSeo['og_type'] }}">
        <meta property="og:site_name" content="UMKM Kasir">
        <meta property="og:locale" content="id_ID">
        <meta property="og:title" content="{{ $landingServerSeo['title'] }}">
        <meta property="og:description" content="{{ $landingServerSeo['description'] }}">
        <meta property="og:url" content="{{ $landingServerSeo['canonical'] }}">
        <meta property="og:image" content="{{ $landingServerSeo['og_image'] }}">
        <meta property="og:image:type" content="{{ $landingServerSeo['og_image_type'] }}">
        @if (! empty($landingServerSeo['og_image_width']) && ! empty($landingServerSeo['og_image_height']))
        <meta property="og:image:width" content="{{ $landingServerSeo['og_image_width'] }}">
        <meta property="og:image:height" content="{{ $landingServerSeo['og_image_height'] }}">
        @endif
        @if(\Illuminate\Support\Str::startsWith($landingServerSeo['og_image'], 'https://'))
        <meta property="og:image:secure_url" content="{{ $landingServerSeo['og_image'] }}">
        @endif
        <meta property="og:image:alt" content="Logo UMKM Kasir — aplikasi kasir untuk UMKM">

        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="{{ $landingServerSeo['title'] }}">
        <meta name="twitter:description" content="{{ $landingServerSeo['description'] }}">
        <meta name="twitter:image" content="{{ $landingServerSeo['og_image'] }}">

        <script type="application/ld+json">{!! $landingServerSeo['json_ld'] !!}</script>
        @endif

        @if (! $pwaLogoOk && file_exists(public_path('apple-touch-icon.png')))
            <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        @endif

        <!-- Google Fonts: Plus Jakarta Sans -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@200;300;400;500;600;700;800&display=swap">
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@200;300;400;500;600;700;800&display=swap" media="print" onload="this.media='all'">
        
        <style>
            :root {
                --font-sans: 'Plus Jakarta Sans', ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", sans-serif;
                --font-display: 'Plus Jakarta Sans', var(--font-sans);
            }
            .font-sans { font-family: var(--font-sans); }
            .font-display { font-family: var(--font-display); }
            
            /* Preloader inline - langsung tersedia sebelum CSS externe load */
            .lp-preloader {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background-color: #05111A;
                z-index: 99999999;
                display: flex;
                align-items: center;
                justify-content: center;
                transition: opacity 0.5s ease, visibility 0.5s ease;
            }
            .lp-preloader-hidden {
                opacity: 0;
                visibility: hidden;
            }
            .lp-preloader-content {
                text-align: center;
            }
            .lp-preloader-spinner {
                width: 48px;
                height: 48px;
                border: 4px solid rgba(160, 234, 113, 0.2);
                border-top-color: #A0EA71;
                border-radius: 50%;
                animation: lp-spin 1s linear infinite;
                margin: 0 auto 16px;
            }
            .lp-preloader-text {
                color: #ffffff;
                font-family: 'Plus Jakarta Sans', ui-sans-serif, system-ui, -apple-system, sans-serif;
                font-size: 14px;
                font-weight: 500;
                letter-spacing: 1px;
                text-transform: uppercase;
            }
            @keyframes lp-spin {
                from { transform: rotate(0deg); }
                to { transform: rotate(360deg); }
            }
            @media (max-width: 575px) {
                .lp-preloader-spinner { width: 40px; height: 40px; border-width: 3px; }
                .lp-preloader-text { font-size: 12px; }
            }
        </style>

        {{-- SweetAlert2 (CDN): preload untuk percepat load, async untuk non-blocking --}}
        <link rel="preload" as="style" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.23.0/dist/sweetalert2.min.css" crossorigin="anonymous">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.23.0/dist/sweetalert2.min.css" crossorigin="anonymous" media="print" onload="this.media='all'">

        {{-- Production assets - use built assets directly --}}
        @vite(['resources/js/app.ts'])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        {{-- Preloader - ditampilkan sebelum Inertia mount --}}
        <div id="lp-preloader" class="lp-preloader">
            <div class="lp-preloader-content">
                <div class="lp-preloader-spinner"></div>
                <div class="lp-preloader-text">Memuat...</div>
            </div>
        </div>
        
        <script>
            // Preloader - dinonaktifkan untuk dashboard/user pages
            (function() {
                var preloader = document.getElementById('lp-preloader');
                if (!preloader) return;
                
                // Langsung hide preloader (tidak perlu show/hide logic)
                preloader.classList.add('lp-preloader-hidden');
                setTimeout(function() {
                    preloader.style.display = 'none';
                }, 500);
            })();
        </script>
        
        @inertia

        {{-- SweetAlert2 all-in-one (global Swal) — harus sebelum skrip Service Worker --}}
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.23.0/dist/sweetalert2.all.min.js" crossorigin="anonymous"></script>
        
        {{-- PWA Service Worker Registration --}}
        <script>
            function promptPwaVersionReload() {
                var msg = 'Versi baru tersedia! Muat ulang halaman untuk menggunakan versi terbaru?';
                if (typeof Swal !== 'undefined' && typeof Swal.fire === 'function') {
                    Swal.fire({
                        icon: 'info',
                        title: 'Versi baru tersedia',
                        text: 'Muat ulang halaman untuk menggunakan pembaruan aplikasi.',
                        showCancelButton: true,
                        confirmButtonText: 'Muat ulang',
                        cancelButtonText: 'Nanti',
                        confirmButtonColor: '#0891b2',
                        cancelButtonColor: '#64748b',
                        reverseButtons: true,
                        customClass: {
                            popup: 'rounded-2xl shadow-2xl font-sans',
                            title: 'text-lg font-bold text-slate-900',
                            confirmButton: 'px-6 py-2 rounded-xl font-semibold',
                            cancelButton: 'px-6 py-2 rounded-xl font-semibold',
                        },
                    }).then(function (result) {
                        if (result.isConfirmed) {
                            window.location.reload();
                        }
                    });
                } else if (window.confirm(msg)) {
                    window.location.reload();
                }
            }

            // Register service worker
            if ('serviceWorker' in navigator) {
                window.addEventListener('load', () => {
                    navigator.serviceWorker.register('/sw.js')
                        .then((registration) => {
                            console.log('Service Worker registered successfully:', registration.scope);
                            
                            // Check for updates
                            registration.addEventListener('updatefound', () => {
                                const newWorker = registration.installing;
                                newWorker.addEventListener('statechange', () => {
                                    if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                                        promptPwaVersionReload();
                                    }
                                });
                            });
                        })
                        .catch((error) => {
                            console.log('Service Worker registration failed:', error);
                        });
                });
            }

            // Handle online/offline status — banner tipis di atas halaman
            function updateOnlineStatus() {
                const status = navigator.onLine ? 'online' : 'offline';
                document.body.setAttribute('data-status', status);

                let indicator = document.getElementById('offline-indicator');

                if (!navigator.onLine) {
                    if (!indicator) {
                        indicator = document.createElement('div');
                        indicator.id = 'offline-indicator';
                        indicator.style.cssText = [
                            'position:fixed', 'top:0', 'left:0', 'right:0',
                            'background:#f59e0b', 'color:#fff', 'text-align:center',
                            'padding:6px 12px', 'font-size:13px', 'font-weight:500',
                            'z-index:99999', 'animation:sw-slideDown 0.3s ease',
                        ].join(';');
                        const style = document.createElement('style');
                        style.textContent = '@keyframes sw-slideDown{from{transform:translateY(-100%)}to{transform:translateY(0)}}';
                        document.head.appendChild(style);
                        document.body.appendChild(indicator);
                    }
                    indicator.textContent = '📶 Mode Offline — transaksi disimpan lokal, sync otomatis saat koneksi pulih';
                } else {
                    if (indicator) {
                        indicator.textContent = '✅ Koneksi pulih — menyinkronkan transaksi...';
                        indicator.style.background = '#10b981';
                        setTimeout(function () { indicator && indicator.remove(); }, 3000);
                    }
                }
            }

            window.addEventListener('online', updateOnlineStatus);
            window.addEventListener('offline', updateOnlineStatus);
            updateOnlineStatus();
        </script>
    </body>
</html>
