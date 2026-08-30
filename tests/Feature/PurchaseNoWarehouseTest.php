<?php

use App\Models\Organization;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use App\Support\InventoryType;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Pembelian mandiri untuk toko tanpa sistem gudang.
 *
 * Aturan baru: kewajiban "pembelian hanya di Gudang Pusat" berlaku hanya untuk
 * outlet dalam grup usaha yang memiliki Gudang Pusat. Toko tanpa sistem gudang
 * (mandiri/standalone atau grup usaha tanpa gudang) boleh membeli langsung.
 */
function makeNoWhOrg(string $name): Organization
{
    return Organization::query()->create([
        'name' => $name,
        'slug' => str()->slug($name).'-'.uniqid(),
        'owner_user_id' => User::factory()->create()->id,
    ]);
}

function makeNoWhStore(string $locationType = StoreLocationType::OUTLET, ?int $organizationId = null): Store
{
    return Store::query()->create([
        'organization_id' => $organizationId,
        'name' => ($locationType === StoreLocationType::CENTRAL_WAREHOUSE ? 'Gudang ' : 'Toko ').uniqid(),
        'slug' => 'lokasi-'.uniqid(),
        'location_type' => $locationType,
        'is_active' => true,
        'is_subscription_active' => true,
        'subscription_end' => null,
    ]);
}

function makeNoWhUser(Store $store): User
{
    return User::factory()->create([
        'store_id' => $store->id,
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
}

function makeNoWhProduct(Store $store): Product
{
    return Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Bahan Mandiri',
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

/**
 * Inti logika PurchaseController::store yang scoped ke store aktif:
 * membuat Purchase + PurchaseLine, menambah stok, mencatat StockMovement.
 */
function runNoWhPurchase(Store $store, User $user, Product $product, string $qtyBase = '5000.0000'): Purchase
{
    return DB::transaction(function () use ($store, $user, $product, $qtyBase) {
        $purchase = Purchase::create([
            'store_id' => $store->id,
            'supplier_id' => null,
            'user_id' => $user->id,
            'purchased_at' => now(),
        ]);

        PurchaseLine::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name_snapshot' => $product->name,
            'sku_snapshot' => $product->sku,
            'quantity' => $qtyBase,
            'input_unit' => 'gram',
            'quantity_input' => $qtyBase,
            'base_unit' => 'gram',
            'qty_base' => $qtyBase,
            'unit_cost_input' => '20.00',
            'unit_cost_base' => '20.000000',
            'subtotal' => '100000.00',
        ]);

        $product->increment('stock_quantity', $qtyBase);
        $product->refresh();

        StockMovement::create([
            'store_id' => $store->id,
            'product_id' => $product->id,
            'user_id' => $user->id,
            'quantity_change' => $qtyBase,
            'type' => StockMovement::TYPE_PURCHASE,
            'reference_type' => Purchase::class,
            'reference_id' => $purchase->id,
            'balance_after' => (string) $product->stock_quantity,
            'notes' => $purchase->purchase_number,
        ]);

        $purchase->update([
            'dpp_amount' => '100000.00',
            'ppn_amount' => '0.00',
            'total_amount' => '100000.00',
        ]);

        return $purchase;
    });
}

// ---------------------------------------------------------------------------
// Aturan guard: kewajiban Gudang Pusat hanya untuk toko ber-sistem gudang
// ---------------------------------------------------------------------------

test('toko mandiri tanpa organisasi tidak diwajibkan lewat gudang pusat', function () {
    $store = makeNoWhStore();

    expect(StoreLocationType::usesWarehouseSystem($store))->toBeFalse()
        ->and(StoreLocationType::mustRoutePurchaseThroughCentralWarehouse($store))->toBeFalse();
});

test('outlet di grup usaha tanpa gudang pusat tidak diwajibkan lewat gudang pusat', function () {
    $org = makeNoWhOrg('Grup Tanpa Gudang');
    $outlet = makeNoWhStore(StoreLocationType::OUTLET, $org->id);

    expect(StoreLocationType::usesWarehouseSystem($outlet))->toBeFalse()
        ->and(StoreLocationType::mustRoutePurchaseThroughCentralWarehouse($outlet))->toBeFalse();
});

test('outlet di grup usaha yang memiliki gudang pusat tetap diwajibkan lewat gudang pusat jika toggle sistem gudang aktif', function () {
    $org = makeNoWhOrg('Grup Ber-Gudang');
    makeNoWhStore(StoreLocationType::CENTRAL_WAREHOUSE, $org->id);
    $outlet = makeNoWhStore(StoreLocationType::OUTLET, $org->id);
    $outlet->update(['settings' => array_merge($outlet->settings ?? [], ['warehouse_enabled' => true])]);

    expect(StoreLocationType::usesWarehouseSystem($outlet))->toBeTrue()
        ->and(StoreLocationType::mustRoutePurchaseThroughCentralWarehouse($outlet))->toBeTrue();
});

test('outlet di grup usaha ber-gudang tapi toggle mati tetap bebas membeli', function () {
    $org = makeNoWhOrg('Grup Ber-Gudang');
    makeNoWhStore(StoreLocationType::CENTRAL_WAREHOUSE, $org->id);
    $outlet = makeNoWhStore(StoreLocationType::OUTLET, $org->id);

    expect(StoreLocationType::usesWarehouseSystem($outlet))->toBeFalse()
        ->and(StoreLocationType::mustRoutePurchaseThroughCentralWarehouse($outlet))->toBeFalse();
});

test('gudang pusat sendiri tidak diwajibkan lewat gudang pusat (memang gudangnya)', function () {
    $org = makeNoWhOrg('Grup Ber-Gudang');
    $warehouse = makeNoWhStore(StoreLocationType::CENTRAL_WAREHOUSE, $org->id);

    expect(StoreLocationType::usesWarehouseSystem($warehouse))->toBeTrue()
        ->and(StoreLocationType::mustRoutePurchaseThroughCentralWarehouse($warehouse))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Akses HTTP halaman pembelian per skenario
// ---------------------------------------------------------------------------

test('toko mandiri tanpa sistem gudang dapat membuka menu pembelian', function () {
    $store = makeNoWhStore();
    $user = makeNoWhUser($store);

    $this->actingAs($user)->get(route('purchases.index'))->assertStatus(200);
    $this->actingAs($user)->get(route('purchases.create'))->assertStatus(200);
});

test('outlet di grup usaha tanpa gudang pusat dapat membuka menu pembelian', function () {
    $org = makeNoWhOrg('Grup Tanpa Gudang');
    $outlet = makeNoWhStore(StoreLocationType::OUTLET, $org->id);
    $user = makeNoWhUser($outlet);

    $this->actingAs($user)->get(route('purchases.index'))->assertStatus(200);
    $this->actingAs($user)->get(route('purchases.create'))->assertStatus(200);
});

test('gudang pusat tetap dapat membuka menu pembelian', function () {
    $org = makeNoWhOrg('Grup Ber-Gudang');
    $warehouse = makeNoWhStore(StoreLocationType::CENTRAL_WAREHOUSE, $org->id);
    $user = makeNoWhUser($warehouse);

    $this->actingAs($user)->get(route('purchases.index'))->assertStatus(200);
});

test('outlet di grup usaha ber-gudang dengan toggle aktif masih dialihkan ke dashboard', function () {
    $org = makeNoWhOrg('Grup Ber-Gudang');
    makeNoWhStore(StoreLocationType::CENTRAL_WAREHOUSE, $org->id);
    $outlet = makeNoWhStore(StoreLocationType::OUTLET, $org->id);
    $outlet->update(['settings' => array_merge($outlet->settings ?? [], ['warehouse_enabled' => true])]);
    $user = makeNoWhUser($outlet);

    $this->actingAs($user)->get(route('purchases.index'))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('warning');
    $this->actingAs($user)->get(route('purchases.create'))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('warning');
});

test('outlet di grup usaha ber-gudang tapi toggle mati dapat membuka menu pembelian', function () {
    $org = makeNoWhOrg('Grup Ber-Gudang');
    makeNoWhStore(StoreLocationType::CENTRAL_WAREHOUSE, $org->id);
    $outlet = makeNoWhStore(StoreLocationType::OUTLET, $org->id);
    $user = makeNoWhUser($outlet);

    $this->actingAs($user)->get(route('purchases.index'))->assertStatus(200);
    $this->actingAs($user)->get(route('purchases.create'))->assertStatus(200);
});

// ---------------------------------------------------------------------------
// Pengaturan: toggle sistem gudang disimpan & divalidasi
// ---------------------------------------------------------------------------

test('pengaturan dapat mengaktifkan sistem gudang untuk outlet di grup ber-gudang', function () {
    $org = makeNoWhOrg('Grup Ber-Gudang');
    makeNoWhStore(StoreLocationType::CENTRAL_WAREHOUSE, $org->id);
    $outlet = makeNoWhStore(StoreLocationType::OUTLET, $org->id);
    $user = makeNoWhUser($outlet);

    $this->actingAs($user)
        ->withSession(['_token' => 'csrf-token'])
        ->put(route('settings.store.update'), [
            '_token' => 'csrf-token',
            'name' => $outlet->name,
            'business_type' => 'general',
            'warehouse_enabled' => true,
        ])
        ->assertRedirect();

    $outlet->refresh();
    expect($outlet->settings['warehouse_enabled'])->toBeTrue();
});

test('pengaturan menolak aktivasi sistem gudang tanpa gudang pusat di grup', function () {
    $store = makeNoWhStore();
    $user = makeNoWhUser($store);

    $this->actingAs($user)
        ->withSession(['_token' => 'csrf-token'])
        ->from(route('profile.edit'))
        ->put(route('settings.store.update'), [
            '_token' => 'csrf-token',
            'name' => $store->name,
            'business_type' => 'general',
            'warehouse_enabled' => true,
        ])
        ->assertSessionHasErrors('warehouse_enabled');

    $store->refresh();
    expect($store->settings['warehouse_enabled'] ?? false)->toBeFalse();
});

test('pengaturan dapat menonaktifkan kembali sistem gudang', function () {
    $org = makeNoWhOrg('Grup Ber-Gudang');
    makeNoWhStore(StoreLocationType::CENTRAL_WAREHOUSE, $org->id);
    $outlet = makeNoWhStore(StoreLocationType::OUTLET, $org->id);
    $outlet->update(['settings' => array_merge($outlet->settings ?? [], ['warehouse_enabled' => true])]);
    $user = makeNoWhUser($outlet);

    $this->actingAs($user)
        ->withSession(['_token' => 'csrf-token'])
        ->put(route('settings.store.update'), [
            '_token' => 'csrf-token',
            'name' => $outlet->name,
            'business_type' => 'general',
            'warehouse_enabled' => false,
        ])
        ->assertRedirect();

    $outlet->refresh();
    expect($outlet->settings['warehouse_enabled'])->toBeFalse();
});

// ---------------------------------------------------------------------------
// Bisnis: toko tanpa sistem gudang menjalankan pembelian (stok & mutasi)
// ---------------------------------------------------------------------------

test('toko tanpa sistem gudang dapat mencatat pembelian dan stok bertambah', function () {
    $store = makeNoWhStore();
    $user = makeNoWhUser($store);
    $product = makeNoWhProduct($store);

    $purchase = runNoWhPurchase($store, $user, $product);

    $product->refresh();
    $line = PurchaseLine::query()->sole();
    $movement = StockMovement::query()->where('type', StockMovement::TYPE_PURCHASE)->sole();

    expect($purchase->store_id)->toBe($store->id)
        ->and($line->product_id)->toBe($product->id)
        ->and($product->stock_quantity)->toBe('5000.0000')
        ->and($movement->store_id)->toBe($store->id)
        ->and($movement->quantity_change)->toBe('5000.0000')
        ->and($movement->balance_after)->toBe('5000.0000');
});

// ---------------------------------------------------------------------------
// Pencarian produk di form pembelian: semua tipe produk yang dilacak muncul
// ---------------------------------------------------------------------------

test('form pembelian menampilkan produk sellable dan hasil produksi yang melacak stok', function () {
    $store = makeNoWhStore();
    $user = makeNoWhUser($store);

    $sellable = makeNoWhProduct($store);
    $sellable->update(['inventory_type' => 'sellable']);
    $production = makeNoWhProduct($store);
    $production->update(['inventory_type' => 'production_output']);
    $untracked = makeNoWhProduct($store);
    $untracked->update(['track_stock' => false, 'inventory_type' => 'sellable']);

    $response = $this->actingAs($user)->get(route('purchases.create'));
    $response->assertOk();

    $page = $response->viewData('page');
    $productIds = collect($page['props']['products'])->pluck('id')->all();

    expect($productIds)->toContain($sellable->id)
        ->toContain($production->id)
        ->not->toContain($untracked->id);
});

test('controller menyimpan pembelian produk sellable lewat form', function () {
    $store = makeNoWhStore();
    $user = makeNoWhUser($store);
    $product = makeNoWhProduct($store);
    $product->update(['inventory_type' => 'sellable']);

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

    expect($line->product_id)->toBe($product->id)
        ->and($product->stock_quantity)->toBe('5000.0000');
});
