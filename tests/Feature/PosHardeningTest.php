<?php

use App\Models\Product;
use App\Models\ProductSaleConsumption;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Support\BaseUnit;
use App\Support\InventoryType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Outlet aktif dengan kasir yang sudah login. */
function makePosStore(): array
{
    // Paket starter: modul shift kasir tidak aktif sehingga POS tidak butuh shift terbuka.
    $plan = \App\Models\SubscriptionPlan::query()->firstOrCreate(
        ['slug' => 'starter'],
        ['name' => 'Paket Starter', 'price' => 0, 'billing_cycle' => 'monthly', 'is_active' => true],
    );

    $store = Store::query()->create([
        'name' => 'Toko Uji POS',
        'slug' => 'pos-hardening-'.uniqid(),
        'business_type' => 'general',
        'is_active' => true,
        'is_subscription_active' => true,
        'subscription_plan_id' => $plan->id,
        'subscription_end' => now()->addDay(),
    ]);

    $cashier = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'cashier',
        'email_verified_at' => now(),
    ]);
    $store->members()->attach($cashier->id, ['role' => 'cashier']);

    return [$store, $cashier];
}

function makePosProduct(Store $store, array $attributes = []): Product
{
    return Product::query()->create(array_merge([
        'store_id' => $store->id,
        'name' => 'Es Teh',
        'sku' => 'POS-'.uniqid(),
        'price' => 5000,
        'cost_price' => 2000,
        'stock_quantity' => 20,
        'unit' => 'cup',
        'inventory_type' => InventoryType::SELLABLE,
        'base_unit' => BaseUnit::PIECE,
        'track_stock' => true,
        'is_active' => true,
    ], $attributes));
}

// ═══════════════════════════════════════════════════════════════
// Idempotensi transaksi online
// ═══════════════════════════════════════════════════════════════

test('kirim ulang pembayaran dengan kunci idempotensi yang sama tidak menggandakan transaksi', function () {
    [$store, $cashier] = makePosStore();
    $product = makePosProduct($store);

    $payload = [
        'items' => [[
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => 5000,
            'subtotal' => 10000,
            'product_name' => $product->name,
        ]],
        'payment_method' => 'cash',
        'amount_paid' => 10000,
        'total_amount' => 10000,
    ];

    $key = 'uji-idempotensi-'.uniqid();

    $first = $this->actingAs($cashier)
        ->withHeaders(['X-Pos-Idempotency-Key' => $key])
        ->postJson('/pos/process-payment', $payload);

    $first->assertOk()->assertJsonPath('success', true);

    // Kiriman kedua meniru kasir yang menekan "Selesaikan" lagi setelah jaringan putus.
    $second = $this->actingAs($cashier)
        ->withHeaders(['X-Pos-Idempotency-Key' => $key])
        ->postJson('/pos/process-payment', $payload);

    $second->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('duplicate', true);

    expect(Transaction::query()->where('store_id', $store->id)->count())->toBe(1)
        ->and((float) $product->fresh()->stock_quantity)->toBe(18.0);
});

test('kunci idempotensi berbeda tetap menghasilkan transaksi terpisah', function () {
    [$store, $cashier] = makePosStore();
    $product = makePosProduct($store);

    $payload = [
        'items' => [[
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 5000,
            'subtotal' => 5000,
            'product_name' => $product->name,
        ]],
        'payment_method' => 'cash',
        'amount_paid' => 5000,
        'total_amount' => 5000,
    ];

    foreach (['kunci-a', 'kunci-b'] as $key) {
        $this->actingAs($cashier)
            ->withHeaders(['X-Pos-Idempotency-Key' => $key])
            ->postJson('/pos/process-payment', $payload)
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    expect(Transaction::query()->where('store_id', $store->id)->count())->toBe(2)
        ->and((float) $product->fresh()->stock_quantity)->toBe(18.0);
});

test('pembayaran tanpa kunci idempotensi tetap diproses seperti biasa', function () {
    [$store, $cashier] = makePosStore();
    $product = makePosProduct($store);

    $this->actingAs($cashier)
        ->postJson('/pos/process-payment', [
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => 5000,
                'subtotal' => 5000,
                'product_name' => $product->name,
            ]],
            'payment_method' => 'cash',
            'amount_paid' => 5000,
            'total_amount' => 5000,
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $transaction = Transaction::query()->where('store_id', $store->id)->firstOrFail();

    expect($transaction->offline_sale_id)->toBeNull();
});

// ═══════════════════════════════════════════════════════════════
// Payload halaman POS
// ═══════════════════════════════════════════════════════════════

test('halaman POS mengirim komponen menu sekali, bukan disarangkan di tiap produk', function () {
    [$store, $cashier] = makePosStore();

    $gula = makePosProduct($store, [
        'name' => 'Gula',
        'sku' => 'BHN-GULA',
        'inventory_type' => InventoryType::RAW_MATERIAL,
        'base_unit' => BaseUnit::GRAM,
        'stock_quantity' => 5000,
    ]);

    // Dua menu memakai bahan yang sama — inilah duplikasi yang dulu membengkakkan payload.
    foreach (['Kopi Susu', 'Teh Manis'] as $index => $name) {
        $menu = makePosProduct($store, ['name' => $name, 'sku' => 'MNU-'.$index]);
        ProductSaleConsumption::query()->create([
            'store_id' => $store->id,
            'product_id' => $menu->id,
            'component_product_id' => $gula->id,
            'quantity' => 15,
            'base_unit' => BaseUnit::GRAM,
        ]);
    }

    $response = $this->actingAs($cashier)->get('/pos');
    $response->assertOk();

    $props = $response->viewData('page')['props'];

    expect($props)->toHaveKey('saleComponents');

    $components = collect($props['saleComponents']);
    expect($components)->toHaveCount(1)
        ->and($components->first()['id'])->toBe($gula->id)
        ->and($components->first()['name'])->toBe('Gula');

    // Baris konsumsi hanya menyimpan id komponen, tanpa objek komponen tersarang.
    $menus = collect($props['products'])->filter(fn ($p) => ! empty($p['sale_consumptions']));
    expect($menus)->toHaveCount(2);

    foreach ($menus as $menu) {
        foreach ($menu['sale_consumptions'] as $consumption) {
            expect($consumption)->not->toHaveKey('component')
                ->and($consumption['component_product_id'])->toBe($gula->id);
        }
    }
});
