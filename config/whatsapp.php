<?php

return [
    /*
    |--------------------------------------------------------------------------
    | WhatsApp Gateway (go-wa / whatsmeow)
    |--------------------------------------------------------------------------
    |
    | base_url  : alamat HTTP gateway Anda (tanpa trailing slash).
    |             Default port 3000 sesuai gateway di C:\Users\melis\Herd\go-wa\gateway
    | token     : jika gateway dilindungi Bearer token, isi di sini.
    | timeout   : timeout HTTP ke gateway dalam detik.
    | recipients: fallback daftar nomor tujuan — dipisah koma, mis: "62812...,62813...".
    |             Jika dikosongkan, AlertPushService hanya akan kirim ke user yang
    |             punya kolom phone terisi.
    |
    | Gateway go-wa harus menerima:
    |   POST {base_url}/api/send  { to: "628xxx" | "628xxx@s.whatsapp.net" | "xxx@g.us", message: "..." }
    |   GET  {base_url}/api/state  health check (opsional, dipakai command test koneksi)
    */

    'base_url' => env('WHATSAPP_GATEWAY_URL', env('WHATSAPP_WEBHOOK_URL', 'http://localhost:3000')),

    'token' => env('WHATSAPP_TOKEN'),

    'timeout' => (int) env('WHATSAPP_TIMEOUT', 10),

    'recipients' => env('WHATSAPP_RECIPIENTS', env('ALERT_WHATSAPP_RECIPIENTS', '')),
];
