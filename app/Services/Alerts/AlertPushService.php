<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Models\Store;
use App\Models\User;
use App\Notifications\AiAlertPushNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AlertPushService
{
    /**
     * Kirim peringatan ke channel di luar aplikasi.
     * Semua channel opsional — tidak terkonfigurasi = dilewati diam-diam.
     * Kegagalan di satu channel tidak menghentikan channel lain.
     */
    public function push(User $user, Store $store, array $alert): void
    {
        if (! (bool) config('alerts.push.enabled', true)) {
            return;
        }

        $subject = "[{$store->name}] {$alert['title']}";
        $textBody = $this->buildTextBody($store, $alert);

        if ((bool) config('alerts.push.mail_enabled', true)) {
            $this->pushMail($user, $alert, $store);
        }

        $this->pushTelegram($subject, $textBody, $store, $alert);
        $this->pushWhatsapp($user, $subject, $textBody, $store, $alert);
    }

    private function buildTextBody(Store $store, array $alert): string
    {
        $lines = [
            $alert['title'].' — '.$store->name,
            '',
            $alert['message'],
        ];

        if (! empty($alert['data'])) {
            $lines[] = '';
            $lines[] = 'Detail: '.json_encode($alert['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return implode("\n", $lines);
    }

    private function pushMail(User $user, array $alert, Store $store): void
    {
        if (empty($user->email) || ! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        try {
            $user->notify(new AiAlertPushNotification($store, $alert));
        } catch (\Throwable $e) {
            Log::warning('Alert push mail gagal', [
                'user_id' => $user->id,
                'store_id' => $store->id,
                'type' => $alert['type'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function pushTelegram(string $subject, string $textBody, Store $store, array $alert): void
    {
        $cfg = config('alerts.push.telegram', []);
        if (empty($cfg['enabled']) || empty($cfg['bot_token']) || empty($cfg['chat_ids'])) {
            return;
        }

        $botToken = (string) $cfg['bot_token'];
        $chatIds = array_filter(array_map('trim', explode(',', (string) $cfg['chat_ids'])));

        if ($chatIds === []) {
            return;
        }

        $text = $subject."\n\n".$textBody;

        foreach ($chatIds as $chatId) {
            try {
                $response = Http::timeout(10)->post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $text,
                ]);

                if ($response->failed()) {
                    Log::warning('Alert push telegram gagal', [
                        'chat_id' => $chatId,
                        'store_id' => $store->id,
                        'type' => $alert['type'] ?? null,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('Alert push telegram exception', [
                    'chat_id' => $chatId,
                    'store_id' => $store->id,
                    'type' => $alert['type'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Kirim via go-wa gateway (POST /api/send {to, message}).
     * Dukungan:
     *  - WHATSAPP_GATEWAY_URL / WHATSAPP_WEBHOOK_URL (base URL, cth: http://localhost:3000)
     *  - WHATSAPP_RECIPIENTS / ALERT_WHATSAPP_RECIPIENTS (fallback jika user tidak punya phone)
     *  - WHATSAPP_TOKEN (Bearer token jika gateway diproteksi)
     * Jika gateway berupa webhook generik lain, field tetap kompatibel.
     */
    private function pushWhatsapp(User $user, string $subject, string $textBody, Store $store, array $alert): void
    {
        $cfg = config('alerts.push.whatsapp', []);
        $waCfg = config('whatsapp', []);
        if (empty($cfg['enabled'])) {
            return;
        }

        $baseUrl = trim((string) ($waCfg['base_url'] ?? ''));
        if ($baseUrl === '') {
            $baseUrl = trim((string) ($cfg['webhook_url'] ?? ''));
        }
        if ($baseUrl === '') {
            return;
        }

        $token = trim((string) ($waCfg['token'] ?? ''));
        if ($token === '') {
            $token = trim((string) ($cfg['token'] ?? ''));
        }
        $timeout = (int) ($waCfg['timeout'] ?? 10);
        $text = $subject."\n\n".$textBody;

        // Prioritas: Pengaturan Toko (alert_whatsapp_numbers) > phone user > env WHATSAPP_RECIPIENTS
        $recipients = $this->resolveWhatsappRecipients($user, $store, $cfg, $waCfg);

        if ($recipients === []) {
            Log::warning('Alert push whatsapp dilewati: tidak ada nomor penerima', [
                'store_id' => $store->id,
                'user_id' => $user->id,
                'type' => $alert['type'] ?? null,
            ]);

            return;
        }

        // Normalisasi baseUrl -> endpoint /api/send (jika sudah /api/send, biarkan)
        $url = rtrim($baseUrl, '/');
        if (! str_ends_with($url, '/api/send') && ! str_ends_with($url, '/send')) {
            $url .= '/api/send';
        }

        foreach ($recipients as $to) {
            $jid = $this->normalizeWhatsappJid($to);
            try {
                $request = Http::timeout($timeout)->asJson();

                if ($token !== '') {
                    $request = $request->withToken($token);
                }

                $response = $request->post($url, [
                    'to' => $jid,
                    'message' => $text,
                ]);

                if ($response->failed()) {
                    Log::warning('Alert push whatsapp gagal', [
                        'store_id' => $store->id,
                        'to' => $jid,
                        'type' => $alert['type'] ?? null,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('Alert push whatsapp exception', [
                    'store_id' => $store->id,
                    'to' => $jid,
                    'type' => $alert['type'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function resolveWhatsappRecipients(User $user, Store $store, array $alertCfg, array $waCfg): array
    {
        $recipients = [];

        // Pengaturan toko (kolom utama yang diisi admin di halaman Pengaturan)
        $storeNumbers = trim((string) ($store->settings['alert_whatsapp_numbers'] ?? ''));
        foreach (array_filter(array_map('trim', explode(',', $storeNumbers))) as $num) {
            if ($num !== '' && ! in_array($num, $recipients, true)) {
                $recipients[] = $num;
            }
        }

        $userPhone = trim((string) ($user->phone ?? ''));
        if ($userPhone !== '' && ! in_array($userPhone, $recipients, true)) {
            $recipients[] = $userPhone;
        }

        // Fallback dari env: WHATSAPP_RECIPIENTS atau ALERT_WHATSAPP_RECIPIENTS
        $envRecipients = (string) ($waCfg['recipients'] ?? $alertCfg['recipients'] ?? '');
        foreach (array_filter(array_map('trim', explode(',', $envRecipients))) as $num) {
            if ($num !== '' && ! in_array($num, $recipients, true)) {
                $recipients[] = $num;
            }
        }

        return array_values(array_filter($recipients));
    }

    private function normalizeWhatsappJid(string $to): string
    {
        $to = trim($to);
        if ($to === '') {
            return $to;
        }

        // Sudah JID lengkap: biarkan (xxx@s.whatsapp.net, xxx@g.us)
        if (str_contains($to, '@')) {
            return $to;
        }

        // Bersihkan non-digit untuk nomor biasa lalu gabungkan dengan suffix whatsmeow
        $digits = preg_replace('/\D+/', '', $to) ?? '';
        if ($digits === '') {
            return $to;
        }

        return $digits.'@s.whatsapp.net';
    }
}
