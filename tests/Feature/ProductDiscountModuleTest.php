<?php

use App\Models\Product;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\Transaction;
use App\Models\User;
use App\Support\BaseUnit;
use App\Support\InventoryType;
use App\Support\ProductDiscountFeatures;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @return array{0: Store, 1: User, 2: User, 3: Product} */
function makeProductDiscountModuleStore(?array $settings = null): array
{
    $plan = SubscriptionPlan::query()->firstOrCreate(
        ['slug' => 'starter'],
        ['name' => 'Paket Starter', 'price' => 0, 'billing_cycle' => 'monthly', 'is_active' => true],
    );

    $store = Store::query()->create([
        'name' => 'Toko Uji Modul Diskon Produk',
        'slug' => 'product-discount-module-'.uniqid(),
        'business_type' => 'general',
        'settings' => $settings,
        'is_active' => true,
        'is_subscription_active' => true,
        'subscription_plan_id' => $plan->id,
        'subscription_end' => now()->addDay(),
    ]);

    $admin = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $cashier = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'cashier',
        'email_verified_at' => now(),
    ]);
    $store->members()->attach($cashier->id, ['role' => 'cashier']);

    $product = Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Teh Manis',
        'sku' => 'PDM-'.uniqid(),
        'price' => 10000,
        'cost_price' => 4000,
        'stock_quantity' => 20,
        'unit' => 'cup',
        'inventory_type' => InventoryType::SELLABLE,
        'base_unit' => BaseUnit::PIECE,
        'track_stock' => true,
        'is_active' => true,
    ]);

    return [$store, $admin, $cashier, $product];
}

function productDiscountModulePayload(int $productId, float $lineDiscount): array
{
    return [
        'items' => [[
            'product_id' => $productId,
            'quantity' => 2,
            'unit_price' => 10000,
            'subtotal' => 20000,
            'line_discount' => $lineDiscount,
            'product_name' => 'Teh Manis',
        ]],
        'payment_method' => 'cash',
        'amount_paid' => 50000,
    ];
}

test('modul diskon per produk nonaktif secara default', function () {
    [$store] = makeProductDiscountModuleStore();

    expect(ProductDiscountFeatures::enabled($store))->toBeFalse();
});

test('admin dapat menonaktifkan dan mengaktifkan kembali modul diskon per produk', function () {
    [$store, $admin] = makeProductDiscountModuleStore(['product_discount_enabled' => true]);

    $this->actingAs($admin)
        ->put(route('settings.store.update'), [
            'name' => $store->name,
            'business_type' => 'general',
            'product_discount_enabled' => false,
        ])
        ->assertSessionHasNoErrors();

    $store->refresh();
    expect($store->settings['product_discount_enabled'])->toBeFalse()
        ->and(ProductDiscountFeatures::enabled($store))->toBeFalse();

    $this->actingAs($admin)
        ->get(route('profile.edit'))
        ->assertInertia(fn ($page) => $page->where('store.product_discount_enabled', false));

    $this->actingAs($admin)
        ->put(route('settings.store.update'), [
            'name' => $store->name,
            'business_type' => 'general',
            'product_discount_enabled' => true,
        ])
        ->assertSessionHasNoErrors();

    expect(ProductDiscountFeatures::enabled($store->refresh()))->toBeTrue();
});

test('menyimpan pengaturan tanpa field modul membuat diskon per produk nonaktif', function () {
    [$store, $admin] = makeProductDiscountModuleStore();

    $this->actingAs($admin)
        ->put(route('settings.store.update'), [
            'name' => $store->name,
            'business_type' => 'general',
        ])
        ->assertSessionHasNoErrors();

    expect($store->refresh()->settings['product_discount_enabled'])->toBeFalse();
});

test('menyimpan pengaturan tanpa field modul tidak mematikan modul yang sudah aktif', function () {
    [$store, $admin] = makeProductDiscountModuleStore(['product_discount_enabled' => true]);

    $this->actingAs($admin)
        ->put(route('settings.store.update'), [
            'name' => $store->name,
            'business_type' => 'general',
        ])
        ->assertSessionHasNoErrors();

    expect($store->refresh()->settings['product_discount_enabled'])->toBeTrue();
});

test('diskon per baris diabaikan bila toko belum pernah mengatur modul', function () {
    [$store, , $cashier, $product] = makeProductDiscountModuleStore();

    $this->actingAs($cashier)
        ->postJson('/pos/process-payment', productDiscountModulePayload($product->id, 3000))
        ->assertOk();

    $transaction = Transaction::query()->where('store_id', $store->id)->firstOrFail();

    expect((float) $transaction->discount_amount)->toBe(0.0)
        ->and((float) $transaction->total_amount)->toBe(20000.0);
});

test('flag modul diskon per produk dikirim ke halaman POS', function () {
    [, , $cashier] = makeProductDiscountModuleStore(['product_discount_enabled' => false]);

    $this->actingAs($cashier)
        ->get(route('pos.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('POS/Index')
            ->where('store.product_discount_enabled', false)
            ->where('product_discount_enabled', false)
        );
});

test('diskon per baris diterapkan saat modul aktif', function () {
    [$store, , $cashier, $product] = makeProductDiscountModuleStore(['product_discount_enabled' => true]);

    $this->actingAs($cashier)
        ->postJson('/pos/process-payment', productDiscountModulePayload($product->id, 3000))
        ->assertOk()
        ->assertJsonPath('success', true);

    $transaction = Transaction::query()->where('store_id', $store->id)->with('items')->firstOrFail();

    expect((float) $transaction->discount_amount)->toBe(3000.0)
        ->and((float) $transaction->total_amount)->toBe(17000.0)
        ->and((float) $transaction->items->first()->discount_amount)->toBe(3000.0);
});

test('diskon per baris diabaikan server saat modul nonaktif', function () {
    [$store, , $cashier, $product] = makeProductDiscountModuleStore(['product_discount_enabled' => false]);

    $this->actingAs($cashier)
        ->postJson('/pos/process-payment', productDiscountModulePayload($product->id, 3000))
        ->assertOk()
        ->assertJsonPath('success', true);

    $transaction = Transaction::query()->where('store_id', $store->id)->with('items')->firstOrFail();
    $item = $transaction->items->first();

    expect((float) $transaction->subtotal)->toBe(20000.0)
        ->and((float) $transaction->discount_amount)->toBe(0.0)
        ->and((float) $transaction->total_amount)->toBe(20000.0)
        ->and((float) $transaction->change_amount)->toBe(30000.0)
        ->and((float) $item->discount_amount)->toBe(0.0)
        ->and((float) $item->gross_profit_snapshot)->toBe(12000.0);
});
