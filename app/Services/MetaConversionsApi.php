<?php

namespace App\Services;

use App\Models\AdLandingSetting;
use App\Models\AdLead;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Kirim event Lead ke Meta Conversions API (server-side).
 *
 * Melengkapi Pixel di browser: event yang sama dikirim dengan event_id yang
 * sama sehingga Meta menghitungnya sekali (deduplikasi), tetapi tetap
 * tercatat saat Pixel diblokir (iOS, pemblokir iklan, in-app browser).
 *
 * Nonaktif sampai ID Pixel dan token Conversions API diisi di
 * Super Admin > Lead Iklan (atau META_PIXEL_ID / META_CAPI_TOKEN di .env).
 */
class MetaConversionsApi
{
    public function enabled(): bool
    {
        return AdLandingSetting::pixelId() !== null && AdLandingSetting::capiToken() !== null;
    }

    /**
     * Tidak pernah melempar exception: kegagalan pelacakan iklan tidak boleh
     * menggagalkan penyimpanan lead.
     */
    public function sendLead(AdLead $lead, string $eventSourceUrl): bool
    {
        if (! $this->enabled() || blank($lead->event_id)) {
            return false;
        }

        $payload = [
            'data' => [[
                'event_name' => 'Lead',
                'event_time' => now()->timestamp,
                'event_id' => $lead->event_id,
                'action_source' => 'website',
                'event_source_url' => $eventSourceUrl,
                'user_data' => $this->userData($lead),
                'custom_data' => [
                    'content_name' => 'landing-iklan',
                    'lead_tier' => $lead->tier,
                    'business_type' => $lead->business_type,
                ],
            ]],
            'access_token' => AdLandingSetting::capiToken(),
        ];

        if (AdLandingSetting::testEventCode() !== null) {
            $payload['test_event_code'] = AdLandingSetting::testEventCode();
        }

        $url = sprintf(
            'https://graph.facebook.com/%s/%s/events',
            config('services.meta.graph_version', 'v24.0'),
            AdLandingSetting::pixelId(),
        );

        try {
            $response = Http::timeout(5)->asJson()->post($url, $payload);

            if ($response->failed()) {
                Log::warning('Meta CAPI: event Lead ditolak', [
                    'lead_id' => $lead->id,
                    'status' => $response->status(),
                    'error' => $response->json('error.message'),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('Meta CAPI: gagal mengirim event Lead', [
                'lead_id' => $lead->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Data pencocokan. Identitas pribadi di-hash SHA-256 sesuai ketentuan
     * Meta; IP, user agent, fbp, dan fbc dikirim apa adanya.
     *
     * @return array<string, mixed>
     */
    private function userData(AdLead $lead): array
    {
        return array_filter([
            'ph' => [$this->hash($lead->whatsapp)],
            'fn' => [$this->hash($lead->firstName())],
            'ct' => filled($lead->city) ? [$this->hash(preg_replace('/[^a-z]/', '', mb_strtolower((string) $lead->city)))] : null,
            'country' => [$this->hash('id')],
            'client_ip_address' => $lead->ip,
            'client_user_agent' => $lead->user_agent,
            'fbp' => $lead->fbp,
            'fbc' => $lead->fbc,
        ], fn ($value) => filled($value));
    }

    private function hash(?string $value): string
    {
        return hash('sha256', mb_strtolower(trim((string) $value)));
    }
}
