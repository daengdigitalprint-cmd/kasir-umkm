<?php

/*
 * F6 — Permintaan Stok antar lokasi (Modul Central Kitchen).
 * Outlet meminta → Gudang Pusat merevisi & menyetujui → Transfer Stok terkirim →
 * outlet menerima → permintaan Selesai. Tolak & batal tidak mengubah stok.
 */

use App\Models\Organization;
use App\Models\Product;
use App\Models\StockRequest;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Models\User;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Siaran realtime tidak diuji di sini; cegah koneksi ke server WebSocket.
    Event::fake([\App\Events\StockRequestUpdated::class]);
});

function ckStore(Organization $org, string $name, string $type = StoreLocationType::OUTLET, string $role = 'admin'): array
{
    $store = Store::query()->create([
        'name' => $name,
        'slug' => 'ck-'.uniqid(),
        'organization_id' => $org->id,
        'location_type' => $type,
        'is_active' => true,
        'is_subscription_active' => true,
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
        'role' => $role,
        'email_verified_at' => now(),
    ]);
    $user->stores()->attach($store->id, ['role' => $role]);

    return [$store, $user];
}

function ckProduct(Store $store, float $stock, array $overrides = []): Product
{
    return Product::query()->create(array_merge([
        'store_id' => $store->id,
        'name' => 'Saus Sambal',
        'sku' => 'SBL-1',
        'price' => 0,
        'cost_price' => 15000,
        'average_cost' => 15000,
        'stock_quantity' => $stock,
        'min_stock_level' => 5,
        'base_unit' => 'pcs',
        'unit' => 'pcs',
        'track_stock' => true,
        'is_active' => true,
    ], $overrides));
}

/** Grup dengan modul CK aktif: Gudang Pusat (stok 50) + Outlet A (stok 2). */
function ckGroup(bool $moduleActive = true): array
{
    $org = Organization::query()->create([
        'name' => 'Grup Ayam',
        'slug' => 'grup-'.uniqid(),
        'central_kitchen_until' => $moduleActive ? now()->addMonth() : null,
    ]);
    [$ck, $ckAdmin] = ckStore($org, 'Central Kitchen', StoreLocationType::CENTRAL_WAREHOUSE);
    [$outlet, $outletAdmin] = ckStore($org, 'Outlet A');

    $ckProduct = ckProduct($ck, 50);
    $outletProduct = ckProduct($outlet, 2);

    return compact('org', 'ck', 'ckAdmin', 'outlet', 'outletAdmin', 'ckProduct', 'outletProduct');
}

function ckRequest($test, array $g, float $qty = 10): StockRequest
{
    $test->actingAs($g['outletAdmin'])->post('/stock-requests', [
        'to_store_id' => $g['ck']->id,
        'notes' => 'Untuk akhir pekan',
        'items' => [['product_id' => $g['outletProduct']->id, 'quantity' => $qty]],
    ])->assertSessionHasNoErrors();

    return StockRequest::query()->latest('id')->firstOrFail();
}

it('outlet membuat permintaan dengan snapshot stok dan barang padanan gudang', function () {
    $g = ckGroup();
    $request = ckRequest($this, $g);
    $line = $request->lines()->sole();

    expect($request->status)->toBe(StockRequest::STATUS_REQUESTED)
        ->and($request->from_store_id)->toBe($g['outlet']->id)
        ->and($request->to_store_id)->toBe($g['ck']->id)
        ->and($line->supplier_product_id)->toBe($g['ckProduct']->id)
        ->and((float) $line->quantity_requested)->toBe(10.0)
        ->and((float) $line->stock_at_request)->toBe(2.0)
        ->and((float) $line->min_stock_at_request)->toBe(5.0);

    Event::assertDispatched(\App\Events\StockRequestUpdated::class, fn ($e) => $e->action === 'created');
});

it('gudang melihat permintaan masuk, outlet lain tidak', function () {
    $g = ckGroup();
    $request = ckRequest($this, $g);
    [, $otherOutletCashier] = ckStore($g['org'], 'Outlet B', StoreLocationType::OUTLET, 'cashier');
    [, $stranger] = ckStore(Organization::query()->create(['name' => 'Lain', 'slug' => 'lain-'.uniqid(), 'central_kitchen_until' => now()->addMonth()]), 'Toko Lain');

    $this->actingAs($g['ckAdmin'])->get('/stock-requests?tab=incoming')->assertOk()->assertInertia(
        fn ($page) => $page->component('StockRequests/Index')
            ->where('pendingIncoming', 1)
            ->has('requests.data', 1)
            ->where('requests.data.0.id', $request->id)
    );
    $this->actingAs($g['ckAdmin'])->get("/stock-requests/{$request->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->where('canApprove', true)->where('canCancel', false));

    $this->actingAs($stranger)->get("/stock-requests/{$request->id}")->assertForbidden();
    $this->actingAs($otherOutletCashier)->get("/stock-requests/{$request->id}")->assertStatus(302);
});

it('gudang merevisi 10 menjadi 8 lalu transfer terbit dan stok gudang berkurang 8', function () {
    $g = ckGroup();
    $request = ckRequest($this, $g);
    $line = $request->lines()->sole();

    $this->actingAs($g['ckAdmin'])->post("/stock-requests/{$request->id}/approve", [
        'lines' => [['id' => $line->id, 'quantity_approved' => 8]],
    ])->assertSessionHasNoErrors();

    $request->refresh();
    $transfer = $request->transfer;

    expect($request->status)->toBe(StockRequest::STATUS_APPROVED)
        ->and($request->approved_by)->toBe($g['ckAdmin']->id)
        ->and((float) $line->fresh()->quantity_approved)->toBe(8.0)
        ->and($transfer)->not->toBeNull()
        ->and($transfer->status)->toBe(StockTransfer::STATUS_SENT)
        ->and($transfer->from_store_id)->toBe($g['ck']->id)
        ->and($transfer->to_store_id)->toBe($g['outlet']->id)
        ->and((float) $transfer->lines()->sole()->quantity)->toBe(8.0)
        ->and((float) $g['ckProduct']->fresh()->stock_quantity)->toBe(42.0)
        ->and((float) $g['outletProduct']->fresh()->stock_quantity)->toBe(2.0);
});

it('outlet menerima transfer, stok outlet +8 dan permintaan Selesai', function () {
    $g = ckGroup();
    $request = ckRequest($this, $g);
    $this->actingAs($g['ckAdmin'])->post("/stock-requests/{$request->id}/approve", [
        'lines' => [['id' => $request->lines()->sole()->id, 'quantity_approved' => 8]],
    ]);

    $transfer = $request->fresh()->transfer;
    $this->actingAs($g['outletAdmin'])->post("/stock-transfers/{$transfer->id}/receive", receiptSignature())->assertSessionHasNoErrors();

    expect((float) $g['outletProduct']->fresh()->stock_quantity)->toBe(10.0)
        ->and($request->fresh()->status)->toBe(StockRequest::STATUS_COMPLETED)
        ->and($request->fresh()->completed_at)->not->toBeNull();
});

it('membatalkan transfer hasil permintaan ikut membatalkan permintaan dan mengembalikan stok gudang', function () {
    $g = ckGroup();
    $request = ckRequest($this, $g);
    $this->actingAs($g['ckAdmin'])->post("/stock-requests/{$request->id}/approve");

    $transfer = $request->fresh()->transfer;
    $this->actingAs($g['ckAdmin'])->post("/stock-transfers/{$transfer->id}/cancel", ['cancellation_reason' => 'Kurir batal'])
        ->assertSessionHasNoErrors();

    expect($request->fresh()->status)->toBe(StockRequest::STATUS_CANCELLED)
        ->and((float) $g['ckProduct']->fresh()->stock_quantity)->toBe(50.0);
});

it('tolak dan batal tidak mengubah stok', function () {
    $g = ckGroup();

    $rejected = ckRequest($this, $g);
    $this->actingAs($g['ckAdmin'])->post("/stock-requests/{$rejected->id}/reject", ['reason' => 'Stok untuk event'])
        ->assertSessionHasNoErrors();

    $cancelled = ckRequest($this, $g);
    $this->actingAs($g['outletAdmin'])->post("/stock-requests/{$cancelled->id}/cancel")->assertSessionHasNoErrors();

    expect($rejected->fresh()->status)->toBe(StockRequest::STATUS_REJECTED)
        ->and($rejected->fresh()->rejected_reason)->toBe('Stok untuk event')
        ->and($cancelled->fresh()->status)->toBe(StockRequest::STATUS_CANCELLED)
        ->and((float) $g['ckProduct']->fresh()->stock_quantity)->toBe(50.0)
        ->and((float) $g['outletProduct']->fresh()->stock_quantity)->toBe(2.0)
        ->and(StockTransfer::query()->count())->toBe(0);
});

it('hanya pemasok yang bisa menyetujui dan hanya peminta yang bisa membatalkan', function () {
    $g = ckGroup();
    $request = ckRequest($this, $g);

    $this->actingAs($g['outletAdmin'])->post("/stock-requests/{$request->id}/approve")->assertForbidden();
    $this->actingAs($g['outletAdmin'])->post("/stock-requests/{$request->id}/reject", ['reason' => 'abc'])->assertForbidden();
    $this->actingAs($g['ckAdmin'])->post("/stock-requests/{$request->id}/cancel")->assertForbidden();

    expect($request->fresh()->status)->toBe(StockRequest::STATUS_REQUESTED);
});

it('menolak persetujuan saat stok gudang tidak cukup', function () {
    $g = ckGroup();
    $request = ckRequest($this, $g, 60);

    $this->actingAs($g['ckAdmin'])->post("/stock-requests/{$request->id}/approve")->assertSessionHasErrors('lines');

    expect($request->fresh()->status)->toBe(StockRequest::STATUS_REQUESTED)
        ->and((float) $g['ckProduct']->fresh()->stock_quantity)->toBe(50.0);
});

it('menolak persetujuan bila semua jumlah direvisi jadi 0', function () {
    $g = ckGroup();
    $request = ckRequest($this, $g);

    $this->actingAs($g['ckAdmin'])->post("/stock-requests/{$request->id}/approve", [
        'lines' => [['id' => $request->lines()->sole()->id, 'quantity_approved' => 0]],
    ])->assertSessionHasErrors('lines');

    expect($request->fresh()->status)->toBe(StockRequest::STATUS_REQUESTED);
});

it('tidak bisa disetujui dua kali', function () {
    $g = ckGroup();
    $request = ckRequest($this, $g);

    $this->actingAs($g['ckAdmin'])->post("/stock-requests/{$request->id}/approve");
    $this->actingAs($g['ckAdmin'])->post("/stock-requests/{$request->id}/approve")->assertSessionHasErrors('request');

    expect(StockTransfer::query()->count())->toBe(1)
        ->and((float) $g['ckProduct']->fresh()->stock_quantity)->toBe(40.0);
});

it('menolak barang yang tidak ada padanannya di gudang', function () {
    $g = ckGroup();
    $lonely = ckProduct($g['outlet'], 0, ['name' => 'Es Batu', 'sku' => 'ES-1']);

    $this->actingAs($g['outletAdmin'])->post('/stock-requests', [
        'to_store_id' => $g['ck']->id,
        'items' => [['product_id' => $lonely->id, 'quantity' => 3]],
    ])->assertSessionHasErrors('items.0.product_id');

    expect(StockRequest::query()->count())->toBe(0);
});

it('menolak meminta ke toko di luar grup usaha', function () {
    $g = ckGroup();
    [$foreign] = ckStore(Organization::query()->create(['name' => 'Lain', 'slug' => 'lain-'.uniqid()]), 'Gudang Lain', StoreLocationType::CENTRAL_WAREHOUSE);
    ckProduct($foreign, 100);

    $this->actingAs($g['outletAdmin'])->post('/stock-requests', [
        'to_store_id' => $foreign->id,
        'items' => [['product_id' => $g['outletProduct']->id, 'quantity' => 3]],
    ])->assertSessionHasErrors('to_store_id');
});

it('toko tanpa modul Central Kitchen tidak bisa membuka Permintaan Stok', function () {
    $g = ckGroup(moduleActive: false);

    $this->actingAs($g['outletAdmin'])->get('/stock-requests')->assertRedirect(route('subscription.index', ['tab' => 'central_kitchen']));
    $this->actingAs($g['outletAdmin'])->post('/stock-requests', [
        'to_store_id' => $g['ck']->id,
        'items' => [['product_id' => $g['outletProduct']->id, 'quantity' => 3]],
    ])->assertRedirect(route('subscription.index', ['tab' => 'central_kitchen']));

    expect(StockRequest::query()->count())->toBe(0);
});

it('modul kedaluwarsa menutup akses', function () {
    $g = ckGroup();
    $g['org']->update(['central_kitchen_until' => now()->subDay()]);

    $this->actingAs($g['outletAdmin'])->get('/stock-requests')->assertRedirect(route('subscription.index', ['tab' => 'central_kitchen']));
});

it('form permintaan menampilkan stok gudang dan isi awal dari Daftar Belanja', function () {
    $g = ckGroup();

    $this->actingAs($g['outletAdmin'])
        ->get('/stock-requests/create?'.http_build_query(['items' => [$g['outletProduct']->id.'::8', '999::1']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('StockRequests/Create')
            ->where('suppliers.0.id', $g['ck']->id)
            ->where("availability.{$g['ck']->id}.{$g['outletProduct']->id}", 50)
            ->has('prefill', 1)
            ->where('prefill.0.quantity', 8));
});

it('perintah artisan mengaktifkan dan mematikan modul', function () {
    $org = Organization::query()->create(['name' => 'Grup Baru', 'slug' => 'baru-'.uniqid()]);

    $this->artisan('central-kitchen:activate', ['organization' => $org->id, '--days' => 30])->assertSuccessful();
    expect($org->fresh()->central_kitchen_until->isFuture())->toBeTrue();

    $this->artisan('central-kitchen:activate', ['organization' => $org->id, '--off' => true])->assertSuccessful();
    expect($org->fresh()->central_kitchen_until)->toBeNull();
});

it('Daftar Belanja menawarkan "Minta ke Gudang Pusat" hanya bila modul aktif', function () {
    $active = ckGroup();
    $this->actingAs($active['outletAdmin'])->get('/shopping-list')->assertOk()
        ->assertInertia(fn ($page) => $page->where('canRequestStock', true));

    $inactive = ckGroup(moduleActive: false);
    $this->actingAs($inactive['outletAdmin'])->get('/shopping-list')->assertOk()
        ->assertInertia(fn ($page) => $page->where('canRequestStock', false));
});
