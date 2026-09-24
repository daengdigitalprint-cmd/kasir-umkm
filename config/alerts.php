<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Push Peringatan Proaktif (keluar aplikasi)
    |--------------------------------------------------------------------------
    |
    | In-app (AiAlert + WebSocket) selalu aktif. Blok ini mengendalikan
    | pengiriman push "menyusul" UMKM di channel yang mereka sudah pakai.
    | Semua channel bersifat opsional — kosong/nonaktif = dilewati tanpa error.
    |
    */

    'push' => [
        'enabled' => (bool) env('ALERT_PUSH_ENABLED', true),

        'mail_enabled' => (bool) env('ALERT_PUSH_MAIL_ENABLED', true),

        'telegram' => [
            'enabled' => (bool) env('ALERT_PUSH_TELEGRAM_ENABLED', false),
            'bot_token' => env('TELEGRAM_BOT_TOKEN'),
            // Satu atau beberapa chat ID dipisah koma, mis: "123,456" atau "-100123..."
            'chat_ids' => env('TELEGRAM_CHAT_IDS'),
        ],

        'whatsapp' => [
            'enabled' => (bool) env('ALERT_PUSH_WHATSAPP_ENABLED', false),
            // Gateway go-wa (whatsmeow, port 3000) — fallback ke WHATSAPP_WEBHOOK_URL untuk kompatibel
            'webhook_url' => env('WHATSAPP_WEBHOOK_URL', env('WHATSAPP_GATEWAY_URL')),
            'token' => env('WHATSAPP_TOKEN'),
            // Daftar nomor tujuan fallback — dipisah koma (628xxx atau 628xxx@s.whatsapp.net)
            'recipients' => env('WHATSAPP_RECIPIENTS', env('ALERT_WHATSAPP_RECIPIENTS')),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Ambang Deteksi Margin Turun
    |--------------------------------------------------------------------------
    */

    'margin' => [
        // Penurunan margin (poin persentase) yang dianggap signifikan
        'drop_points_threshold' => (float) env('ALERT_MARGIN_DROP_POINTS', 5.0),
        // Atau penurunan relatif (%) sebagai alternatif trigger
        'drop_percent_threshold' => (float) env('ALERT_MARGIN_DROP_PERCENT', 15.0),
        // Periode pembanding: 7 hari terakhir vs 7 hari sebelumnya
        'window_days' => (int) env('ALERT_MARGIN_WINDOW_DAYS', 7),
    ],
];
