<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AdLandingSetting;
use App\Models\AdLead;
use App\Support\AdLeadQualifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Lead dari landing page iklan (/mulai): daftar, tindak lanjut, ekspor,
 * dan pengaturan Meta Pixel untuk halaman itu.
 */
class AdLeadController extends Controller
{
    public function index(Request $request): Response
    {
        $leads = $this->filtered($request)
            // Yang paling layak dihubungi dulu: serius, belum dihubungi, terbaru.
            ->orderByRaw("CASE tier WHEN 'hot' THEN 0 WHEN 'warm' THEN 1 ELSE 2 END")
            ->orderByRaw("CASE status WHEN 'new' THEN 0 ELSE 1 END")
            ->orderByDesc('updated_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (AdLead $lead) => [
                'id' => $lead->id,
                'name' => $lead->name,
                'business_name' => $lead->business_name,
                'whatsapp' => $lead->whatsapp,
                'whatsapp_local' => $lead->whatsappLocal(),
                'city' => $lead->city,
                'business_type' => AdLeadQualifier::label('business_type', $lead->business_type),
                'business_status' => AdLeadQualifier::label('business_status', $lead->business_status),
                'daily_transactions' => AdLeadQualifier::label('daily_transactions', $lead->daily_transactions),
                'start_timeline' => AdLeadQualifier::label('start_timeline', $lead->start_timeline),
                'score' => $lead->score,
                'tier' => $lead->tier,
                'tier_label' => AdLeadQualifier::tierLabel($lead->tier),
                'status' => $lead->status,
                'notes' => $lead->notes,
                'utm_campaign' => $lead->utm_campaign,
                'utm_content' => $lead->utm_content,
                'utm_source' => $lead->utm_source,
                'submissions' => $lead->submissions,
                'submitted_at' => $lead->updated_at?->toISOString(),
                'contacted_at' => $lead->contacted_at?->toISOString(),
            ]);

        $total = AdLead::count();
        $qualified = AdLead::qualified()->count();

        return Inertia::render('SuperAdmin/Leads/Index', [
            'leads' => $leads,
            'stats' => [
                'total' => $total,
                'qualified' => $qualified,
                'qualified_rate' => $total > 0 ? (int) round($qualified / $total * 100) : 0,
                'to_contact' => AdLead::qualified()->where('status', 'new')->count(),
                'converted' => AdLead::where('status', 'converted')->count(),
            ],
            'statuses' => AdLead::STATUSES,
            'settings' => $this->settingsPayload(),
            'filters' => [
                'q' => $request->query('q'),
                'tier' => $request->query('tier'),
                'status' => $request->query('status'),
            ],
        ]);
    }

    public function update(Request $request, AdLead $lead): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', 'required', Rule::in(array_keys(AdLead::STATUSES))],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        if (($data['status'] ?? null) === 'contacted' && $lead->contacted_at === null) {
            $data['contacted_at'] = now();
        }

        // timestamps = false: updated_at dipakai sebagai "waktu isi form
        // terakhir" untuk urutan daftar, jadi tidak ikut berubah saat admin
        // memberi catatan.
        $lead->timestamps = false;
        $lead->update($data);

        return back();
    }

    /**
     * Simpan pengaturan Meta Pixel & penawaran landing iklan.
     *
     * Token Conversions API tidak pernah dikirim balik ke browser: kolom
     * kosong berarti "biarkan yang tersimpan", dan dihapus lewat centang.
     */
    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'meta_pixel_id' => ['nullable', 'string', 'max:5000'],
            'meta_capi_token' => ['nullable', 'string', 'max:1000'],
            'clear_capi_token' => ['sometimes', 'boolean'],
            'meta_test_event_code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'offer_text' => ['nullable', 'string', 'max:160'],
        ], [
            'meta_test_event_code.regex' => 'Kode uji hanya berisi huruf dan angka, contoh TEST12345.',
            'offer_text.max' => 'Teks penawaran maksimal 160 karakter.',
        ]);

        $pixelId = AdLandingSetting::extractPixelId($data['meta_pixel_id'] ?? null);

        if (filled($data['meta_pixel_id'] ?? null) && $pixelId === null) {
            throw ValidationException::withMessages([
                'meta_pixel_id' => 'ID Pixel tidak dikenali. Isi angkanya saja (contoh 123456789012345) atau tempel kode Pixel utuh dari Events Manager.',
            ]);
        }

        $setting = AdLandingSetting::current();
        $setting->meta_pixel_id = $pixelId;
        $setting->meta_test_event_code = $data['meta_test_event_code'] ?? null;
        $setting->offer_text = filled($data['offer_text'] ?? null) ? trim($data['offer_text']) : null;

        if ($request->boolean('clear_capi_token')) {
            $setting->meta_capi_token = null;
        } elseif (filled($data['meta_capi_token'] ?? null)) {
            $setting->meta_capi_token = trim($data['meta_capi_token']);
        }

        $setting->save();

        return back();
    }

    /** CSV (dibuka Excel) — mengikuti filter yang sedang aktif. */
    public function export(Request $request): StreamedResponse
    {
        $query = $this->filtered($request)->orderByDesc('updated_at');

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            // BOM agar Excel membaca UTF-8 dengan benar.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Waktu isi', 'Nama', 'Nama usaha', 'WhatsApp', 'Kota', 'Jenis usaha', 'Kondisi usaha',
                'Transaksi/hari', 'Rencana mulai', 'Nilai', 'Tingkat', 'Status', 'Catatan',
                'Sumber', 'Kampanye', 'Konten iklan',
            ]);

            $query->chunk(500, function ($leads) use ($out) {
                foreach ($leads as $lead) {
                    fputcsv($out, array_map([self::class, 'csvSafe'], [
                        $lead->updated_at?->format('Y-m-d H:i'),
                        $lead->name,
                        $lead->business_name,
                        $lead->whatsapp,
                        $lead->city,
                        AdLeadQualifier::label('business_type', $lead->business_type),
                        AdLeadQualifier::label('business_status', $lead->business_status),
                        AdLeadQualifier::label('daily_transactions', $lead->daily_transactions),
                        AdLeadQualifier::label('start_timeline', $lead->start_timeline),
                        $lead->score,
                        AdLeadQualifier::tierLabel($lead->tier),
                        AdLead::STATUSES[$lead->status] ?? $lead->status,
                        $lead->notes,
                        $lead->utm_source,
                        $lead->utm_campaign,
                        $lead->utm_content,
                    ]));
                }
            });

            fclose($out);
        }, 'lead-iklan-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Pengaturan untuk form di panel. Token hanya dilaporkan ada/tidaknya.
     *
     * @return array<string, mixed>
     */
    private function settingsPayload(): array
    {
        $setting = AdLandingSetting::current();

        try {
            $hasToken = filled($setting->meta_capi_token);
        } catch (\Throwable) {
            // Token tidak bisa didekripsi (APP_KEY berganti): minta diisi ulang.
            $hasToken = false;
        }

        return [
            'meta_pixel_id' => $setting->meta_pixel_id,
            'has_capi_token' => $hasToken,
            'meta_test_event_code' => $setting->meta_test_event_code,
            'offer_text' => $setting->offer_text,
            // Yang benar-benar dipakai landing saat ini (panel admin atau cadangan .env).
            'active_pixel_id' => AdLandingSetting::pixelId(),
            'pixel_from_env' => blank($setting->meta_pixel_id) && AdLandingSetting::pixelId() !== null,
            'capi_active' => AdLandingSetting::pixelId() !== null && AdLandingSetting::capiToken() !== null,
        ];
    }

    private function filtered(Request $request): Builder
    {
        return AdLead::query()
            ->when($request->query('q'), fn ($q, $search) => $q->where(function ($w) use ($search) {
                $w->where('name', 'like', "%{$search}%")
                    ->orWhere('business_name', 'like', "%{$search}%")
                    ->orWhere('whatsapp', 'like', '%'.preg_replace('/\D+/', '', (string) $search).'%')
                    ->orWhere('city', 'like', "%{$search}%")
                    ->orWhere('utm_campaign', 'like', "%{$search}%");
            }))
            ->when($request->query('tier') === 'qualified', fn ($q) => $q->qualified())
            ->when(in_array($request->query('tier'), ['hot', 'warm', 'cold'], true), fn ($q) => $q->where('tier', $request->query('tier')))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status));
    }

    /**
     * Isi form berasal dari publik: awalan = + - @ bisa dijalankan Excel
     * sebagai rumus (CSV injection), jadi diberi tanda petik di depan.
     */
    private static function csvSafe(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
