<?php

/*
 * Alur transfer stok: owner boleh mengirim/menerima, peran dicek di toko asal/tujuan
 * (bukan toko aktif), "Kirim sekarang", dan penanda kiriman yang menunggu diterima.
 */

use App\Models\Organization;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use App\Models\Store;
use App\Models\User;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function ownerFlowStore(Organization $org, string $name): Store
{
    return Store::query()->create([
        'name' => $name,
        'slug' => 'alur-'.uniqid(),
        'organization_id' => $org->id,
        'location_type' => StoreLocationType::OUTLET,
        'is_active' => true,
        'is_subscription_active' => true,
    ]);
}

function ownerFlowProduct(Store $store, float $stock): Product
{
    return Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Sirup',
        'sku' => 'SRP-1',
        'price' => 0,
        'cost_price' => 1000,
        'average_cost' => 1000,
        'stock_quantity' => $stock,
        'base_unit' => 'pcs',
        'unit' => 'pcs',
        'track_stock' => true,
        'is_active' => true,
    ]);
}

/** Dua outlet satu grup; transfer Dikirim 5 pcs dari A ke B. */
function ownerFlowSetup(): array
{
    $org = Organization::query()->create(['name' => 'Grup Alur', 'slug' => 'grup-'.uniqid()]);
    $a = ownerFlowStore($org, 'Outlet A');
    $b = ownerFlowStore($org, 'Outlet B');
    $source = ownerFlowProduct($a, 50);
    $target = ownerFlowProduct($b, 0);

    $transfer = StockTransfer::query()->create([
        'from_store_id' => $a->id,
        'to_store_id' => $b->id,
        'user_id' => null,
        'status' => StockTransfer::STATUS_SENT,
        'sent_at' => now(),
    ]);
    StockTransferLine::query()->create([
        'stock_transfer_id' => $transfer->id,
        'from_product_id' => $source->id,
        'to_product_id' => $target->id,
        'quantity' => 5,
        'base_unit' => 'pcs',
        'unit_cost_snapshot' => 1000,
        'total_cost_snapshot' => 5000,
    ]);

    return compact('a', 'b', 'source', 'target', 'transfer');
}

function ownerFlowUser(Store $active, array $roles): User
{
    $user = User::factory()->create(['store_id' => $active->id, 'role' => 'cashier', 'email_verified_at' => now()]);
    foreach ($roles as $storeId => $role) {
        $user->stores()->attach($storeId, ['role' => $role]);
    }

    return $user;
}

test('owner outlet tujuan bisa menerima transfer', function () {
    ['b' => $b, 'target' => $target, 'transfer' => $transfer] = ownerFlowSetup();
    $owner = ownerFlowUser($b, [$b->id => 'owner']);

    $this->actingAs($owner)->get("/stock-transfers/{$transfer->id}")
        ->assertInertia(fn ($page) => $page->where('canReceive', true));

    $this->actingAs($owner)->post("/stock-transfers/{$transfer->id}/receive", receiptSignature())->assertSessionHasNoErrors();

    expect($transfer->fresh()->status)->toBe(StockTransfer::STATUS_RECEIVED)
        ->and((float) $target->fresh()->stock_quantity)->toBe(5.0);
});

test('peran dicek di toko tujuan, bukan toko yang sedang aktif', function () {
    ['a' => $a, 'b' => $b, 'transfer' => $transfer] = ownerFlowSetup();
    // Admin di outlet A (aktif), tetapi hanya kasir di outlet B (tujuan).
    $user = ownerFlowUser($a, [$a->id => 'admin', $b->id => 'cashier']);

    $this->actingAs($user)->get("/stock-transfers/{$transfer->id}")
        ->assertInertia(fn ($page) => $page->where('canReceive', false));
    $this->actingAs($user)->post("/stock-transfers/{$transfer->id}/receive", receiptSignature())->assertForbidden();
});

test('kirim sekarang langsung mengirim transfer baru', function () {
    ['a' => $a, 'b' => $b, 'source' => $source] = ownerFlowSetup();
    $owner = ownerFlowUser($a, [$a->id => 'owner', $b->id => 'owner']);

    $this->actingAs($owner)->post('/stock-transfers', [
        'to_store_id' => $b->id,
        'send_now' => true,
        'lines' => [['from_product_id' => $source->id, 'quantity' => 7]],
    ])->assertSessionHasNoErrors();

    $created = StockTransfer::query()->where('from_store_id', $a->id)->latest('id')->first();
    expect($created->status)->toBe(StockTransfer::STATUS_SENT)
        ->and((float) $source->fresh()->stock_quantity)->toBe(43.0);
});

test('daftar menandai kiriman yang menunggu diterima', function () {
    ['b' => $b, 'transfer' => $transfer] = ownerFlowSetup();
    $admin = ownerFlowUser($b, [$b->id => 'admin']);

    $this->actingAs($admin)->get('/stock-transfers')
        ->assertInertia(fn ($page) => $page
            ->where('incomingCount', 1)
            ->where('incoming_transfer_count', 1)
            ->where('transfers.data.0.id', $transfer->id));

    $this->actingAs($admin)->get('/stock-transfers?direction=out')
        ->assertInertia(fn ($page) => $page->has('transfers.data', 0));
});
