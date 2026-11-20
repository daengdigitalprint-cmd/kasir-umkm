<?php

use App\Models\Organization;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Models\User;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Dashboard Gudang Pusat memakai template gudang (stok masuk, transfer, mutasi)
 * dan karena itu butuh location_type + ringkasan gudang dari controller.
 */
function makeWarehouseDashboardOrg(): array
{
    $org = Organization::query()->create(['name' => 'Grup Dasbor', 'slug' => 'grup-dasbor-'.uniqid()]);

    $ck = Store::query()->create([
        'name' => 'Gudang Pusat Uji',
        'slug' => 'ck-dasbor-'.uniqid(),
        'organization_id' => $org->id,
        'location_type' => StoreLocationType::CENTRAL_WAREHOUSE,
        'is_active' => true,
        'is_subscription_active' => true,
    ]);

    $outlet = Store::query()->create([
        'name' => 'Outlet Uji',
        'slug' => 'outlet-dasbor-'.uniqid(),
        'organization_id' => $org->id,
        'location_type' => StoreLocationType::OUTLET,
        'is_active' => true,
        'is_subscription_active' => true,
    ]);

    $user = User::factory()->create([
        'store_id' => $ck->id,
        'role' => 'warehouse',
        'email_verified_at' => now(),
    ]);
    $user->stores()->attach($ck->id, ['role' => 'warehouse']);

    return [$ck, $outlet, $user];
}

test('dashboard gudang pusat mengirim location_type dan ringkasan gudang', function () {
    [$ck, $outlet, $user] = makeWarehouseDashboardOrg();

    $beras = Product::query()->create(['store_id' => $ck->id, 'name' => 'Beras', 'sku' => 'BRS', 'price' => 0, 'stock_quantity' => 0]);
    $gula = Product::query()->create(['store_id' => $ck->id, 'name' => 'Gula', 'sku' => 'GLA', 'price' => 0, 'stock_quantity' => 0]);

    // Dua mutasi masuk untuk Beras + satu untuk Gula = 2 jenis barang; mutasi keluar tidak dihitung.
    foreach ([[$beras, 'purchase', 5000], [$beras, 'production_output', 200], [$gula, 'transfer_in', 3], [$gula, 'transfer_out', -1]] as [$p, $type, $qty]) {
        StockMovement::query()->create([
            'store_id' => $ck->id,
            'product_id' => $p->id,
            'user_id' => $user->id,
            'type' => $type,
            'quantity_change' => $qty,
        ]);
    }

    StockTransfer::query()->create([
        'transfer_number' => 'TRF-OUT-1', 'from_store_id' => $ck->id, 'to_store_id' => $outlet->id,
        'user_id' => $user->id, 'status' => StockTransfer::STATUS_SENT, 'sent_at' => now(),
    ]);
    StockTransfer::query()->create([
        'transfer_number' => 'TRF-IN-1', 'from_store_id' => $outlet->id, 'to_store_id' => $ck->id,
        'user_id' => $user->id, 'status' => StockTransfer::STATUS_SENT, 'sent_at' => now(),
    ]);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('store.location_type', StoreLocationType::CENTRAL_WAREHOUSE)
            ->where('store.today_stock_in', 2)
            ->where('store.today_transfers_out', 1)
            ->where('store.pending_incoming_transfers', 1)
            ->has('recentMovements', 4)
            ->where('recentMovements.0.type_label', 'Transfer keluar')
            ->where('recentMovements.0.product.name', 'Gula')
        );
});

test('dashboard outlet tetap bertipe outlet tanpa data mutasi gudang', function () {
    [, $outlet] = makeWarehouseDashboardOrg();
    $admin = User::factory()->create(['store_id' => $outlet->id, 'role' => 'admin', 'email_verified_at' => now()]);
    $admin->stores()->attach($outlet->id, ['role' => 'admin']);

    $this->actingAs($admin)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('store.location_type', StoreLocationType::OUTLET)
            ->has('recentMovements', 0)
        );
});
