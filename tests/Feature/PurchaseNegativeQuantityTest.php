<?php

use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\Store;
use App\Models\User;
use App\Support\InventoryType;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Test pertahanan kuantitas negatif/nol pada fitur pembelian & retur.
 *
 * Memverifikasi: validasi Form Request menolak kuantitas <= 0,
 * dan constraint DB CHECK mencegah penulisan langsung yang negatif.
 */
function makeNegStore(): Store
{
    return Store::query()->create([
        'name' => 'Toko Cek Minus '.uniqid(),
        'slug' => 'cek-minus-'.uniqid(),
        'location_type' => StoreLocationType::OUTLET,
        'is_active' => true,
        'is_subscription_active' => true,
        'subscription_end' => null,
    ]);
}

function makeNegUser(Store $store): User
{
    return User::factory()->create([
        'store_id' => $store->id,
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
}

function makeNegProduct(Store $store): Product
{
    return Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Barang Cek '.uniqid(),
        'price' => 0,
        'cost_price' => 0,
        'average_cost' => 0,
        'stock_quantity' => 0,
        'inventory_type' => InventoryType::RAW_MATERIAL,
        'base_unit' => 'gram',
        'unit' => 'gram',
        'track_stock' => true,
        'is_active' => true,
    ]);
}

// ---------------------------------------------------------------------------
// Validasi Form Request: kuantitas pembelian negatif/nol ditolak
// ---------------------------------------------------------------------------

it('menolak pembelian dengan kuantitas negatif', function () {
    $store = makeNegStore();
    $user = makeNegUser($store);
    $product = makeNegProduct($store);

    $response = $this->actingAs($user)
        ->withSession(['_token' => 'csrf-token'])
        ->post(route('purchases.store'), [
            '_token' => 'csrf-token',
            'purchased_at' => now()->format('Y-m-d'),
            'lines' => [[
                'product_id' => $product->id,
                'quantity' => -10,
                'input_unit' => 'gram',
                'unit_cost' => '20',
            ]],
        ]);

    $response->assertSessionHasErrors('lines.0.quantity');

    expect(Purchase::count())->toBe(0)
        ->and(PurchaseLine::count())->toBe(0);
});

it('menolak pembelian dengan kuantitas nol', function () {
    $store = makeNegStore();
    $user = makeNegUser($store);
    $product = makeNegProduct($store);

    $response = $this->actingAs($user)
        ->withSession(['_token' => 'csrf-token'])
        ->post(route('purchases.store'), [
            '_token' => 'csrf-token',
            'purchased_at' => now()->format('Y-m-d'),
            'lines' => [[
                'product_id' => $product->id,
                'quantity' => 0,
                'input_unit' => 'gram',
                'unit_cost' => '20',
            ]],
        ]);

    $response->assertSessionHasErrors('lines.0.quantity');

    expect(Purchase::count())->toBe(0)
        ->and(PurchaseLine::count())->toBe(0);
});

it('menerima pembelian dengan kuantitas positif', function () {
    $store = makeNegStore();
    $user = makeNegUser($store);
    $product = makeNegProduct($store);

    $this->actingAs($user)
        ->withSession(['_token' => 'csrf-token'])
        ->post(route('purchases.store'), [
            '_token' => 'csrf-token',
            'purchased_at' => now()->format('Y-m-d'),
            'lines' => [[
                'product_id' => $product->id,
                'quantity' => '5000.0000',
                'input_unit' => 'gram',
                'unit_cost' => '20',
            ]],
        ])
        ->assertRedirect();

    $product->refresh();
    $line = PurchaseLine::query()->sole();

    expect($line->quantity)->toBe('5000.0000')
        ->and($product->stock_quantity)->toBe('5000.0000');
});

// ---------------------------------------------------------------------------
// Constraint DB: penulisan langsung kuantitas negatif ditolak
// ---------------------------------------------------------------------------

it('constraint DB menolak insert langsung kuantitas negatif di purchase_lines', function () {
    $store = makeNegStore();
    $user = makeNegUser($store);
    $product = makeNegProduct($store);

    $purchase = Purchase::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'purchased_at' => now(),
    ]);

    // Insert langsung bypass controller — harus ditolak oleh CHECK constraint.
    expect(fn () => PurchaseLine::create([
        'purchase_id' => $purchase->id,
        'product_id' => $product->id,
        'quantity' => -5,
        'input_unit' => 'gram',
    ]))->toThrow(\Illuminate\Database\QueryException::class);

    expect(PurchaseLine::count())->toBe(0);
});

it('constraint DB menolak insert langsung kuantitas nol di purchase_lines', function () {
    $store = makeNegStore();
    $user = makeNegUser($store);
    $product = makeNegProduct($store);

    $purchase = Purchase::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'purchased_at' => now(),
    ]);

    expect(fn () => PurchaseLine::create([
        'purchase_id' => $purchase->id,
        'product_id' => $product->id,
        'quantity' => -0.0001,
        'input_unit' => 'gram',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});
