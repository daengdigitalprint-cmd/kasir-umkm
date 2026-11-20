<?php

/*
 * Pembelian bayar tempo: tercatat sebagai utang ke supplier, tampil di filter
 * "Belum lunas" dan laporan utang usaha.
 */

use App\Models\Product;
use App\Models\Purchase;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function creditPurchaseSetup(): array
{
    $store = Store::query()->create([
        'name' => 'Toko Tempo',
        'slug' => 'tempo-'.uniqid(),
        'is_active' => true,
        'is_subscription_active' => true,
    ]);
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin', 'email_verified_at' => now()]);
    $product = Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Gula',
        'price' => 0,
        'cost_price' => 0,
        'stock_quantity' => 0,
        'min_stock_level' => 0,
        'unit' => 'pcs',
        'base_unit' => 'pcs',
        'track_stock' => true,
        'is_active' => true,
    ]);

    return [$store, $user, $product];
}

function creditPayload(Product $product, array $payment): array
{
    return [
        'purchased_at' => now()->toDateString(),
        'lines' => [[
            'product_id' => $product->id,
            'quantity' => 10,
            'input_unit' => 'pcs',
            'unit_cost' => 10000,
        ]],
        ...$payment,
    ];
}

it('menyimpan pembelian bayar tempo dengan uang muka sebagai utang', function () {
    [$store, $user, $product] = creditPurchaseSetup();

    $this->actingAs($user)
        ->post('/purchases', creditPayload($product, [
            'is_paid' => false,
            'payment_method' => 'transfer',
            'amount_paid' => 30000,
            'due_date' => now()->addDays(14)->toDateString(),
        ]))
        ->assertSessionHasNoErrors();

    $purchase = Purchase::query()->where('store_id', $store->id)->latest('id')->firstOrFail();
    expect($purchase->is_paid)->toBeFalse()
        ->and((float) $purchase->total_amount)->toBe(100000.0)
        ->and((float) $purchase->amount_paid)->toBe(30000.0)
        ->and($purchase->due_date->toDateString())->toBe(now()->addDays(14)->toDateString());

    $this->get('/purchases?status=unpaid')->assertInertia(fn ($page) => $page
        ->where('status', 'unpaid')
        ->has('purchases', 1)
        ->where('unpaidSummary.count', 1)
        ->where('unpaidSummary.total', 70000));
});

it('pembelian lunas tetap tercatat lunas seperti sebelumnya', function () {
    [$store, $user, $product] = creditPurchaseSetup();

    $this->actingAs($user)
        ->post('/purchases', creditPayload($product, ['is_paid' => true, 'payment_method' => 'cash']))
        ->assertSessionHasNoErrors();

    $purchase = Purchase::query()->where('store_id', $store->id)->latest('id')->firstOrFail();
    expect($purchase->is_paid)->toBeTrue()
        ->and((float) $purchase->amount_paid)->toBe(100000.0);

    $this->get('/purchases?status=unpaid')->assertInertia(fn ($page) => $page->has('purchases', 0));
});
