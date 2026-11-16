<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | Google Search Console — meta name="google-site-verification"
    */
    'google' => [
        'site_verification' => env('GOOGLE_SITE_VERIFICATION'),
    ],

    /*
    | Meta (Facebook/Instagram) Ads — landing page iklan /mulai.
    | Cadangan saja: nilai utama diisi di Super Admin > Lead Iklan dan
    | didahulukan dari nilai .env ini (lihat App\Models\AdLandingSetting).
    | pixel_id        : ID Pixel/Dataset dari Events Manager. Kosong = Pixel tidak dimuat.
    | capi_token      : token Conversions API (opsional). Diisi = event Lead juga dikirim dari server.
    | test_event_code : kode dari tab "Test Events" saat menguji; kosongkan di produksi.
    */
    'meta' => [
        'pixel_id' => env('META_PIXEL_ID'),
        'capi_token' => env('META_CAPI_TOKEN'),
        'test_event_code' => env('META_TEST_EVENT_CODE'),
        'graph_version' => env('META_GRAPH_VERSION', 'v24.0'),
    ],

];
