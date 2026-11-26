{{--
    Gaya halaman kota (indeks + per kota). Mengikuti tampilan halaman Program
    Afiliasi (resources/js/pages/Affiliate/Landing.vue): latar abu muda, isi
    selebar 1024px, tiap bagian berupa kartu membulat. Kartu hero memakai latar
    lavender + bentuk hiasan yang sama, kartu yang ditonjolkan memakai latar
    hijau muda seperti kartu "Paket tahunan · komisi terbesar".
--}}
<style>
    .kt-page { background: linear-gradient(135deg, #f8fafc, #f1f5f9); padding: 8px 0 40px; }
    .kt-wrap { max-width: 1024px; margin: 0 auto; padding: 0 16px; }
    @media (min-width: 640px) { .kt-wrap { padding: 0 24px; } }
    .kt-page .crumbs { padding: 14px 0 16px; }
    .kt-stack { display: grid; gap: 24px; grid-template-columns: minmax(0, 1fr); }

    /* ====== Kartu dasar ====== */
    .kt-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 20px; }
    @media (min-width: 640px) { .kt-card.roomy { padding: 24px; } }
    .kt-h2 { font-size: 18px; font-weight: 700; color: #0f172a; margin: 0 0 4px; letter-spacing: -0.01em; }
    .kt-sub { margin: 0 0 14px; font-size: 14px; line-height: 1.6; color: #475569; max-width: 680px; }
    .kt-h2.alone { margin-bottom: 12px; }
    .kt-card h3 { font-size: 16px; font-weight: 700; color: #0f172a; margin: 12px 0 4px; line-height: 1.4; }
    .kt-card p { margin: 0; font-size: 14px; line-height: 1.65; color: #475569; }

    .kt-grid { display: grid; gap: 12px; grid-template-columns: minmax(0, 1fr); }
    @media (min-width: 640px) { .kt-grid.two { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (min-width: 768px) { .kt-grid.three { grid-template-columns: repeat(3, minmax(0, 1fr)); } }

    .kt-chip-row { display: flex; align-items: center; gap: 12px; }
    .kt-chip { flex: none; width: 40px; height: 40px; border-radius: 12px; background: rgba(83, 58, 253, .1); color: var(--primary); display: inline-flex; align-items: center; justify-content: center; font-size: 20px; font-family: var(--font-display); font-weight: 800; }
    .kt-step-label { font-size: 12px; font-weight: 900; letter-spacing: .1em; text-transform: uppercase; color: #94a3b8; }
    .kt-more { display: inline-flex; align-items: center; gap: 6px; margin-top: 12px; font-size: 14px; font-weight: 700; color: var(--primary); }
    .kt-more:hover { color: var(--primary-dark); }
    .kt-solusi { margin-top: 12px !important; padding-top: 12px; border-top: 1px dashed #e2e8f0; color: #334155 !important; }
    .kt-solusi strong { color: var(--primary-dark); }
    .kt-plan { display: inline-block; margin-top: 10px; font-size: 12px; font-weight: 700; color: var(--primary-dark); background: var(--primary-soft); padding: 3px 10px; border-radius: 999px; }

    /* ====== Hero: kartu lavender + bentuk hiasan (sama dengan hero afiliasi) ====== */
    .kt-hero { position: relative; overflow: hidden; border-radius: 16px; background: #d4ceff; color: #0f172a; padding: 24px; }
    @media (min-width: 640px) { .kt-hero { padding: 40px; } }
    .kt-shapes { position: absolute; inset: 0; pointer-events: none; }
    .kt-shapes span { position: absolute; display: block; }
    .kt-shapes img { display: block; max-width: none; }
    .kt-shapes .s1, .kt-shapes .s2 { top: 0; height: 100%; max-height: 337px; }
    .kt-shapes .s1 { left: 0; }
    .kt-shapes .s2 { right: 0; }
    .kt-shapes .s1 img, .kt-shapes .s2 img { height: 100%; width: auto; }
    .kt-shapes .s3, .kt-shapes .s4 { bottom: 0; width: 112px; }
    .kt-shapes .s3 { left: 0; }
    .kt-shapes .s4 { right: 0; }
    .kt-shapes .s3 img, .kt-shapes .s4 img, .kt-shapes .s5 img { width: 100%; height: auto; }
    .kt-shapes .s5 { bottom: 0; left: 50%; width: 100%; transform: translateX(-50%); }
    @media (min-width: 640px) { .kt-shapes .s3, .kt-shapes .s4 { width: 176px; } }
    .kt-hero-body { position: relative; z-index: 1; max-width: 672px; }
    .kt-pill { display: inline-flex; align-items: center; gap: 6px; border-radius: 999px; background: rgba(255, 255, 255, .7); color: var(--primary); font-size: 12px; font-weight: 700; letter-spacing: .02em; padding: 5px 12px; }
    .kt-hero h1 { font-size: 30px; font-weight: 900; line-height: 1.2; letter-spacing: -0.025em; color: #0f172a; margin: 16px 0 0; }
    @media (min-width: 640px) { .kt-hero h1 { font-size: 36px; } }
    .kt-hero .lead { margin: 12px 0 0; font-size: 16px; line-height: 1.65; color: #334155; }
    .kt-acts { margin-top: 24px; display: flex; flex-direction: column; gap: 12px; }
    @media (min-width: 640px) { .kt-acts { flex-direction: row; align-items: center; } }
    .kt-btn { display: inline-flex; height: 48px; align-items: center; justify-content: center; gap: 8px; border-radius: 12px; background: var(--primary); color: #fff; padding: 0 24px; font-size: 16px; font-weight: 700; box-shadow: 0 10px 15px -3px rgba(83, 58, 253, .25); transition: transform .15s ease, background .2s ease; }
    .kt-btn:hover { color: #fff; transform: scale(1.02); }
    .kt-btn:focus-visible, .kt-link:focus-visible, .kt-more:focus-visible, .kt-city:focus-visible, .kt-links a:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
    .kt-btn.flat { box-shadow: none; }
    .kt-btn.flat:hover { transform: none; background: var(--primary-dark); }
    .kt-link { text-align: center; font-size: 14px; font-weight: 600; color: #334155; text-decoration: underline; text-underline-offset: 4px; }
    .kt-link:hover { color: #0f172a; }
    .kt-note { margin: 14px 0 0; font-size: 13px; color: #475569; }

    /* ====== Langkah bernomor ====== */
    .kt-steps { list-style: none; margin: 0; padding: 0; display: grid; gap: 12px; grid-template-columns: minmax(0, 1fr); }
    @media (min-width: 640px) { .kt-steps { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    .kt-steps li { border: 1px solid #e2e8f0; border-radius: 16px; background: #f8fafc; padding: 16px; }
    .kt-steps strong { display: block; margin-top: 10px; font-size: 15px; font-weight: 700; color: #0f172a; }
    .kt-steps span.t { display: block; margin-top: 2px; font-size: 14px; line-height: 1.6; color: #475569; }

    /* ====== Harga: kartu hijau yang ditonjolkan + kartu pembanding ====== */
    .kt-plans { display: grid; gap: 12px; grid-template-columns: minmax(0, 1fr); margin-top: 16px; }
    @media (min-width: 640px) { .kt-plans { grid-template-columns: repeat(5, minmax(0, 1fr)); } .kt-plans .kt-feature { grid-column: span 3; } .kt-plans .kt-second { grid-column: span 2; } }
    .kt-feature { position: relative; overflow: hidden; border-radius: 20px; background: #f2fff2; color: #0d1738; padding: 20px; }
    @media (min-width: 640px) { .kt-feature { padding: 24px; } }
    .kt-feature .kt-shapes .s3, .kt-feature .kt-shapes .s4 { width: 128px; }
    @media (min-width: 640px) { .kt-feature .kt-shapes .s3, .kt-feature .kt-shapes .s4 { width: 192px; } }
    .kt-feature .kt-shapes .s6, .kt-feature .kt-shapes .s7 { top: 50%; height: 100%; max-height: 370px; transform: translateY(-50%); }
    .kt-feature .kt-shapes .s6 { left: 0; }
    .kt-feature .kt-shapes .s7 { right: 0; }
    .kt-feature .kt-shapes .s6 img, .kt-feature .kt-shapes .s7 img { height: 100%; width: auto; }
    .kt-feature-body { position: relative; z-index: 1; }
    .kt-badge { display: inline-block; border-radius: 999px; background: var(--lime); color: #1a3a0a; font-size: 12px; font-weight: 900; letter-spacing: .04em; text-transform: uppercase; padding: 4px 12px; }
    .kt-badge.plain { background: #fff; color: #475569; font-weight: 700; box-shadow: inset 0 0 0 1px #e2e8f0; }
    .kt-plan-name { margin: 12px 0 0 !important; font-size: 14px !important; font-weight: 600; color: #5a5869 !important; }
    .kt-price { margin: 0 !important; font-size: 24px !important; font-weight: 900; line-height: 1.3 !important; color: #0d1738 !important; font-variant-numeric: tabular-nums; }
    @media (min-width: 640px) { .kt-price { font-size: 30px !important; } }
    .kt-price small { font-size: 16px; font-weight: 700; color: #5a5869; }
    .kt-inner { margin-top: 16px; border: 1px solid #d8ead8; border-radius: 12px; background: rgba(255, 255, 255, .8); padding: 16px; }
    .kt-inner .k { font-size: 14px !important; font-weight: 600; color: #5a5869 !important; }
    .kt-inner .v { margin-top: 2px !important; font-size: 28px !important; font-weight: 900; line-height: 1.25 !important; color: #047857 !important; font-variant-numeric: tabular-nums; }
    .kt-inner .d { margin-top: 4px !important; color: #5a5869 !important; }
    .kt-second { border: 1px solid #e2e8f0; border-radius: 16px; background: #f8fafc; padding: 20px; }
    @media (min-width: 640px) { .kt-second { padding: 24px; } }
    .kt-second .kt-price { font-size: 20px !important; color: #0f172a !important; }
    .kt-second .d { margin-top: 12px !important; }
    .kt-fine { margin: 12px 0 0 !important; font-size: 12px !important; color: #64748b !important; }

    /* ====== Daftar berbutir & baris kontak ====== */
    .kt-list { list-style: none; margin: 12px 0 0; padding: 0; display: grid; gap: 10px; }
    .kt-list li { display: flex; gap: 10px; align-items: flex-start; font-size: 14px; line-height: 1.65; color: #334155; }
    .kt-list li::before { content: ''; flex: none; width: 6px; height: 6px; margin-top: 9px; border-radius: 999px; background: var(--primary); }
    .kt-rows { display: grid; gap: 12px; grid-template-columns: minmax(0, 1fr); margin-top: 16px; }
    @media (min-width: 768px) { .kt-rows { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
    .kt-row { display: flex; gap: 12px; align-items: flex-start; border: 1px solid #e2e8f0; border-radius: 16px; background: #f8fafc; padding: 14px 16px; color: #0f172a; min-width: 0; }
    a.kt-row:hover { border-color: #c3b9fb; color: #0f172a; }
    .kt-row .kt-chip { background: #fff; box-shadow: inset 0 0 0 1px #e2e8f0; }
    .kt-row .k { display: block; font-size: 12px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #64748b; }
    .kt-row .v { display: block; margin-top: 2px; font-size: 14px; font-weight: 600; line-height: 1.5; color: #0f172a; }

    /* ====== FAQ ====== */
    .kt-faq { margin-top: 12px; display: grid; gap: 10px; }
    .kt-faq details { border: 1px solid #e2e8f0; border-radius: 16px; background: #f8fafc; padding: 14px 16px; }
    .kt-faq details[open] { border-color: #c3b9fb; background: var(--primary-muted); }
    .kt-faq summary { font-family: var(--font-display); font-weight: 700; font-size: 15px; color: #0f172a; cursor: pointer; list-style: none; display: flex; justify-content: space-between; gap: 14px; align-items: flex-start; min-height: 24px; }
    .kt-faq summary::-webkit-details-marker { display: none; }
    .kt-faq summary::after { content: '+'; color: var(--primary); font-size: 20px; line-height: 1.15; flex: none; }
    .kt-faq details[open] summary::after { content: '−'; }
    .kt-faq details p { margin-top: 10px; color: #334155; }

    /* ====== Tautan kota & jenis usaha ====== */
    .kt-links { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
    .kt-links a { display: inline-flex; align-items: center; min-height: 40px; background: #f8fafc; border: 1px solid #e2e8f0; color: #0f172a; font-weight: 600; font-size: 14px; padding: 6px 14px; border-radius: 999px; }
    .kt-links a:hover { border-color: var(--primary); color: var(--primary); background: #fff; }
    .kt-links a.all { background: var(--primary-soft); border-color: transparent; color: var(--primary-dark); }
    .kt-links-label { margin: 18px 0 0 !important; font-size: 12px !important; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #64748b !important; }

    /* ====== Kartu kota (halaman indeks) ====== */
    .kt-city { display: flex; flex-direction: column; background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 20px; color: inherit; transition: border-color .2s ease; }
    .kt-city:hover { border-color: #c3b9fb; color: inherit; }
    .kt-city h3 { font-size: 17px; font-weight: 800; color: #0f172a; margin: 12px 0 4px; }
    .kt-city p { margin: 0; font-size: 14px; line-height: 1.6; color: #475569; flex: 1; }
    .kt-city:hover .kt-more { color: var(--primary-dark); }
    .kt-prov { display: flex; align-items: baseline; gap: 10px; margin: 0 0 12px; }
    .kt-prov span { font-size: 13px; font-weight: 600; color: #64748b; }

    @media (prefers-reduced-motion: reduce) { .kt-btn, .kt-city { transition: none; } .kt-btn:hover { transform: none; } }
</style>
