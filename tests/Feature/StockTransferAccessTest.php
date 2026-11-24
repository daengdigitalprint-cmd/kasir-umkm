<?php

/*
 * Keamanan Transfer Stok: admin toko di grup usaha LAIN tidak boleh melihat,
 * mengirim, menerima, membatalkan, atau mencetak transfer milik grup ini.
 * Admin dalam grup yang sama (walau tidak terdaftar di toko asal/tujuan) tetap boleh.
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

function accessOrg(string $name): Organization
{
    return Organization::query()->create(['name' => $name, 'slug' => 'org-'.uniqid()]);
}

function accessStore(?Organization $org, string $name, string $type = StoreLocationType::OUTLET): array
{
    $store = Store::query()->create([
        'name' => $name,
        'slug' => 'akses-'.uniqid(),
        'organization_id' => $org?->id,
        'location_type' => $type,
        'is_active' => true,
        'is_subscription_active' => true,
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $user->stores()->attach($store->id, ['role' => 'admin']);

    return [$store, $user];
}

function accessProduct(Store $store, float $stock): Product
{
    return Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Bumbu Dasar',
        'sku' => 'BMB-1',
        'price' => 0,
        'cost_price' => 10,
        'average_cost' => 10,
        'stock_quantity' => $stock,
        'base_unit' => 'gram',
        'unit' => 'gram',
        'track_stock' => true,
        'is_active' => true,
    ]);
}

/** @return array{StockTransfer, Product, Product} */
function accessTransfer(Store $from, Store $to, User $user, string $status): array
{
    $source = accessProduct($from, 1000);
    $target = accessProduct($to, 0);

    $transfer = StockTransfer::query()->create([
        'from_store_id' => $from->id,
        'to_store_id' => $to->id,
        'user_id' => $user->id,
        'status' => $status,
        'sent_at' => $status === StockTransfer::STATUS_SENT ? now() : null,
        'sent_by' => $status === StockTransfer::STATUS_SENT ? $user->id : null,
    ]);

    StockTransferLine::query()->create([
        'stock_transfer_id' => $transfer->id,
        'from_product_id' => $source->id,
        'to_product_id' => $target->id,
        'quantity' => 200,
        'base_unit' => 'gram',
        'unit_cost_snapshot' => 10,
        'total_cost_snapshot' => 2000,
    ]);

    return [$transfer, $source, $target];
}

function accessGroup(): array
{
    $org = accessOrg('Grup Resto');
    [$gudang, $gudangAdmin] = accessStore($org, 'Central Kitchen', StoreLocationType::CENTRAL_WAREHOUSE);
    [$outlet, $outletAdmin] = accessStore($org, 'Outlet A');

    return [$org, $gudang, $gudangAdmin, $outlet, $outletAdmin];
}

it('menolak admin grup lain melihat detail dan surat jalan', function () {
    [, $gudang, $gudangAdmin, $outlet] = accessGroup();
    [, $stranger] = accessStore(accessOrg('Grup Lain'), 'Toko Asing');
    [$transfer] = accessTransfer($gudang, $outlet, $gudangAdmin, StockTransfer::STATUS_SENT);

    $this->actingAs($stranger)->get("/stock-transfers/{$transfer->id}")->assertForbidden();
    $this->actingAs($stranger)->get("/stock-transfers/{$transfer->id}/delivery-note")->assertForbidden();
});

it('menolak admin toko tanpa grup melihat detail transfer', function () {
    [, $gudang, $gudangAdmin, $outlet] = accessGroup();
    [, $stranger] = accessStore(null, 'Warung Mandiri');
    [$transfer] = accessTransfer($gudang, $outlet, $gudangAdmin, StockTransfer::STATUS_SENT);

    $this->actingAs($stranger)->get("/stock-transfers/{$transfer->id}")->assertForbidden();
});

it('menolak admin grup lain mengirim transfer dan stok tidak berubah', function () {
    [, $gudang, $gudangAdmin, $outlet] = accessGroup();
    [, $stranger] = accessStore(accessOrg('Grup Lain'), 'Toko Asing');
    [$transfer, $source] = accessTransfer($gudang, $outlet, $gudangAdmin, StockTransfer::STATUS_DRAFT);

    $this->actingAs($stranger)->post("/stock-transfers/{$transfer->id}/send")->assertForbidden();

    expect($transfer->fresh()->status)->toBe(StockTransfer::STATUS_DRAFT)
        ->and((float) $source->fresh()->stock_quantity)->toBe(1000.0);
});

it('menolak admin grup lain menerima transfer dan stok tujuan tidak bertambah', function () {
    [, $gudang, $gudangAdmin, $outlet] = accessGroup();
    [, $stranger] = accessStore(accessOrg('Grup Lain'), 'Toko Asing');
    [$transfer, , $target] = accessTransfer($gudang, $outlet, $gudangAdmin, StockTransfer::STATUS_SENT);

    $this->actingAs($stranger)->post("/stock-transfers/{$transfer->id}/receive", receiptSignature())->assertForbidden();

    expect($transfer->fresh()->status)->toBe(StockTransfer::STATUS_SENT)
        ->and((float) $target->fresh()->stock_quantity)->toBe(0.0);
});

it('menolak admin grup lain membatalkan transfer', function () {
    [, $gudang, $gudangAdmin, $outlet] = accessGroup();
    [, $stranger] = accessStore(accessOrg('Grup Lain'), 'Toko Asing');
    [$transfer] = accessTransfer($gudang, $outlet, $gudangAdmin, StockTransfer::STATUS_DRAFT);

    $this->actingAs($stranger)
        ->post("/stock-transfers/{$transfer->id}/cancel", ['cancellation_reason' => 'iseng'])
        ->assertForbidden();

    expect($transfer->fresh()->status)->toBe(StockTransfer::STATUS_DRAFT);
});

it('tetap mengizinkan admin toko lain dalam grup yang sama melihat transfer', function () {
    [$org, $gudang, $gudangAdmin, $outlet] = accessGroup();
    [, $otherOutletAdmin] = accessStore($org, 'Outlet B');
    [$transfer] = accessTransfer($gudang, $outlet, $gudangAdmin, StockTransfer::STATUS_SENT);

    $this->actingAs($otherOutletAdmin)->get("/stock-transfers/{$transfer->id}")->assertOk();
});

it('tetap mengizinkan admin toko tujuan menerima transfer', function () {
    [, $gudang, $gudangAdmin, $outlet, $outletAdmin] = accessGroup();
    [$transfer] = accessTransfer($gudang, $outlet, $gudangAdmin, StockTransfer::STATUS_SENT);

    $this->actingAs($outletAdmin)->post("/stock-transfers/{$transfer->id}/receive", receiptSignature())->assertRedirect();

    expect($transfer->fresh()->status)->toBe(StockTransfer::STATUS_RECEIVED);
});
