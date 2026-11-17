<?php

/*
 * Menu Saran menu (F8) dan Pengantaran (F10) disembunyikan sampai modulnya dinyalakan,
 * dan dinyalakan dari halaman Fitur Tambahan.
 */

use App\Models\DeliveryOrder;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Support\OptionalFeatures;
use App\Support\UiMode;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function hiddenMenuStore(array $settings = []): array
{
    $store = Store::query()->create([
        'name' => 'Toko Lama',
        'slug' => 'lama-'.uniqid(),
        'business_type' => 'fnb',
        'is_active' => true,
        'is_subscription_active' => true,
        'settings' => $settings,
    ]);
    $admin = User::factory()->create(['store_id' => $store->id, 'role' => 'admin', 'email_verified_at' => now()]);
    $admin->stores()->attach($store->id, ['role' => 'admin']);

    return [$store, $admin];
}

it('toko lama: kedua modul mati sehingga menunya tidak tampil', function () {
    [, $admin] = hiddenMenuStore();

    $this->actingAs($admin)->get('/dashboard')->assertOk()->assertInertia(
        fn ($page) => $page->where('upsell_enabled', false)->where('delivery_enabled', false)
    );
});

it('Fitur Tambahan menyalakan dan mematikan Saran Menu & Pesanan Antar', function (string $feature, string $setting, string $menu) {
    [$store, $admin] = hiddenMenuStore(['ui_mode' => 'simple']);
    $this->actingAs($admin);

    $this->put("/settings/features/{$feature}", ['enabled' => true])->assertSessionHas('success');
    $store->refresh();
    expect($store->settings[$setting])->toBeTrue()
        ->and(OptionalFeatures::isActive($store, $feature))->toBeTrue()
        ->and(UiMode::visibleMenus($store))->toContain($menu);

    $this->get('/dashboard')->assertInertia(fn ($page) => $page->where($setting, true));

    $this->put("/settings/features/{$feature}", ['enabled' => false])->assertSessionHas('success');
    $store->refresh();
    expect($store->settings[$setting])->toBeFalse()
        ->and(UiMode::visibleMenus($store))->not->toContain($menu);
})->with([
    'saran menu' => ['upsell', 'upsell_enabled', 'upsell'],
    'pesanan antar' => ['delivery', 'delivery_enabled', 'deliveries'],
]);

it('Pesanan Antar tidak bisa dimatikan selama masih ada pengantaran berjalan', function () {
    [$store, $admin] = hiddenMenuStore(['delivery_enabled' => true]);
    $transaction = Transaction::query()->create([
        'store_id' => $store->id,
        'user_id' => $admin->id,
        'transaction_number' => 'TRX-'.uniqid(),
        'status' => 'completed',
        'payment_method' => 'cash',
        'payment_status' => 'paid',
        'subtotal' => 10000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 18000,
        'amount_paid' => 18000,
        'change_amount' => 0,
    ]);
    DeliveryOrder::query()->create([
        'store_id' => $store->id,
        'transaction_id' => $transaction->id,
        'address' => 'Jl. Uji 1',
        'delivery_fee' => 8000,
        'status' => DeliveryOrder::STATUS_ON_THE_WAY,
    ]);
    UiMode::forgetCache($store);

    $this->actingAs($admin)->put('/settings/features/delivery', ['enabled' => false])
        ->assertSessionHas('error', '"Pesanan Antar" belum bisa dimatikan karena masih ada data yang berjalan.');

    expect($store->fresh()->settings['delivery_enabled'])->toBeTrue();
});
