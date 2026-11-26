<?php

/*
|--------------------------------------------------------------------------
| Program Afiliasi Kasir UMKM
|--------------------------------------------------------------------------
| Aturan program. Persen komisi disalin ke tiap baris komisi saat dicatat,
| jadi mengubah angka di sini tidak mengubah komisi yang sudah ada.
*/

return [
    // Persen komisi dari setiap pembayaran yang dikonfirmasi (paket maupun add-on).
    'commission_percent' => (float) env('AFFILIATE_COMMISSION_PERCENT', 20),

    // 0 = komisi SEKALI saja per toko, dari paket pertama yang dibelinya (bawaan, seperti afiliasi pada umumnya).
    // Angka > 0 = komisi berulang dari setiap pembayaran selama sekian bulan sejak pembayaran pertama.
    'commission_months' => (int) env('AFFILIATE_COMMISSION_MONTHS', 0),

    // Link afiliasi diingat sekian hari sejak diklik (klik terakhir yang menang).
    'cookie_days' => (int) env('AFFILIATE_COOKIE_DAYS', 30),

    // Komisi ditahan sekian hari sebelum siap dibayar (menutup masa refund 7 hari).
    'hold_days' => (int) env('AFFILIATE_HOLD_DAYS', 14),

    // Komisi ditransfer setelah saldo siap bayar mencapai nominal ini.
    'min_payout' => (int) env('AFFILIATE_MIN_PAYOUT', 100000),

    // Toko yang mendaftar lewat link afiliasi mendapat trial Pro selama ini (toko biasa: 14 hari).
    // Isi 0 untuk mematikan keuntungan ini.
    'referred_trial_days' => (int) env('AFFILIATE_REFERRED_TRIAL_DAYS', 30),

    'cookie_name' => 'kumkm_ref',
];
