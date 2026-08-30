<?php

use App\Models\CashShift;
use App\Models\CashTransaction;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Membuat grup usaha berisi Gudang Pusat + outlet dengan toggle
 * sistem gudang aktif. `$allowLocal` menentukan nilai is_allow_local_purchase.
 */
function makeLocalPurchaseOrg(string $prefix, bool $allowLocal): array
{
    $org = Organization::query()->create([
        'name' => "{$prefix} Org",
        'slug' => 'lp-'.strtolower($prefix).'-'.uniqid(),
    ]);

    $warehouse = Store::query()->create([
        'name' => "{$prefix} Gudang Pusat",
        'slug' => 'lp-wh-'.strtolower($prefix).'-'.uniqid(),
        'organization_id' => $org->id,
        'location_type' => StoreLocationType::CENTRAL_WAREHOUSE,
        'is_active' => true,
        'is_subscription_active' => true,
    ]);

    $outlet = Store::query()->create([
        'name' => "{$prefix} Outlet",
        'slug' => 'lp-ot-'.strtolower($prefix).'-'.uniqid(),
        'organization_id' => $org->id,
        'location_type' => StoreLocationType::OUTLET,
        'is_active' => true,
        'is_subscription_active' => true,
        'settings' => array_merge([
            'warehouse_enabled' => true,
        ], $allowLocal ? ['is_allow_local_purchase' => true] : []),
    ]);

    $user = User::factory()->create([
        'store_id' => $outlet->id,
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $outlet->members()->attach($user->id, ['role' => 'admin']);

    $supplier = Supplier::query()->create([
        'store_id' => $outlet->id,
        'name' => 'PT Bahan Baku '.$prefix,
    ]);

    $product = Product::query()->create([
        'store_id' => $outlet->id,
        'name' => 'Gula Pasir '.$prefix,
        'sku' => 'GULA-'.strtoupper($prefix),
        'price' => 0,
        'cost_price' => 0,
        'average_cost' => 0,
        'stock_quantity' => 0,
        'min_stock_level' => 0,
        'track_stock' => true,
        'allows_decimal' => false,
        'inventory_type' => 'standard',
        'base_unit' => 'pcs',
        'unit' => 'pcs',
        'has_variants' => false,
        'is_bundle' => false,
        'is_active' => true,
    ]);

    return [$outlet, $user, $supplier, $product];
}

/** Helper POST pembelian dengan pola CSRF seperti test lain di repo ini. */
function createLocalPurchaseRequest(User $user, int $supplierId, int $productId)
{
    return test()
        ->actingAs($user)
        ->withSession(['_token' => 'csrf-token'])
        ->post('/purchases', [
            '_token' => 'csrf-token',
            'supplier_id' => $supplierId,
            'lines' => [
                [
                    'product_id' => $productId,
                    'quantity' => 5,
                    'input_unit' => 'pcs',
                    'unit_cost' => 10000,
                ],
            ],
        ]);
}

test('outlet tanpa izin pembelian lokal ditolak mencatat pembelian supplier langsung', function () {
    [$outlet, $user, $supplier, $product] = makeLocalPurchaseOrg('NO', false);

    $response = createLocalPurchaseRequest($user, $supplier->id, $product->id);

    $response->assertSessionHasErrors('store');
    $this->assertDatabaseMissing('purchases', ['store_id' => $outlet->id]);
});

test('outlet dengan izin pembelian lokal dapat membeli sendiri dan stok bertambah', function () {
    [$outlet, $user, $supplier, $product] = makeLocalPurchaseOrg('YES', true);

    $response = createLocalPurchaseRequest($user, $supplier->id, $product->id);

    $response->assertRedirect();
    $response->assertSessionHasNoErrors();
    $this->assertDatabaseHas('purchases', [
        'store_id' => $outlet->id,
        'supplier_id' => $supplier->id,
    ]);

    $product->refresh();
    expect((float) $product->stock_quantity)->toBe(5.0);
});

test('pembelian lokal tunai melunasi membuat pengeluaran kas dari shift kasir aktif', function () {
    [$outlet, $user, $supplier, $product] = makeLocalPurchaseOrg('CASH', true);

    // Buka shift kasir untuk user terlebih dahulu (uang diambil dari kas kasir).
    CashShift::query()->create([
        'store_id' => $outlet->id,
        'user_id' => $user->id,
        'shift_number' => 'SHF-LP-'.uniqid(),
        'opening_cash' => 500000,
        'opened_at' => now(),
    ]);

    $response = createLocalPurchaseRequest($user, $supplier->id, $product->id);

    $response->assertRedirect();
    $response->assertSessionHasNoErrors();

    $purchase = \App\Models\Purchase::query()
        ->where('store_id', $outlet->id)
        ->first();

    expect($purchase)->not->toBeNull();
    expect((float) $purchase->total_amount)->toBe(50000.0);

    $this->assertDatabaseHas('cash_transactions', [
        'store_id' => $outlet->id,
        'user_id' => $user->id,
        'type' => CashTransaction::TYPE_OUT,
        'category' => CashTransaction::CATEGORY_EXPENSE,
        'amount' => 50000,
        'party_name' => 'PT Bahan Baku CASH',
        'notes' => 'Pembelian lokal '.$purchase->purchase_number,
    ]);
});
