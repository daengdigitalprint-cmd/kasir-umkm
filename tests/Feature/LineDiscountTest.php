<?php

use App\Models\Product;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\Transaction;
use App\Models\User;
use App\Support\BaseUnit;
use App\Support\InventoryType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @return array{0: Store, 1: User, 2: Product} */
function makeLineDiscountStore(): array
{
    $plan = SubscriptionPlan::query()->firstOrCreate(
        ['slug' => 'starter'],
        ['name' => 'Paket Starter', 'price' => 0, 'billing_cycle' => 'monthly', 'is_active' => true],
    );

    $store = Store::query()->create([
        'name' => 'Toko Uji Diskon Baris',
        'slug' => 'line-discount-'.uniqid(),
        'business_type' => 'general',
        'settings' => ['product_discount_enabled' => true],
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

    $product = Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Kopi Susu',
        'sku' => 'LD-'.uniqid(),
        'price' => 10000,
        'cost_price' => 4000,
        'stock_quantity' => 20,
        'unit' => 'cup',
        'inventory_type' => InventoryType::SELLABLE,
        'base_unit' => BaseUnit::PIECE,
        'track_stock' => true,
        'is_active' => true,
    ]);

    return [$store, $cashier, $product];
}

function lineDiscountPayload(int $productId, mixed $lineDiscount, float $amountPaid = 50000): array
{
    return [
        'items' => [[
            'product_id' => $productId,
            'quantity' => 2,
            'unit_price' => 10000,
            'subtotal' => 20000,
            'line_discount' => $lineDiscount,
            'product_name' => 'Kopi Susu',
        ]],
        'payment_method' => 'cash',
        'amount_paid' => $amountPaid,
    ];
}

test('diskon per baris mengurangi total dan tercatat di item transaksi', function () {
    [$store, $cashier, $product] = makeLineDiscountStore();

    $this->actingAs($cashier)
        ->postJson('/pos/process-payment', lineDiscountPayload($product->id, 3000))
        ->assertOk()
        ->assertJsonPath('success', true);

    $transaction = Transaction::query()->where('store_id', $store->id)->with('items')->firstOrFail();
    $item = $transaction->items->first();

    expect((float) $transaction->subtotal)->toBe(20000.0)
        ->and((float) $transaction->discount_amount)->toBe(3000.0)
        ->and((float) $transaction->total_amount)->toBe(17000.0)
        ->and((float) $transaction->change_amount)->toBe(33000.0)
        ->and((float) $item->total_price)->toBe(20000.0)
        ->and((float) $item->discount_amount)->toBe(3000.0)
        ->and((float) $item->gross_profit_snapshot)->toBe(9000.0);
});

test('diskon per baris dibatasi maksimal sebesar nilai baris', function () {
    [$store, $cashier, $product] = makeLineDiscountStore();

    $this->actingAs($cashier)
        ->postJson('/pos/process-payment', lineDiscountPayload($product->id, 99999, 0))
        ->assertOk();

    $transaction = Transaction::query()->where('store_id', $store->id)->with('items')->firstOrFail();

    expect((float) $transaction->discount_amount)->toBe(20000.0)
        ->and((float) $transaction->total_amount)->toBe(0.0)
        ->and((float) $transaction->items->first()->discount_amount)->toBe(20000.0);
});

test('diskon per baris negatif ditolak validasi', function () {
    [, $cashier, $product] = makeLineDiscountStore();

    $this->actingAs($cashier)
        ->postJson('/pos/process-payment', lineDiscountPayload($product->id, -500))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('items.0.line_discount');
});

test('transaksi tanpa diskon per baris tetap seperti sebelumnya', function () {
    [$store, $cashier, $product] = makeLineDiscountStore();

    $this->actingAs($cashier)
        ->postJson('/pos/process-payment', lineDiscountPayload($product->id, null))
        ->assertOk();

    $transaction = Transaction::query()->where('store_id', $store->id)->firstOrFail();

    expect((float) $transaction->discount_amount)->toBe(0.0)
        ->and((float) $transaction->total_amount)->toBe(20000.0);
});
