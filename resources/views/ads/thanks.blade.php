@extends('ads.layout')

@section('title', $qualified ? 'Usaha Anda cocok — Kasir UMKM by MaccaPOS' : 'Terima kasih — Kasir UMKM by MaccaPOS')
@section('top_note', 'Hasil cek kecocokan')

{{-- Pencocokan lanjutan: Pixel meng-hash nilai ini di browser sebelum dikirim ke Meta. --}}
@section('pixel_match'){!! json_encode(['ph' => $lead->whatsapp, 'fn' => mb_strtolower($lead->firstName()), 'country' => 'id'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}@endsection

@push('styles')
        .result { padding: 26px 0 64px; }
        .result .wrap { max-width: 560px; }
        .result h1 { font-size: clamp(28px, 7.4vw, 40px); letter-spacing: -0.02em; margin: 10px 0 12px; }
        .offer { display: flex; gap: 9px; align-items: flex-start; margin: 0 0 18px; padding: 11px 13px; border-radius: 11px; background: var(--lime); color: var(--lime-ink); font-size: 15px; font-weight: 700; line-height: 1.4; }
        .offer b { flex: none; font-family: var(--mono); font-size: 11.5px; font-weight: 500; letter-spacing: .08em; text-transform: uppercase; padding: 2px 7px; border-radius: 6px; background: var(--lime-ink); color: var(--lime); margin-top: 1px; }
        .result .lead-text { color: var(--muted); font-size: 16.5px; margin-bottom: 20px; }
        .badge { display: inline-flex; align-items: center; gap: 8px; padding: 6px 12px 6px 8px; border-radius: 999px; font-weight: 800; font-size: 13.5px; }
        .badge-ok { background: var(--lime); color: var(--lime-ink); }
        .badge-info { background: var(--purple-soft); color: var(--purple-deep); }
        .badge svg { width: 18px; height: 18px; }
        .actions { display: grid; gap: 10px; }
        .note { color: var(--muted); font-size: 14px; margin-top: 14px; }
        .rows { margin: 0; }
        .rows div { display: flex; justify-content: space-between; gap: 16px; padding: 7px 0; font-size: 14.5px; }
        .rows dt { color: var(--muted); }
        .rows dd { margin: 0; font-weight: 700; text-align: right; }
        .result .struk-shadow { margin-top: 30px; }
@endpush

@section('content')
<main class="result">
    <div class="wrap">
        @if ($qualified)
            <span class="badge badge-ok">
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m4.5 10.5 3.5 3.5 7.5-8"/></svg>
                Cocok untuk usaha Anda
            </span>
            <h1>{{ $lead->firstName() }}, Kasir UMKM pas untuk {{ $lead->business_name }}.</h1>
            <p class="lead-text">Satu langkah lagi: kirim pesan ke tim kami, supaya kami bisa langsung membantu menyiapkan akun dan data awal usaha Anda.</p>

            @if (filled($offerText))
                <p class="offer"><b>Bonus</b> <span>{{ $offerText }}</span></p>
            @endif

            <div class="actions">
                <a class="btn btn-wa btn-block" href="{{ $whatsappUrl }}" target="_blank" rel="noopener" id="wa-btn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.5 8.5 0 0 1-12.7 7.4L3 20l1.2-5A8.5 8.5 0 1 1 21 11.5z"/></svg>
                    Chat kami di WhatsApp
                </a>
                <a class="btn btn-ghost btn-block" href="{{ url('/register') }}" data-track="RegisterClick">Atau buat akun gratis sendiri</a>
            </div>
            <p class="note">Belum sempat chat sekarang? Tim kami akan menghubungi {{ $lead->whatsappLocal() }} lewat WhatsApp pada jam kerja.</p>
        @else
            <span class="badge badge-info">Jawaban tersimpan</span>
            <h1>Terima kasih, {{ $lead->firstName() }}. Mulai dari paket gratis dulu.</h1>
            <p class="lead-text">Untuk tahap usaha Anda sekarang, yang paling pas adalah paket Starter: gratis selamanya dan bisa langsung dicoba sendiri, tanpa menunggu tim kami.</p>

            <div class="actions">
                <a class="btn btn-primary btn-block" href="{{ url('/register') }}" data-track="RegisterClick">Buat akun gratis</a>
                <a class="btn btn-ghost btn-block" href="{{ url('/panduan') }}">Baca panduan memulai</a>
            </div>
            <p class="note">Usaha sudah siap jalan? Isi lagi form ini kapan saja, tim kami akan membantu sampai siap dipakai.</p>
        @endif

        <div class="struk-shadow">
            <div class="struk">
                <div class="struk-head">
                    <span class="mono">Ringkasan jawaban</span>
                    <span class="mono">Kasir UMKM</span>
                </div>
                <hr class="struk-rule">
                <dl class="rows">
                    <div><dt>Nama usaha</dt><dd>{{ $lead->business_name }}</dd></div>
                    @foreach ($answers as $question => $answer)
                        <div><dt>{{ $question }}</dt><dd>{{ $answer }}</dd></div>
                    @endforeach
                    <div><dt>WhatsApp</dt><dd>{{ $lead->whatsappLocal() }}</dd></div>
                </dl>
            </div>
        </div>
    </div>
</main>
@endsection

@push('scripts')
<script>
(function () {
    @if ($shouldTrack)
        // Semua pengisi form: bisa dipakai untuk optimasi saat jumlah Lead serius masih sedikit.
        window.adTrack('track', 'SubmitApplication', { content_name: 'landing-iklan' });
        @if ($qualified)
            // Hanya lead serius yang dihitung Lead — event_id sama dengan kiriman server (Conversions API).
            window.adTrack('track', 'Lead', { content_name: 'landing-iklan', lead_tier: @json($lead->tier) }, { eventID: @json($lead->event_id) });
        @else
            window.adTrack('trackCustom', 'LeadBelumSiap', { content_name: 'landing-iklan' });
        @endif
    @endif

    var wa = document.getElementById('wa-btn');
    if (wa) wa.addEventListener('click', function () { window.adTrack('track', 'Contact', { content_name: 'landing-iklan' }); });

    document.querySelectorAll('[data-track]').forEach(function (el) {
        el.addEventListener('click', function () { window.adTrack('trackCustom', el.getAttribute('data-track')); });
    });
})();
</script>
@endpush
