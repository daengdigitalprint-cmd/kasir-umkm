<?php

namespace App\Http\Controllers;

use App\Models\AdLandingSetting;
use App\Models\AdLead;
use App\Models\Store;
use App\Models\Transaction;
use App\Services\MetaConversionsApi;
use App\Support\AdLeadQualifier;
use App\Support\LandingPricing;
use App\Support\StoreLocationType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Landing page khusus trafik iklan Meta (/mulai).
 *
 * Sengaja Blade murni tanpa bundle aplikasi: pengunjung iklan hampir semua
 * dari HP dengan data seluler, dan setiap detik muat memangkas konversi.
 * Halaman ini tidak diindeks dan tidak punya menu keluar — satu tujuan:
 * mengisi form penyaring.
 */
class AdLandingController extends Controller
{
    private const SESSION_ATTRIBUTION = 'ad_landing.attribution';

    private const SESSION_LEAD = 'ad_landing.lead_id';

    private const SESSION_TRACK = 'ad_landing.track';

    private const ATTRIBUTION_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'fbclid'];

    /**
     * Judul per jenis usaha, dipilih lewat ?usaha=fnb di URL iklan supaya
     * isi halaman menyambung dengan iklan yang diklik (message match).
     *
     * @var array<string, array{lead: string, mark: string, sub: string}>
     */
    private const HEADLINES = [
        'default' => [
            'lead' => 'Jualan ramai, tapi',
            'mark' => 'uangnya ke mana?',
            'sub' => 'Kasir UMKM mencatat setiap penjualan, memotong stok otomatis, dan merekap laporan harian langsung dari HP. Tidak perlu lagi hitung ulang tiap malam.',
        ],
        'fnb' => [
            'lead' => 'Warung ramai, pesanan',
            'mark' => 'tercatat rapi sampai tutup.',
            'sub' => 'Pesanan meja, split bill, dan pembayaran tunai atau QRIS tercatat dari HP atau tablet kasir. Laporan harian langsung jadi.',
        ],
        'retail' => [
            'lead' => 'Stok toko selalu cocok,',
            'mark' => 'tanpa hitung ulang tiap malam.',
            'sub' => 'Scan barcode, stok berkurang otomatis setiap barang terjual, dan laporan penjualan harian langsung jadi di HP Anda.',
        ],
        'bakery' => [
            'lead' => 'Pesanan kue tercatat,',
            'mark' => 'stok bahan terpantau.',
            'sub' => 'Catat pre-order dan penjualan harian dari satu aplikasi, lengkap dengan stok dan laporan yang jadi sendiri.',
        ],
        'pharmacy' => [
            'lead' => 'Stok obat terpantau,',
            'mark' => 'kedaluwarsa tidak terlewat.',
            'sub' => 'Nomor batch, pengingat tanggal kedaluwarsa, dan stok per batch tercatat otomatis setiap ada penjualan.',
        ],
        'laundry' => [
            'lead' => 'Nota laundry rapi,',
            'mark' => 'status cucian jelas.',
            'sub' => 'Catat order kiloan dan satuan, estimasi selesai, dan status cucian dari HP. Pemasukan harian langsung terekap.',
        ],
        'barbershop' => [
            'lead' => 'Antrean rapi, komisi kapster',
            'mark' => 'terhitung sendiri.',
            'sub' => 'Antrean pelanggan, pilih kapster, dan komisi dihitung otomatis setiap transaksi selesai.',
        ],
    ];

    public function show(Request $request): View
    {
        app()->setLocale('id');

        $this->rememberAttribution($request);

        $usaha = (string) $request->query('usaha', '');
        $preselected = array_key_exists($usaha, AdLeadQualifier::BUSINESS_TYPES) ? $usaha : null;

        return view('ads.landing', [
            'headline' => self::HEADLINES[$preselected ?? 'default'] ?? self::HEADLINES['default'],
            'preselectedType' => $preselected,
            'businessTypes' => AdLeadQualifier::BUSINESS_TYPES,
            'businessStatuses' => AdLeadQualifier::BUSINESS_STATUSES,
            'dailyTransactions' => AdLeadQualifier::DAILY_TRANSACTIONS,
            'startTimelines' => AdLeadQualifier::START_TIMELINES,
            'pricing' => LandingPricing::payload(),
            'offerText' => AdLandingSetting::offerText(),
            'proof' => $this->socialProof(),
        ]);
    }

    public function store(Request $request, MetaConversionsApi $meta): RedirectResponse
    {
        app()->setLocale('id');

        // Honeypot: kolom tersembunyi yang hanya diisi bot. Dibalas seperti
        // sukses supaya bot tidak mencoba ulang dengan pola lain.
        if (filled($request->input('website'))) {
            return redirect()->route('ads.landing');
        }

        $request->merge([
            'whatsapp' => AdLeadQualifier::normalizeWhatsapp($request->input('whatsapp')) ?? $request->input('whatsapp'),
        ]);

        $data = $request->validate([
            'business_type' => ['required', Rule::in(array_keys(AdLeadQualifier::BUSINESS_TYPES))],
            'business_status' => ['required', Rule::in(array_keys(AdLeadQualifier::BUSINESS_STATUSES))],
            'daily_transactions' => ['required', Rule::in(array_keys(AdLeadQualifier::DAILY_TRANSACTIONS))],
            'start_timeline' => ['required', Rule::in(array_keys(AdLeadQualifier::START_TIMELINES))],
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'business_name' => ['required', 'string', 'min:2', 'max:120'],
            'whatsapp' => ['required', 'regex:/^628\d{7,12}$/'],
            // Opsional: tidak memengaruhi penilaian, jadi tidak boleh menahan pengisian.
            'city' => ['nullable', 'string', 'max:100'],
        ], [
            'business_type.*' => 'Pilih jenis usaha Anda.',
            'business_status.*' => 'Pilih kondisi usaha Anda saat ini.',
            'daily_transactions.*' => 'Pilih perkiraan jumlah transaksi per hari.',
            'start_timeline.*' => 'Pilih kapan Anda ingin mulai.',
            'name.*' => 'Isi nama Anda.',
            'business_name.*' => 'Isi nama usaha Anda.',
            'whatsapp.*' => 'Isi nomor WhatsApp aktif, contoh 0812 3456 7890.',
            'city.*' => 'Nama kota terlalu panjang.',
        ]);

        $result = AdLeadQualifier::evaluate($data['business_status'], $data['daily_transactions'], $data['start_timeline']);

        $lead = AdLead::firstOrNew(['whatsapp' => $data['whatsapp']]);
        $isRepeat = $lead->exists;

        // Atribusi iklan hanya ditimpa bila kunjungan ini memang membawa
        // parameter iklan; pengisian ulang tanpa UTM tidak menghapus asal lead.
        $attribution = array_filter((array) $request->session()->get(self::SESSION_ATTRIBUTION, []));

        $lead->fill([
            ...$data,
            'name' => Str::squish($data['name']),
            'business_name' => Str::squish($data['business_name']),
            'city' => filled($data['city'] ?? null) ? Str::squish($data['city']) : null,
            'score' => $result['score'],
            'tier' => $result['tier'],
            'event_id' => (string) Str::uuid(),
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            'fbp' => $this->metaCookie('_fbp') ?? $lead->fbp,
            'fbc' => $this->metaCookie('_fbc') ?? $this->fbcFromClickId($attribution['fbclid'] ?? null) ?? $lead->fbc,
            ...$attribution,
        ]);

        if ($isRepeat) {
            $lead->submissions = (int) $lead->submissions + 1;
        }

        $lead->save();

        $request->session()->put(self::SESSION_LEAD, $lead->id);
        $request->session()->flash(self::SESSION_TRACK, true);

        // Hanya lead serius yang dilaporkan ke Meta sebagai Lead.
        if ($lead->isQualified() && $meta->enabled()) {
            $thanksUrl = route('ads.thanks');
            defer(fn () => $meta->sendLead($lead, $thanksUrl));
        }

        return redirect()->route('ads.thanks');
    }

    public function thanks(Request $request): View|RedirectResponse
    {
        app()->setLocale('id');

        $lead = AdLead::find($request->session()->get(self::SESSION_LEAD));

        if (! $lead) {
            return redirect()->route('ads.landing');
        }

        $message = sprintf(
            'Halo tim MaccaPOS, saya %s dari %s (%s). Saya baru mengisi form di website dan ingin mulai memakai Kasir UMKM.',
            $lead->firstName(),
            $lead->business_name,
            Str::lower(AdLeadQualifier::label('business_type', $lead->business_type)),
        );

        return view('ads.thanks', [
            'lead' => $lead,
            'qualified' => $lead->isQualified(),
            // Flash: event konversi ditembak sekali, tidak ikut terulang saat halaman dimuat ulang.
            'shouldTrack' => (bool) $request->session()->get(self::SESSION_TRACK, false),
            'whatsappUrl' => 'https://wa.me/'.AdLeadQualifier::WHATSAPP_NUMBER.'?text='.rawurlencode($message),
            'offerText' => AdLandingSetting::offerText(),
            'answers' => [
                'Jenis usaha' => AdLeadQualifier::label('business_type', $lead->business_type),
                'Kondisi usaha' => AdLeadQualifier::label('business_status', $lead->business_status),
                'Transaksi per hari' => AdLeadQualifier::label('daily_transactions', $lead->daily_transactions),
                'Rencana mulai' => AdLeadQualifier::label('start_timeline', $lead->start_timeline),
            ],
        ]);
    }

    /**
     * Bukti sosial dari data aplikasi yang sebenarnya — tidak ada angka karangan.
     *
     * Hanya tampil bila angkanya sudah cukup meyakinkan; angka kecil justru
     * menurunkan kepercayaan. Dibulatkan ke bawah, jadi "120+" selalu benar.
     *
     * @return list<array{value: string, label: string}>
     */
    private function socialProof(): array
    {
        return Cache::remember('ad_landing.social_proof', now()->addHours(6), function () {
            try {
                $outlets = Store::query()
                    ->where('location_type', StoreLocationType::OUTLET)
                    ->where('is_active', true)
                    ->count();
                $transactions = Transaction::query()->completed()->count();
            } catch (\Throwable) {
                return [];
            }

            $proof = [];

            if ($outlets >= 50) {
                $step = $outlets >= 1000 ? 100 : 10;
                $proof[] = [
                    'value' => number_format(intdiv($outlets, $step) * $step, 0, ',', '.').'+',
                    'label' => 'outlet terdaftar',
                ];
            }

            if ($transactions >= 5000) {
                $proof[] = [
                    'value' => number_format(intdiv($transactions, 1000) * 1000, 0, ',', '.').'+',
                    'label' => 'transaksi tercatat',
                ];
            }

            return $proof;
        });
    }

    /** Simpan parameter iklan dari URL pertama agar tidak hilang saat form dikirim. */
    private function rememberAttribution(Request $request): void
    {
        $incoming = [];

        foreach (self::ATTRIBUTION_KEYS as $key) {
            $value = $request->query($key);

            if (is_string($value) && $value !== '') {
                $incoming[$key] = Str::limit($value, $key === 'fbclid' ? 255 : 100, '');
            }
        }

        if ($incoming !== []) {
            $request->session()->put(self::SESSION_ATTRIBUTION, $incoming);
        }
    }

    /**
     * Cookie Pixel (_fbp/_fbc) ditulis oleh JavaScript Meta, bukan oleh
     * Laravel, jadi tidak terenkripsi dan tidak terbaca lewat $request->cookie().
     */
    private function metaCookie(string $name): ?string
    {
        $value = $_COOKIE[$name] ?? null;

        return is_string($value) && preg_match('/^fb\.\d\.\d+\.[\w.-]+$/', $value) === 1 ? Str::limit($value, 255, '') : null;
    }

    /** Bentuk fbc dari fbclid bila cookie _fbc belum sempat ditulis Pixel. */
    private function fbcFromClickId(?string $fbclid): ?string
    {
        return filled($fbclid) ? 'fb.1.'.now()->getTimestampMs().'.'.$fbclid : null;
    }
}
