<?php

declare(strict_types=1);

use App\Mail\BatchExpiryDigestMail;
use App\Models\Store;
use App\Models\User;
use App\Notifications\AiAlertPushNotification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;

/**
 * Semua email aplikasi memakai layout bersama (header ungu + logo putih,
 * kartu tiket, footer "tidak perlu dibalas").
 */
function expectEmailLayout(string $html): void
{
    expect($html)
        ->toContain('/email/logo-kasir-umkm-putih.png')
        ->toContain('#7943e5')
        ->toContain('tidak perlu dibalas')
        ->toContain('info@kasirumkm.web.id');
}

test('email peringatan stok memakai layout bersama dengan baris produk dan total', function () {
    $store = new Store(['name' => 'Kedai Uji', 'timezone' => 'Asia/Makassar']);
    $user = new User(['name' => 'Budi Uji', 'email' => 'budi@example.com']);

    $html = (new AiAlertPushNotification($store, [
        'type' => 'low_stock',
        'title' => 'Stok kritis',
        'message' => '',
        'data' => ['count' => 3, 'items' => [
            ['name' => 'Susu UHT', 'stock_quantity' => 0, 'min_stock_level' => 5, 'unit' => 'pcs'],
        ]],
    ]))->toMail($user)->render();

    expectEmailLayout($html);
    expect($html)
        ->toContain('Budi Uji')
        ->toContain('Susu UHT')
        ->toContain('HABIS')
        ->toContain('Perlu restock')
        ->toContain('3 produk')
        ->toContain('+ 2 produk lain')
        ->toContain('WITA');
});

test('email digest batch memakai layout bersama', function () {
    $store = new Store(['name' => 'Kedai Uji']);

    $html = (new BatchExpiryDigestMail($store, 7, [
        ['product_name' => 'Roti Tawar', 'batch_code' => 'B01', 'quantity_remaining' => 4, 'expiry_date' => '2026-11-20', 'expiry_status' => 'expired'],
    ]))->render();

    expectEmailLayout($html);
    expect($html)->toContain('Roti Tawar')->toContain('Batch B01')->toContain('Total batch');
});

test('email reset dan verifikasi memakai layout bersama', function () {
    $user = new User(['name' => 'Sari Uji', 'email' => 'sari@example.com']);
    $user->id = 99;

    $reset = (new ResetPassword('token-uji'))->toMail($user)->render();
    expectEmailLayout($reset);
    expect($reset)
        ->toContain('Sari Uji')
        ->toContain('token-uji')
        ->toContain('Atur ulang kata sandi')
        ->toContain('WIB');

    $verify = (new VerifyEmail)->toMail($user)->render();
    expectEmailLayout($verify);
    expect($verify)->toContain('Verifikasi email')->toContain('sari@example.com');
});
