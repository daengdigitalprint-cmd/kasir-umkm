<?php

use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Support\ReceiptSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function receiptWatermarkStore(string $planSlug, int $price, bool $active = true): Store
{
    $plan = SubscriptionPlan::query()->firstOrCreate(
        ['slug' => $planSlug],
        ['name' => 'Paket '.$planSlug, 'price' => $price, 'billing_cycle' => 'monthly', 'is_active' => true],
    );

    return Store::query()->create([
        'name' => 'Toko Struk '.$planSlug,
        'slug' => 'toko-struk-'.uniqid(),
        'is_active' => true,
        'is_subscription_active' => $active,
        'subscription_plan_id' => $plan->id,
        'subscription_end' => $active ? now()->addMonth() : now()->subDay(),
    ]);
}

test('struk toko paket Gratis membawa tanda Kasir UMKM dan alamat situs', function () {
    $payload = ReceiptSettings::payload(receiptWatermarkStore('starter', 0));

    expect($payload['watermark'])->toBe(['Kasir UMKM by MaccaPOS', 'kasirumkm.web.id']);
});

test('struk toko paket Gratis tahunan juga membawa tanda', function () {
    $payload = ReceiptSettings::payload(receiptWatermarkStore('starter-tahunan', 0));

    expect($payload['watermark'])->toHaveCount(2);
});

test('struk toko paket berbayar tidak membawa tanda', function () {
    $payload = ReceiptSettings::payload(receiptWatermarkStore('bisnis', 99000));

    expect($payload['watermark'])->toBe([]);
});

test('kaki struk buatan toko tetap ada bersama tanda', function () {
    $store = receiptWatermarkStore('starter', 0);
    $store->update(['settings' => ['receipt_footer' => 'Sampai jumpa lagi', 'receipt_footer_line2' => 'IG @tokostruk']]);

    $payload = ReceiptSettings::payload($store->fresh());

    expect($payload['footer'])->toBe('Sampai jumpa lagi')
        ->and($payload['footer_line2'])->toBe('IG @tokostruk')
        ->and($payload['watermark'])->toHaveCount(2);
});
