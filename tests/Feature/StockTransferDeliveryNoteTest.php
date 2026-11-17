<?php

/*
 * F4 — Surat jalan Transfer Stok (PDF).
 * Bisa diunduh anggota lokasi asal/tujuan setelah transfer dikirim;
 * ditolak untuk toko lain dan untuk transfer yang masih draft.
 */

use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use App\Models\Store;
use App\Models\User;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function deliveryStore(string $name, string $type = StoreLocationType::OUTLET): array
{
    $store = Store::query()->create([
        'name' => $name,
        'slug' => 'sj-'.uniqid(),
        'location_type' => $type,
        'address' => 'Jl. Contoh No. 1',
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

function deliveryTransfer(Store $from, Store $to, User $user, string $status): StockTransfer
{
    $product = Product::query()->create([
        'store_id' => $from->id,
        'name' => 'Ayam Marinasi',
        'sku' => 'AYM-1',
        'price' => 0,
        'cost_price' => 30,
        'stock_quantity' => 1000,
        'base_unit' => 'gram',
        'unit' => 'gram',
        'track_stock' => true,
        'is_active' => true,
    ]);

    $target = Product::query()->create([
        'store_id' => $to->id,
        'name' => 'Ayam Marinasi',
        'sku' => 'AYM-1',
        'price' => 0,
        'cost_price' => 30,
        'stock_quantity' => 0,
        'base_unit' => 'gram',
        'unit' => 'gram',
        'track_stock' => true,
        'is_active' => true,
    ]);

    $transfer = StockTransfer::query()->create([
        'from_store_id' => $from->id,
        'to_store_id' => $to->id,
        'user_id' => $user->id,
        'status' => $status,
        'sent_at' => $status === StockTransfer::STATUS_DRAFT ? null : now(),
        'sent_by' => $status === StockTransfer::STATUS_DRAFT ? null : $user->id,
    ]);

    StockTransferLine::query()->create([
        'stock_transfer_id' => $transfer->id,
        'from_product_id' => $product->id,
        'to_product_id' => $target->id,
        'quantity' => 2500,
        'base_unit' => 'gram',
        'unit_cost_snapshot' => 30,
        'total_cost_snapshot' => 75000,
    ]);

    return $transfer;
}

it('mengunduh surat jalan untuk transfer yang sudah dikirim', function () {
    [$gudang, $admin] = deliveryStore('Central Kitchen', StoreLocationType::CENTRAL_WAREHOUSE);
    [$outlet] = deliveryStore('Outlet Kota');
    $transfer = deliveryTransfer($gudang, $outlet, $admin, StockTransfer::STATUS_SENT);

    $response = $this->actingAs($admin)->get("/stock-transfers/{$transfer->id}/delivery-note");

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf')
        ->and($response->headers->get('content-disposition'))->toContain('surat-jalan-'.$transfer->transfer_number.'.pdf');
});

it('bisa diunduh juga oleh lokasi tujuan', function () {
    [$gudang, $admin] = deliveryStore('Central Kitchen', StoreLocationType::CENTRAL_WAREHOUSE);
    [$outlet, $outletAdmin] = deliveryStore('Outlet Kota');
    $transfer = deliveryTransfer($gudang, $outlet, $admin, StockTransfer::STATUS_RECEIVED);

    $this->actingAs($outletAdmin)->get("/stock-transfers/{$transfer->id}/delivery-note")->assertOk();
});

it('menolak toko lain', function () {
    [$gudang, $admin] = deliveryStore('Central Kitchen', StoreLocationType::CENTRAL_WAREHOUSE);
    [$outlet] = deliveryStore('Outlet Kota');
    [, $stranger] = deliveryStore('Toko Asing');
    $transfer = deliveryTransfer($gudang, $outlet, $admin, StockTransfer::STATUS_SENT);

    $this->actingAs($stranger)->get("/stock-transfers/{$transfer->id}/delivery-note")->assertForbidden();
});

it('belum bisa dicetak saat transfer masih draft', function () {
    [$gudang, $admin] = deliveryStore('Central Kitchen', StoreLocationType::CENTRAL_WAREHOUSE);
    [$outlet] = deliveryStore('Outlet Kota');
    $transfer = deliveryTransfer($gudang, $outlet, $admin, StockTransfer::STATUS_DRAFT);

    $this->actingAs($admin)->get("/stock-transfers/{$transfer->id}/delivery-note")
        ->assertRedirect()
        ->assertSessionHas('error');
});

it('merender isi surat jalan: nomor, asal, tujuan, barang, jumlah', function () {
    [$gudang, $admin] = deliveryStore('Central Kitchen', StoreLocationType::CENTRAL_WAREHOUSE);
    [$outlet] = deliveryStore('Outlet Kota');
    $transfer = deliveryTransfer($gudang, $outlet, $admin, StockTransfer::STATUS_SENT);
    $transfer->load(['fromStore', 'toStore', 'user', 'sentBy', 'receivedBy', 'lines.fromProduct', 'lines.toProduct']);

    $html = view('pdf.delivery-note', ['transfer' => $transfer, 'statusLabel' => 'Dikirim'])->render();

    expect($html)->toContain('SURAT JALAN')
        ->toContain($transfer->transfer_number)
        ->toContain('Central Kitchen')
        ->toContain('Outlet Kota')
        ->toContain('Ayam Marinasi')
        ->toContain('2.500')
        ->toContain('gram');
});

it('menampilkan jam kirim dalam zona waktu toko pengirim', function () {
    [$gudang, $admin] = deliveryStore('Central Kitchen', StoreLocationType::CENTRAL_WAREHOUSE);
    [$outlet] = deliveryStore('Outlet Kota');
    $transfer = deliveryTransfer($gudang, $outlet, $admin, StockTransfer::STATUS_SENT);
    $transfer->update(['sent_at' => \Illuminate\Support\Carbon::parse('2026-11-17 01:00:00', 'UTC')]);
    $transfer->load(['fromStore', 'toStore', 'user', 'sentBy', 'receivedBy', 'lines.fromProduct', 'lines.toProduct']);

    $html = view('pdf.delivery-note', ['transfer' => $transfer, 'statusLabel' => 'Dikirim', 'timezone' => 'Asia/Jakarta'])->render();

    expect($html)->toContain('17 Nov 2026 08:00');
});
