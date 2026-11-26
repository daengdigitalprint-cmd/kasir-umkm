@extends('ads.layout')

@section('title', 'Kasir UMKM by MaccaPOS — Cek Kecocokan untuk Usaha Anda')

@php
    /** Ikon garis per jenis usaha (isi <svg viewBox="0 0 24 24">). */
    $typeIcons = [
        'fnb' => '<path d="M7 3v8a2 2 0 0 0 2 2v8M5 3v5M9 3v5M17 3c-2 2-3 4-3 7h3v11"/>',
        'retail' => '<path d="M4 9h16l-1.5 11h-13L4 9zM8.5 9l3-5.5M15.5 9l-3-5.5"/>',
        'bakery' => '<path d="M4 20h16v-7a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v7zM4 16c2 0 2-1.5 4-1.5s2 1.5 4 1.5 2-1.5 4-1.5 2 1.5 4 1.5M12 11V7M12 4v.5"/>',
        'pharmacy' => '<rect x="4" y="4" width="16" height="16" rx="3"/><path d="M12 8v8M8 12h8"/>',
        'laundry' => '<rect x="5" y="3" width="14" height="18" rx="2"/><circle cx="12" cy="13.5" r="4"/><path d="M8 6.5h.01M11 6.5h.01"/>',
        'barbershop' => '<circle cx="6" cy="6" r="2.5"/><circle cx="6" cy="18" r="2.5"/><path d="M8 7.6 20 18M8 16.4 20 6"/>',
        'general' => '<path d="M12 3 3 8l9 5 9-5-9-5zM3 13l9 5 9-5"/>',
    ];

    $free = $pricing['free'];
    $paid = $pricing['paid'];

    $kuota = array_filter([
        $free['max_products'] ? $free['max_products'].' produk' : null,
        $free['max_customers'] ? $free['max_customers'].' pelanggan' : null,
    ]);
    $starterFeatures = [
        $kuota ? 'Hingga '.implode(' & ', $kuota) : 'Produk & pelanggan tanpa batas',
        'Transaksi tanpa batas',
        'Kasir (POS) & cetak struk',
        'Stok dasar & laporan penjualan',
    ];
    $growUpFeatures = [
        'Produk & pelanggan tanpa batas',
        $paid['max_stores'] ? $paid['max_stores'].' outlet, tambah outlet '.($paid['outlet_addon_label'] ?? 'Rp85 rb').'/bulan' : 'Outlet tanpa batas & transfer stok',
        'Shift kasir, pembelian & stok opname',
        'Diskon, loyalitas & piutang pelanggan',
    ];

    // Langkah yang memuat error validasi server, supaya form langsung membuka langkah itu.
    $stepFields = [
        1 => ['business_type'],
        2 => ['business_status'],
        3 => ['daily_transactions'],
        4 => ['start_timeline'],
        5 => ['name', 'business_name', 'whatsapp', 'city'],
    ];
    $errorStep = null;
    foreach ($stepFields as $n => $fields) {
        if ($errors->hasAny($fields)) { $errorStep = $n; break; }
    }

    $selectedType = old('business_type', $preselectedType);
    $check = '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m4.5 10.5 3.5 3.5 7.5-8"/></svg>';
@endphp

@push('styles')
        /* ===== Hero ===== */
        .hero { padding: 22px 0 56px; }
        .hero-grid { display: grid; gap: 26px; grid-template-areas: "copy" "form" "proof"; }
        .hero-copy { grid-area: copy; }
        .hero-form { grid-area: form; min-width: 0; scroll-margin-top: 16px; }
        .hero-proof { grid-area: proof; }
        .hero h1 { font-size: clamp(33px, 8.8vw, 56px); line-height: 1.05; letter-spacing: -0.022em; margin: 12px 0 14px; }
        .hero h1 mark {
            color: inherit; padding: 0 .12em; margin: 0 -.12em;
            background: linear-gradient(transparent 56%, var(--lime) 56% 93%, transparent 93%);
            -webkit-box-decoration-break: clone; box-decoration-break: clone;
        }
        .hero .sub { font-size: 17px; color: var(--muted); max-width: 520px; }
        .ticks { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
        .ticks li { display: flex; gap: 10px; align-items: flex-start; font-weight: 700; font-size: 15.5px; }
        .ticks svg { flex: none; width: 22px; height: 22px; padding: 3px; border-radius: 999px; background: var(--lime); color: var(--lime-ink); margin-top: 1px; }
        .hero-shot { display: none; }
        @media (min-width: 940px) {
            .hero { padding: 40px 0 84px; }
            .hero-grid { grid-template-columns: minmax(0, 1.08fr) minmax(0, 440px); grid-template-areas: "copy form" "proof form"; grid-template-rows: auto 1fr; column-gap: 64px; row-gap: 26px; align-items: start; }
            .hero-copy { padding-top: 26px; }
            .hero .sub { font-size: 18.5px; }
            .hero-shot { display: block; margin-top: 30px; max-width: 470px; }
        }

        /* ===== Form penyaring ===== */
        .lead-form fieldset { border: 0; margin: 0; padding: 0; min-width: 0; }
        .lead-form legend { padding: 0; font-family: var(--display); font-size: 21px; font-weight: 700; letter-spacing: -0.012em; line-height: 1.25; margin-bottom: 4px; }
        .lead-form .hint { color: var(--muted); font-size: 14.5px; margin-bottom: 14px; }
        .progress { height: 6px; border-radius: 999px; background: var(--purple-soft); overflow: hidden; margin-top: 10px; }
        .progress i { display: block; height: 100%; width: 20%; border-radius: 999px; background: var(--purple); transition: width .25s ease; }

        .opts { display: grid; gap: 10px; }
        .opts-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .opt { position: relative; display: block; cursor: pointer; }
        .opt input { position: absolute; opacity: 0; inset: 0; width: 100%; height: 100%; margin: 0; cursor: pointer; }
        .opt-box {
            display: flex; align-items: center; gap: 12px; min-height: 58px; padding: 12px 14px;
            border: 1.5px solid var(--line); border-radius: 13px; background: #fff;
            transition: border-color .12s ease, background-color .12s ease;
        }
        .opt-box b { display: block; font-weight: 700; font-size: 15.5px; line-height: 1.3; }
        .opt-box small { display: block; color: var(--muted); font-size: 13px; line-height: 1.35; margin-top: 2px; }
        .opt-box svg { flex: none; width: 24px; height: 24px; color: var(--purple); }
        .opt:hover .opt-box { border-color: #bfaaf3; }
        .opt input:checked + .opt-box { border-color: var(--purple); background: var(--purple-soft); box-shadow: inset 0 0 0 1px var(--purple); }
        .opt input:focus-visible + .opt-box { outline: 3px solid var(--purple); outline-offset: 2px; }
        .opts-2 .opt-box { gap: 10px; padding-inline: 12px; min-height: 64px; }
        .opts-2 .opt-box b { font-size: 15px; }
        .opts-2 .opt:last-child:nth-child(odd) { grid-column: 1 / -1; }

        .field { margin-bottom: 13px; }
        .field label { display: block; font-weight: 700; font-size: 14.5px; margin-bottom: 5px; }
        .field input {
            width: 100%; min-height: 52px; padding: 12px 14px; font: inherit; font-size: 16px; color: var(--ink);
            border: 1.5px solid var(--line); border-radius: 12px; background: #fff;
        }
        .field input::placeholder { color: #9c97b0; }
        .field input:focus { outline: 0; border-color: var(--purple); box-shadow: 0 0 0 3px var(--purple-soft); }
        .field input[aria-invalid="true"] { border-color: var(--danger); }
        .err { color: var(--danger); font-size: 13.5px; font-weight: 700; margin-top: 6px; }
        .err:empty { display: none; }

        .step-nav { display: none; gap: 10px; margin-top: 16px; }
        .step-nav .btn-ghost { flex: none; padding-inline: 18px; }
        .step-nav .btn-primary { flex: 1; }
        .consent { color: var(--muted); font-size: 12.5px; line-height: 1.5; margin-top: 12px; }
        .hp { position: absolute; left: -9999px; top: auto; width: 1px; height: 1px; overflow: hidden; }

        /* Tanpa JavaScript: semua langkah berurutan dalam satu form panjang. */
        .step + .step { margin-top: 26px; }
        .js .step + .step { margin-top: 0; }
        .js .step { display: none; }
        .js .step.is-active { display: block; }
        /* Animasi hanya setelah pengunjung berpindah langkah, bukan saat halaman pertama tampil. */
        .js .lead-form.is-moving .step.is-active { animation: step-in .2s ease; }
        .js .step-nav { display: flex; }
        .js .step[data-step="1"] [data-back] { display: none; }
        @keyframes step-in { from { opacity: 0; transform: translateX(10px); } to { opacity: 1; transform: none; } }

        .offer { display: flex; gap: 9px; align-items: flex-start; margin: -4px 0 16px; padding: 10px 12px; border-radius: 11px; background: var(--lime); color: var(--lime-ink); font-size: 14.5px; font-weight: 700; line-height: 1.4; }
        .offer b { flex: none; font-family: var(--mono); font-size: 11.5px; font-weight: 500; letter-spacing: .08em; text-transform: uppercase; padding: 2px 7px; border-radius: 6px; background: var(--lime-ink); color: var(--lime); margin-top: 1px; }
        .form-note { display: flex; flex-wrap: wrap; justify-content: center; gap: 4px 18px; margin-top: 16px; color: var(--muted); font-size: 13.5px; font-weight: 700; }
        .form-note span { display: inline-flex; align-items: center; gap: 6px; }
        .form-note svg { width: 16px; height: 16px; color: var(--purple); }

        .proof { display: flex; flex-wrap: wrap; gap: 10px 28px; margin-bottom: 20px; padding-bottom: 20px; border-bottom: 2px dashed var(--line); }
        .proof div { line-height: 1.2; }
        .proof b { display: block; font-family: var(--display); font-size: 27px; font-weight: 800; letter-spacing: -0.02em; }
        .proof span { color: var(--muted); font-size: 13.5px; }

        .how { list-style: none; margin: 28px 0 0; padding: 0; display: grid; gap: 14px; counter-reset: how; }
        .how li { counter-increment: how; position: relative; padding: 18px 18px 18px 66px; border: 1.5px solid var(--line); border-radius: var(--radius); background: #fff; }
        .how li::before { content: counter(how, decimal-leading-zero); position: absolute; left: 18px; top: 18px; width: 34px; height: 34px; border-radius: 10px; background: var(--purple-soft); color: var(--purple-deep); font-family: var(--mono); font-size: 13px; font-weight: 500; display: flex; align-items: center; justify-content: center; }
        .how b { display: block; font-family: var(--display); font-weight: 700; font-size: 18px; line-height: 1.3; }
        .how span { color: var(--muted); font-size: 15px; }
        @media (min-width: 860px) { .how { grid-template-columns: repeat(3, minmax(0, 1fr)); } }

        /* ===== Video tutorial (tampilan asli aplikasi) ===== */
        .tut { display: grid; gap: 26px; align-items: center; }
        .tut-copy .btn { margin-top: 22px; }
        .tut-steps { list-style: none; margin: 18px 0 0; padding: 0; display: grid; gap: 8px; }
        .tut-steps li { display: flex; gap: 10px; align-items: center; font-weight: 700; font-size: 15px; }
        .tut-steps b { flex: none; width: 26px; height: 26px; border-radius: 8px; background: var(--purple-soft); color: var(--purple-deep); font-family: var(--mono); font-size: 12px; font-weight: 500; display: flex; align-items: center; justify-content: center; }
        .vid { position: relative; width: 100%; max-width: 340px; margin: 0 auto; aspect-ratio: 9 / 16; border-radius: 26px; overflow: hidden; background: #1b1036; border: 6px solid var(--ink); box-shadow: 0 18px 34px rgba(54, 28, 120, .22); }
        .vid video { display: block; width: 100%; height: 100%; object-fit: cover; background: #1b1036; }
        .vid-play { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: flex-end; gap: 10px; padding-bottom: 34px; border: 0; cursor: pointer; color: #fff; font: inherit; font-weight: 800; font-size: 15px; background: linear-gradient(180deg, transparent 55%, rgba(23, 18, 46, .72)); }
        .vid-play i { width: 74px; height: 74px; border-radius: 50%; background: var(--lime); color: var(--lime-ink); display: flex; align-items: center; justify-content: center; box-shadow: 0 0 0 8px rgba(160, 234, 113, .3); transition: transform .15s ease; }
        .vid-play:hover i { transform: scale(1.06); }
        .vid-play svg { width: 30px; height: 30px; margin-left: 4px; }
        .vid.is-playing .vid-play { display: none; }
        @media (min-width: 860px) {
            .tut { grid-template-columns: minmax(0, 1fr) 360px; gap: 64px; }
            .vid { max-width: 360px; }
        }

        /* ===== Bagian isi ===== */
        .sec { padding: 60px 0; }
        .sec-white { background: var(--surface); }
        .sec h2 { font-size: clamp(25px, 5.6vw, 36px); margin: 8px 0 10px; }
        .sec .lede { color: var(--muted); max-width: 560px; font-size: 16.5px; }

        .versus { margin-top: 28px; display: grid; gap: 12px; }
        .versus-row { display: grid; gap: 0; border: 1.5px solid var(--line); border-radius: var(--radius); overflow: hidden; background: #fff; }
        .versus-row > div { padding: 15px 18px; }
        .versus-row .was { background: #faf9fd; color: var(--muted); }
        /* Garis coret merah: menandai masalah yang hilang setelah pakai aplikasi.
           Merah cerah (bukan --danger yang gelap) supaya terbaca di atas teks gelap. */
        .versus-row .was b { color: var(--ink); text-decoration: line-through; text-decoration-color: #e5484d; text-decoration-thickness: 2.5px; }
        .versus-row .now b { display: flex; gap: 9px; align-items: flex-start; }
        .versus-row .now svg { flex: none; width: 21px; height: 21px; padding: 3px; border-radius: 999px; background: var(--lime); color: var(--lime-ink); margin-top: 2px; }
        .versus-row b { font-weight: 800; font-size: 16.5px; line-height: 1.35; }
        .versus-row span { display: block; font-size: 14.5px; margin-top: 3px; }
        .versus-row .now span { color: var(--muted); padding-left: 30px; }
        @media (min-width: 760px) {
            .versus-row { grid-template-columns: minmax(0, .8fr) minmax(0, 1.2fr); }
            .versus-row .was { border-right: 1.5px solid var(--line); }
        }

        .shots { margin-top: 30px; display: grid; gap: 22px; }
        .shot { margin: 0; }
        .shot img { width: 100%; border: 1.5px solid var(--line); border-radius: var(--radius); background: #fff; }
        .shot figcaption { margin-top: 12px; }
        .shot figcaption b { display: block; font-weight: 800; font-size: 17.5px; }
        .shot figcaption span { color: var(--muted); font-size: 15px; }
        @media (min-width: 860px) { .shots { grid-template-columns: repeat(3, minmax(0, 1fr)); } }

        .plans { margin-top: 28px; display: grid; gap: 14px; }
        .plan { border: 1.5px solid var(--line); border-radius: var(--radius); padding: 22px 20px; background: #fff; }
        .plan-paid { border-color: var(--purple); background: var(--purple-soft); }
        .plan .price { font-family: var(--display); font-size: 32px; font-weight: 800; letter-spacing: -0.02em; line-height: 1.1; margin: 8px 0 2px; }
        .plan .price small { font-family: var(--font); font-size: 15px; font-weight: 700; letter-spacing: 0; color: var(--muted); }
        .plan .desc { color: var(--muted); font-size: 14.5px; margin-bottom: 14px; }
        .plan .ticks li { font-weight: 500; font-size: 15px; }
        @media (min-width: 760px) { .plans { grid-template-columns: repeat(2, minmax(0, 1fr)); } }

        .faq { margin-top: 24px; max-width: 760px; }
        .faq details { border-bottom: 1.5px solid var(--line); }
        .faq summary { cursor: pointer; list-style: none; padding: 17px 34px 17px 0; position: relative; font-weight: 800; font-size: 16.5px; line-height: 1.35; }
        .faq summary::-webkit-details-marker { display: none; }
        .faq summary::after { content: '+'; position: absolute; right: 4px; top: 12px; font-size: 26px; font-weight: 500; color: var(--purple); line-height: 1.2; }
        .faq details[open] summary::after { content: '–'; }
        .faq details p { color: var(--muted); padding: 0 0 18px; max-width: 640px; }

        .closing { background: var(--purple); color: #fff; padding: 56px 0 60px; text-align: center; }
        .closing h2 { font-size: clamp(26px, 6vw, 38px); max-width: 620px; margin: 0 auto 12px; }
        .closing p { color: #e2d6ff; max-width: 500px; margin: 0 auto 24px; }

        /* ===== CTA menempel di bawah (HP), muncul saat form tidak terlihat ===== */
        .sticky-cta {
            position: fixed; left: 0; right: 0; bottom: 0; z-index: 30; padding: 10px 16px calc(10px + env(safe-area-inset-bottom));
            background: rgba(255, 255, 255, .96); border-top: 1.5px solid var(--line);
            transform: translateY(110%); transition: transform .22s ease; visibility: hidden;
        }
        .sticky-cta.is-on { transform: none; visibility: visible; }
        .sticky-cta .btn { min-height: 50px; }
        @media (max-width: 939px) { footer.foot { padding-bottom: 96px; } }
        @media (min-width: 940px) { .sticky-cta { display: none; } }
@endpush

@section('content')
<main>
    <section class="hero">
        <div class="wrap hero-grid">
            <div class="hero-copy">
                <span class="mono">Catat penjualan · stok · laporan</span>
                <h1>{{ $headline['lead'] }} <mark>{{ $headline['mark'] }}</mark></h1>
                <p class="sub">{{ $headline['sub'] }}</p>
            </div>

            <div class="hero-form" id="form">
                <div class="struk-shadow">
                    <form class="struk lead-form" method="POST" action="{{ route('ads.lead.store') }}" id="lead-form">
                        @csrf
                        {{-- Honeypot: manusia tidak melihat kolom ini. --}}
                        <div class="hp" aria-hidden="true">
                            <label for="website">Website</label>
                            <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
                        </div>

                        @if (filled($offerText))
                            <p class="offer"><b>Bonus</b> <span>{{ $offerText }}</span></p>
                        @endif

                        <div class="struk-head">
                            <span class="mono">Cek kecocokan · ±1 menit</span>
                            <span class="mono" id="step-count" aria-live="polite">01/05</span>
                        </div>
                        <div class="progress" aria-hidden="true"><i id="progress-bar"></i></div>
                        <hr class="struk-rule">

                        {{-- Langkah 1 — jenis usaha (satu ketukan, komitmen kecil pertama) --}}
                        <fieldset class="step is-active" data-step="1">
                            <legend>Usaha Anda bergerak di bidang apa?</legend>
                            <p class="hint">Pilih yang paling mendekati.</p>
                            <div class="opts opts-2">
                                @foreach ($businessTypes as $key => $label)
                                    <label class="opt">
                                        <input type="radio" name="business_type" value="{{ $key }}" required @checked($selectedType === $key)>
                                        <span class="opt-box">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $typeIcons[$key] !!}</svg>
                                            <b>{{ $label }}</b>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                            <p class="err" role="alert">{{ $errors->first('business_type') }}</p>
                            <div class="step-nav">
                                <button type="button" class="btn btn-primary" data-next>Lanjut</button>
                            </div>
                        </fieldset>

                        {{-- Langkah 2 — usaha nyata atau masih rencana --}}
                        <fieldset class="step" data-step="2">
                            <legend>Usahanya sudah berjalan?</legend>
                            <p class="hint">Supaya saran kami sesuai tahap usaha Anda.</p>
                            <div class="opts">
                                @foreach ($businessStatuses as $key => $option)
                                    <label class="opt">
                                        <input type="radio" name="business_status" value="{{ $key }}" required @checked(old('business_status') === $key)>
                                        <span class="opt-box"><span><b>{{ $option['label'] }}</b><small>{{ $option['hint'] }}</small></span></span>
                                    </label>
                                @endforeach
                            </div>
                            <p class="err" role="alert">{{ $errors->first('business_status') }}</p>
                            <div class="step-nav">
                                <button type="button" class="btn btn-ghost" data-back>Kembali</button>
                                <button type="button" class="btn btn-primary" data-next>Lanjut</button>
                            </div>
                        </fieldset>

                        {{-- Langkah 3 — skala usaha --}}
                        <fieldset class="step" data-step="3">
                            <legend>Kira-kira berapa transaksi per hari?</legend>
                            <p class="hint">Belum buka? Isi perkiraan Anda.</p>
                            <div class="opts opts-2">
                                @foreach ($dailyTransactions as $key => $option)
                                    <label class="opt">
                                        <input type="radio" name="daily_transactions" value="{{ $key }}" required @checked(old('daily_transactions') === $key)>
                                        <span class="opt-box" style="min-height: 62px; justify-content: center;"><b>{{ $option['label'] }}</b></span>
                                    </label>
                                @endforeach
                            </div>
                            <p class="err" role="alert">{{ $errors->first('daily_transactions') }}</p>
                            <div class="step-nav">
                                <button type="button" class="btn btn-ghost" data-back>Kembali</button>
                                <button type="button" class="btn btn-primary" data-next>Lanjut</button>
                            </div>
                        </fieldset>

                        {{-- Langkah 4 — niat mulai --}}
                        <fieldset class="step" data-step="4">
                            <legend>Kapan ingin mulai pakai aplikasi kasir?</legend>
                            <p class="hint">Jawab apa adanya, tidak ada jawaban salah.</p>
                            <div class="opts">
                                @foreach ($startTimelines as $key => $option)
                                    <label class="opt">
                                        <input type="radio" name="start_timeline" value="{{ $key }}" required @checked(old('start_timeline') === $key)>
                                        <span class="opt-box"><span><b>{{ $option['label'] }}</b><small>{{ $option['hint'] }}</small></span></span>
                                    </label>
                                @endforeach
                            </div>
                            <p class="err" role="alert">{{ $errors->first('start_timeline') }}</p>
                            <div class="step-nav">
                                <button type="button" class="btn btn-ghost" data-back>Kembali</button>
                                <button type="button" class="btn btn-primary" data-next>Lanjut</button>
                            </div>
                        </fieldset>

                        {{-- Langkah 5 — kontak, diminta paling akhir --}}
                        <fieldset class="step" data-step="5">
                            <legend>Terakhir: ke mana hasilnya kami kirim?</legend>
                            <p class="hint">Tim kami menghubungi lewat WhatsApp, bukan telepon.</p>

                            <div class="field">
                                <label for="name">Nama Anda</label>
                                <input type="text" id="name" name="name" value="{{ old('name') }}" autocomplete="name" required minlength="2" maxlength="100" @if ($errors->has('name')) aria-invalid="true" @endif>
                                <p class="err" role="alert">{{ $errors->first('name') }}</p>
                            </div>
                            <div class="field">
                                <label for="business_name">Nama usaha</label>
                                <input type="text" id="business_name" name="business_name" value="{{ old('business_name') }}" autocomplete="organization" required minlength="2" maxlength="120" @if ($errors->has('business_name')) aria-invalid="true" @endif>
                                <p class="err" role="alert">{{ $errors->first('business_name') }}</p>
                            </div>
                            <div class="field">
                                <label for="whatsapp">Nomor WhatsApp</label>
                                <input type="tel" id="whatsapp" name="whatsapp" value="{{ old('whatsapp') }}" inputmode="tel" autocomplete="tel" placeholder="0812 3456 7890" required pattern="[0-9+\s\-]{9,20}" maxlength="20" @if ($errors->has('whatsapp')) aria-invalid="true" @endif>
                                <p class="err" role="alert">{{ $errors->first('whatsapp') }}</p>
                            </div>
                            <div class="field">
                                <label for="city">Kota / kabupaten <span style="font-weight: 500; color: var(--muted);">(opsional)</span></label>
                                <input type="text" id="city" name="city" value="{{ old('city') }}" autocomplete="address-level2" maxlength="100" @if ($errors->has('city')) aria-invalid="true" @endif>
                                <p class="err" role="alert">{{ $errors->first('city') }}</p>
                            </div>

                            <div class="step-nav" style="margin-top: 4px;">
                                <button type="button" class="btn btn-ghost" data-back>Kembali</button>
                                <button type="submit" class="btn btn-primary" id="submit-btn">Kirim &amp; lihat hasil</button>
                            </div>
                            <noscript><button type="submit" class="btn btn-primary btn-block">Kirim &amp; lihat hasil</button></noscript>
                            <p class="consent">Dengan mengirim, Anda setuju dihubungi tim MaccaPOS lewat WhatsApp dan menyetujui <a href="{{ url('/privacy') }}" target="_blank" rel="noopener">Kebijakan Privasi</a>. Nomor Anda tidak dibagikan ke pihak lain.</p>
                        </fieldset>
                    </form>
                </div>
                <p class="form-note">
                    <span><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m4.5 10.5 3.5 3.5 7.5-8"/></svg> Gratis, tanpa kartu kredit</span>
                    <span><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4.5" y="9" width="11" height="8" rx="2"/><path d="M7 9V6.5a3 3 0 0 1 6 0V9"/></svg> Data Anda tidak dibagikan</span>
                </p>
            </div>

            <div class="hero-proof">
                @if ($proof !== [])
                    <div class="proof">
                        @foreach ($proof as $item)
                            <div><b>{{ $item['value'] }}</b><span>{{ $item['label'] }}</span></div>
                        @endforeach
                    </div>
                @endif
                <ul class="ticks">
                    <li>{!! $check !!} Paket {{ $free['name'] }} gratis selamanya untuk {{ $free['max_stores'] ?? 1 }} outlet</li>
                    <li>{!! $check !!} Jalan di HP Android, tablet, dan laptop</li>
                    <li>{!! $check !!} Tunai, QRIS, dan transfer tercatat dalam satu laporan</li>
                </ul>
                <img class="hero-shot" src="{{ asset('tablet-mockup-sm.webp') }}" width="620" height="430" alt="Tampilan kasir Kasir UMKM di tablet: daftar produk dan keranjang" loading="lazy" decoding="async">
            </div>
        </div>
    </section>

    @php $tutorialVideo = public_path('videos/tutorial-daftar-jualan.mp4'); @endphp
    @if (is_file($tutorialVideo))
        <section class="sec sec-white" id="video">
            <div class="wrap tut">
                <div class="tut-copy">
                    <span class="mono">Tonton dulu · 1 menit</span>
                    <h2>Dari daftar sampai transaksi pertama.</h2>
                    <p class="lede">Ini tampilan asli aplikasinya, langkah demi langkah. Tidak perlu pelatihan khusus.</p>
                    <ol class="tut-steps">
                        <li><b>01</b> Daftar gratis &amp; siapkan toko</li>
                        <li><b>02</b> Tambah menu dan harganya</li>
                        <li><b>03</b> Buka kasir</li>
                        <li><b>04</b> Terima pembayaran, struk keluar</li>
                    </ol>
                    <a href="#form" class="btn btn-primary" data-cta="video">Cek kecocokan usaha Anda</a>
                </div>
                <div class="vid" id="tutorial-vid">
                    {{-- preload="none": video baru diunduh saat diputar, landing tetap ringan di data seluler. --}}
                    <video controls playsinline preload="none" width="720" height="1280"
                        poster="{{ asset('videos/tutorial-daftar-jualan-poster.webp') }}?v={{ filemtime($tutorialVideo) }}"
                        aria-label="Video tutorial Kasir UMKM: dari daftar sampai jualan">
                        <source src="{{ asset('videos/tutorial-daftar-jualan.mp4') }}?v={{ filemtime($tutorialVideo) }}" type="video/mp4">
                    </video>
                    <button type="button" class="vid-play" aria-label="Putar video tutorial">
                        <i><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 4.5v15a1 1 0 0 0 1.5.86l12-7.5a1 1 0 0 0 0-1.72l-12-7.5A1 1 0 0 0 7 4.5z"/></svg></i>
                        Putar video tutorial
                    </button>
                </div>
            </div>
        </section>
    @endif

    <section class="sec" style="padding-top: {{ is_file($tutorialVideo) ? '60px' : '0' }};">
        <div class="wrap">
            <span class="mono">Setelah Anda mengisi</span>
            <h2>Tiga langkah sampai kasir siap dipakai.</h2>
            <ol class="how">
                <li><b>Jawab 5 pertanyaan</b><span>Sekitar satu menit, cukup diketuk dari HP.</span></li>
                <li><b>Lihat hasilnya langsung</b><span>Anda tahu saat itu juga paket mana yang pas untuk usaha Anda.</span></li>
                <li><b>Chat tim kami di WhatsApp</b><span>Kami bantu siapkan akun dan memasukkan data awal usaha Anda.</span></li>
            </ol>
        </div>
    </section>

    <section class="sec sec-white">
        <div class="wrap">
            <span class="mono">Catat manual vs Kasir UMKM</span>
            <h2>Yang biasanya makan waktu, jadi beres sendiri.</h2>
            <p class="lede">Mencatat di buku dan menghitung stok dengan tangan rawan salah hitung dan selisih kas. Ini bedanya setelah pindah ke aplikasi.</p>

            <div class="versus">
                <div class="versus-row">
                    <div class="was"><b>Rekap penjualan ±2 jam tiap malam</b><span>Hitung ulang nota satu per satu.</span></div>
                    <div class="now"><b>{!! $check !!} Laporan harian jadi begitu toko tutup</b><span>Omzet, jumlah transaksi, dan metode bayar terekap otomatis.</span></div>
                </div>
                <div class="versus-row">
                    <div class="was"><b>Stok ditebak-tebak</b><span>Baru tahu habis saat pembeli bertanya.</span></div>
                    <div class="now"><b>{!! $check !!} Stok berkurang otomatis setiap terjual</b><span>Ada peringatan saat barang hampir habis.</span></div>
                </div>
                <div class="versus-row">
                    <div class="was"><b>Selisih kas tanpa jejak</b><span>Tidak jelas transaksi mana, siapa kasirnya.</span></div>
                    <div class="now"><b>{!! $check !!} Tiap transaksi tercatat waktu dan nama kasirnya</b><span>Hak akses kasir, manajer, dan pemilik bisa dibedakan.</span></div>
                </div>
            </div>
        </div>
    </section>

    <section class="sec">
        <div class="wrap">
            <span class="mono">Tampilan asli aplikasi</span>
            <h2>Sederhana. Karyawan baru pun langsung bisa.</h2>

            <div class="shots">
                <figure class="shot">
                    <img src="{{ asset('Pencatatan-800.webp') }}" width="800" height="577" alt="Layar kasir: daftar produk dan keranjang belanja" loading="lazy" decoding="async">
                    <figcaption><b>Kasir</b><span>Ketuk produk atau scan barcode, pilih cara bayar, cetak struk.</span></figcaption>
                </figure>
                <figure class="shot">
                    <img src="{{ asset('kontrol-produk-800.webp') }}" width="800" height="455" alt="Layar laporan: total omzet, jumlah transaksi, dan unit terjual" loading="lazy" decoding="async">
                    <figcaption><b>Laporan</b><span>Omzet dan jumlah transaksi per periode, bisa dibuka dari mana saja.</span></figcaption>
                </figure>
                <figure class="shot">
                    <img src="{{ asset('laporan-keuangan-800.webp') }}" width="800" height="388" alt="Grafik tren omzet harian" loading="lazy" decoding="async">
                    <figcaption><b>Tren penjualan</b><span>Lihat hari ramai dan sepi, lalu atur stok dan jadwal karyawan.</span></figcaption>
                </figure>
            </div>
        </div>
    </section>

    <section class="sec sec-white">
        <div class="wrap">
            <span class="mono">Biaya</span>
            <h2>Mulai gratis. Bayar hanya saat usaha butuh lebih.</h2>

            <div class="plans">
                <div class="plan">
                    <span class="mono">Paket {{ $free['name'] }}</span>
                    <p class="price">{{ $free['price_label'] }} <small>selamanya</small></p>
                    <p class="desc">{{ $free['description'] }}</p>
                    <ul class="ticks">
                        @foreach ($starterFeatures as $feature)
                            <li>{!! $check !!} {{ $feature }}</li>
                        @endforeach
                    </ul>
                </div>
                <div class="plan plan-paid">
                    <span class="mono">Paket {{ $paid['name'] }}</span>
                    @if ($paid['monthly_label'] !== '')
                        <p class="price">{{ $paid['monthly_label'] }} <small>per bulan</small></p>
                    @endif
                    <p class="desc">{{ $paid['description'] }}</p>
                    <ul class="ticks">
                        @foreach ($growUpFeatures as $feature)
                            <li>{!! $check !!} {{ $feature }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <section class="sec">
        <div class="wrap">
            <span class="mono">Sebelum Anda mengisi</span>
            <h2>Pertanyaan yang sering muncul</h2>

            <div class="faq">
                <details>
                    <summary>Apakah benar-benar gratis?</summary>
                    <p>Ya. Paket {{ $free['name'] }} gratis selamanya dengan transaksi tanpa batas. Fitur lanjutan seperti shift kasir, pembelian, diskon, dan multi-outlet ada di paket {{ $paid['name'] }}{{ $paid['monthly_label'] !== '' ? ' seharga '.$paid['monthly_label'].' per bulan' : '' }}.</p>
                </details>
                <details>
                    <summary>Saya masih catat di buku. Repot tidak pindahnya?</summary>
                    <p>Produk dan stok bisa diimpor sekaligus dari file Excel. Tim kami juga siap membantu memindahkan data awal dari spreadsheet atau aplikasi lama.</p>
                </details>
                <details>
                    <summary>Perlu beli alat kasir khusus?</summary>
                    <p>Tidak. Aplikasi berjalan di browser HP, tablet, maupun laptop, dan tersedia juga di Google Play. Struk bisa dicetak ke printer thermal atau printer Bluetooth bila Anda punya.</p>
                </details>
                <details>
                    <summary>Kenapa harus isi form dulu?</summary>
                    <p>Supaya tim kami bisa fokus mendampingi usaha yang memang siap mulai. Kalau usaha Anda masih tahap rencana, Anda tetap bisa langsung memakai paket gratis sendiri.</p>
                </details>
            </div>
        </div>
    </section>

    <section class="closing">
        <div class="wrap">
            <h2>Cek dulu, cocok tidak untuk usaha Anda.</h2>
            <p>Lima pertanyaan singkat, sekitar satu menit. Hasilnya langsung tampil.</p>
            <a href="#form" class="btn btn-lime" data-cta="closing">Mulai cek kecocokan</a>
        </div>
    </section>
</main>

<div class="sticky-cta" id="sticky-cta">
    <a href="#form" class="btn btn-primary btn-block" data-cta="sticky">Cek kecocokan · 1 menit</a>
</div>
@endsection

@push('scripts')
<script>
(function () {
    var form = document.getElementById('lead-form');
    if (!form) return;

    var steps = Array.prototype.slice.call(form.querySelectorAll('.step'));
    var total = steps.length;
    var bar = document.getElementById('progress-bar');
    var count = document.getElementById('step-count');
    var current = 0;
    var started = false;

    function pad(n) { return (n < 10 ? '0' : '') + n; }

    function show(index, focus) {
        current = Math.max(0, Math.min(total - 1, index));
        steps.forEach(function (step, i) { step.classList.toggle('is-active', i === current); });
        bar.style.width = ((current + 1) / total * 100) + '%';
        count.textContent = pad(current + 1) + '/' + pad(total);

        if (current === total - 1) window.adTrack('trackCustom', 'FormContactStep');

        if (focus) {
            form.classList.add('is-moving');
            var target = steps[current].querySelector('input:checked, input');
            // Langkah kontak: jangan fokus otomatis di HP — keyboard menutupi pertanyaan sebelum terbaca.
            if (target && (target.type === 'radio' || window.matchMedia('(min-width: 940px)').matches)) {
                target.focus({ preventScroll: true });
            }
            var top = form.getBoundingClientRect().top;
            if (top < 0) window.scrollBy({ top: top - 16, behavior: 'smooth' });
        }
    }

    function answered(step) {
        var radios = step.querySelectorAll('input[type="radio"]');
        if (!radios.length) return true;
        return Array.prototype.some.call(radios, function (r) { return r.checked; });
    }

    function next() {
        var step = steps[current];
        var err = step.querySelector('.err');
        if (!answered(step)) {
            if (err) err.textContent = 'Pilih salah satu dulu, ya.';
            return;
        }
        if (err) err.textContent = '';
        show(current + 1, true);
    }

    form.addEventListener('click', function (event) {
        if (event.target.closest('[data-next]')) { next(); return; }
        if (event.target.closest('[data-back]')) { show(current - 1, true); return; }
    });

    // Ketukan pada pilihan langsung lanjut. Pengguna keyboard (panah) tidak
    // dilompati: perubahan tanpa pointer menunggu tombol "Lanjut".
    var pointerPick = false;
    form.addEventListener('pointerdown', function (event) { pointerPick = !!event.target.closest('.opt'); });
    form.addEventListener('change', function (event) {
        if (!event.target.matches('input[type="radio"]')) return;
        if (!started) { started = true; window.adTrack('trackCustom', 'FormStart'); }
        var err = steps[current].querySelector('.err');
        if (err) err.textContent = '';
        if (pointerPick) { pointerPick = false; window.setTimeout(next, 170); }
    });

    form.addEventListener('submit', function (event) {
        // Jaga-jaga bila langkah sebelumnya terlewat (mis. tombol Enter).
        for (var i = 0; i < total - 1; i++) {
            if (!answered(steps[i])) {
                event.preventDefault();
                show(i, true);
                var err = steps[i].querySelector('.err');
                if (err) err.textContent = 'Pilih salah satu dulu, ya.';
                return;
            }
        }
        var button = document.getElementById('submit-btn');
        button.disabled = true;
        button.textContent = 'Mengirim…';
    });

    // Halaman dipulihkan dari cache riwayat (tombol kembali): tombol jangan tetap terkunci.
    window.addEventListener('pageshow', function () {
        var button = document.getElementById('submit-btn');
        button.disabled = false;
        button.textContent = 'Kirim & lihat hasil';
    });

    show(@json($errorStep ? $errorStep - 1 : 0), false);

    // CTA menempel: tampil hanya saat form tidak terlihat.
    var sticky = document.getElementById('sticky-cta');
    if (sticky && 'IntersectionObserver' in window) {
        new IntersectionObserver(function (entries) {
            sticky.classList.toggle('is-on', !entries[0].isIntersecting);
        }, { threshold: 0.05 }).observe(form);
    }

    document.querySelectorAll('[data-cta]').forEach(function (cta) {
        cta.addEventListener('click', function () {
            window.adTrack('trackCustom', 'CtaClick', { position: cta.getAttribute('data-cta') });
        });
    });
})();
</script>
@endpush

@push('scripts')
<script>
(function () {
    var box = document.getElementById('tutorial-vid');
    if (!box) return;
    var video = box.querySelector('video');
    var cover = box.querySelector('.vid-play');
    var sent = {};
    // Sekali per kunjungan: dipakai untuk audiens retargeting "menonton video".
    function once(name) {
        if (sent[name]) return;
        sent[name] = true;
        window.adTrack('trackCustom', name, { content_name: 'tutorial-daftar-jualan' });
    }
    cover.addEventListener('click', function () {
        box.classList.add('is-playing');
        var p = video.play();
        if (p && p.catch) p.catch(function () { box.classList.remove('is-playing'); });
    });
    video.addEventListener('play', function () { box.classList.add('is-playing'); once('VideoPlay'); });
    video.addEventListener('timeupdate', function () {
        if (video.duration && video.currentTime / video.duration >= 0.5) once('Video50');
    });
    video.addEventListener('ended', function () { once('VideoComplete'); });
})();
</script>
@endpush
