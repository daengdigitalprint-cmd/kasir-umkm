<?php

/*
 * F5 — Terima sebagian & selisih.
 * Tanpa isian = diterima penuh (perilaku lama). Terima 8 dari 10 → stok tujuan +8,
 * selisih 2 tercatat dengan alasan. Lebih dari jumlah kirim / tanpa alasan ditolak.
 */

use App\Models\Organization;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use App\Models\Store;
use App\Models\User;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function partialStore(Organization $org, string $name, string $type = StoreLocationType::OUTLET): array
{
    $store = Store::query()->create([
        'name' => $name,
        'slug' => 'terima-'.uniqid(),
        'organization_id' => $org->id,
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

function partialProduct(Store $store, array $overrides = []): Product
{
    return Product::query()->create(array_merge([
        'store_id' => $store->id,
        'name' => 'Roti Burger',
        'sku' => 'RTB-1',
        'price' => 0,
        'cost_price' => 2000,
        'average_cost' => 2000,
        'stock_quantity' => 0,
        'base_unit' => 'pcs',
        'unit' => 'pcs',
        'track_stock' => true,
        'is_active' => true,
    ], $overrides));
}

/** Transfer berstatus Dikirim: 10 pcs @Rp2.000 dari CK ke outlet. */
function partialSetup(): array
{
    $org = Organization::query()->create(['name' => 'Grup Burger', 'slug' => 'grup-'.uniqid()]);
    [$ck, $ckAdmin] = partialStore($org, 'Central Kitchen', StoreLocationType::CENTRAL_WAREHOUSE);
    [$outlet, $outletAdmin] = partialStore($org, 'Outlet A');

    $source = partialProduct($ck, ['stock_quantity' => 90]);
    $target = partialProduct($outlet);

    $transfer = StockTransfer::query()->create([
        'from_store_id' => $ck->id,
        'to_store_id' => $outlet->id,
        'user_id' => $ckAdmin->id,
        'status' => StockTransfer::STATUS_SENT,
        'sent_at' => now(),
        'sent_by' => $ckAdmin->id,
    ]);

    $line = StockTransferLine::query()->create([
        'stock_transfer_id' => $transfer->id,
        'from_product_id' => $source->id,
        'to_product_id' => $target->id,
        'quantity' => 10,
        'base_unit' => 'pcs',
        'unit_cost_snapshot' => 2000,
        'total_cost_snapshot' => 20000,
    ]);

    return compact('transfer', 'line', 'target', 'outletAdmin');
}

it('tanpa isian menerima penuh seperti sebelumnya', function () {
    ['transfer' => $transfer, 'line' => $line, 'target' => $target, 'outletAdmin' => $admin] = partialSetup();

    $this->actingAs($admin)->post("/stock-transfers/{$transfer->id}/receive", receiptSignature())->assertSessionHasNoErrors();

    expect($transfer->fresh()->status)->toBe(StockTransfer::STATUS_RECEIVED)
        ->and((float) $target->fresh()->stock_quantity)->toBe(10.0)
        ->and($line->fresh()->quantity_received)->toBeNull()
        ->and($line->fresh()->discrepancy_reason)->toBeNull();
});

it('menerima 8 dari 10 dan mencatat selisih dengan alasan', function () {
    ['transfer' => $transfer, 'line' => $line, 'target' => $target, 'outletAdmin' => $admin] = partialSetup();

    $this->actingAs($admin)->post("/stock-transfers/{$transfer->id}/receive", [
        ...receiptSignature(),
        'lines' => [['id' => $line->id, 'quantity_received' => 8, 'discrepancy_reason' => '2 penyok di jalan']],
    ])->assertSessionHasNoErrors()->assertSessionHas('success');

    $line->refresh();
    $movement = StockMovement::query()
        ->where('product_id', $target->id)
        ->where('type', StockMovement::TYPE_TRANSFER_IN)
        ->sole();

    expect($transfer->fresh()->status)->toBe(StockTransfer::STATUS_RECEIVED)
        ->and((float) $target->fresh()->stock_quantity)->toBe(8.0)
        ->and((float) $target->fresh()->average_cost)->toBe(2000.0)
        ->and((float) $line->quantity_received)->toBe(8.0)
        ->and($line->discrepancy_reason)->toBe('2 penyok di jalan')
        ->and((float) $movement->quantity_change)->toBe(8.0)
        // F8: catatan mutasi menyebut jenis selisihnya (tanpa jenis = kurang).
        ->and($line->discrepancy_type)->toBe('short')
        ->and($movement->notes)->toContain('kurang')
        ->and($movement->notes)->toContain('2 penyok di jalan');
});

it('isian sama dengan jumlah kirim dianggap sesuai tanpa alasan', function () {
    ['transfer' => $transfer, 'line' => $line, 'target' => $target, 'outletAdmin' => $admin] = partialSetup();

    $this->actingAs($admin)->post("/stock-transfers/{$transfer->id}/receive", [
        ...receiptSignature(),
        'lines' => [['id' => $line->id, 'quantity_received' => 10]],
    ])->assertSessionHasNoErrors();

    expect((float) $target->fresh()->stock_quantity)->toBe(10.0)
        ->and($line->fresh()->discrepancy_reason)->toBeNull();
});

it('menerima 0 bila semua barang rusak, tanpa stok masuk', function () {
    ['transfer' => $transfer, 'line' => $line, 'target' => $target, 'outletAdmin' => $admin] = partialSetup();

    $this->actingAs($admin)->post("/stock-transfers/{$transfer->id}/receive", [
        ...receiptSignature(),
        'lines' => [['id' => $line->id, 'quantity_received' => 0, 'discrepancy_reason' => 'Basi semua']],
    ])->assertSessionHasNoErrors();

    expect($transfer->fresh()->status)->toBe(StockTransfer::STATUS_RECEIVED)
        ->and((float) $target->fresh()->stock_quantity)->toBe(0.0)
        ->and(StockMovement::query()->where('product_id', $target->id)->exists())->toBeFalse();
});

it('menolak penerimaan yang tidak valid dan tidak mengubah stok', function (array $row) {
    ['transfer' => $transfer, 'line' => $line, 'target' => $target, 'outletAdmin' => $admin] = partialSetup();

    $this->actingAs($admin)->post("/stock-transfers/{$transfer->id}/receive", [
        ...receiptSignature(),
        'lines' => [array_merge(['id' => $line->id], $row)],
    ])->assertSessionHasErrors();

    expect($transfer->fresh()->status)->toBe(StockTransfer::STATUS_SENT)
        ->and((float) $target->fresh()->stock_quantity)->toBe(0.0)
        ->and($line->fresh()->quantity_received)->toBeNull();
})->with([
    'kurang tanpa alasan' => [['quantity_received' => 8]],
    'negatif' => [['quantity_received' => -1]],
]);

it('menolak baris milik transfer lain', function () {
    ['transfer' => $transfer, 'target' => $target, 'outletAdmin' => $admin] = partialSetup();

    $this->actingAs($admin)->post("/stock-transfers/{$transfer->id}/receive", [
        ...receiptSignature(),
        'lines' => [['id' => 999999, 'quantity_received' => 1, 'discrepancy_reason' => 'x']],
    ])->assertSessionHasErrors('lines');

    expect((float) $target->fresh()->stock_quantity)->toBe(0.0);
});

it('tidak bisa diterima dua kali', function () {
    ['transfer' => $transfer, 'target' => $target, 'outletAdmin' => $admin] = partialSetup();

    $this->actingAs($admin)->post("/stock-transfers/{$transfer->id}/receive", receiptSignature());
    $this->actingAs($admin)->post("/stock-transfers/{$transfer->id}/receive", receiptSignature());

    expect((float) $target->fresh()->stock_quantity)->toBe(10.0);
});

it('menyaring daftar transfer yang punya selisih', function () {
    ['transfer' => $transfer, 'line' => $line, 'outletAdmin' => $admin] = partialSetup();

    $this->actingAs($admin)->post("/stock-transfers/{$transfer->id}/receive", [
        ...receiptSignature(),
        'lines' => [['id' => $line->id, 'quantity_received' => 7, 'discrepancy_reason' => 'Kurang dari gudang']],
    ]);

    $this->actingAs($admin)->get('/stock-transfers?discrepancy=1')->assertOk()->assertInertia(
        fn ($page) => $page->component('StockTransfers/Index')
            ->where('discrepancyFilter', true)
            ->has('transfers.data', 1)
            ->where('transfers.data.0.discrepancy_lines_count', 1)
    );
});

it('daftar dengan filter selisih kosong bila semua diterima penuh', function () {
    ['transfer' => $transfer, 'outletAdmin' => $admin] = partialSetup();

    $this->actingAs($admin)->post("/stock-transfers/{$transfer->id}/receive", receiptSignature());

    $this->actingAs($admin)->get('/stock-transfers?discrepancy=1')->assertOk()->assertInertia(
        fn ($page) => $page->has('transfers.data', 0)
    );
});
