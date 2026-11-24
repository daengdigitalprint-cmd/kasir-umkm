<?php

/*
 * Jual-beli internal: outlet MEMBELI stok ke gudang/CK (bukan sekadar transfer).
 * Harga dikunci saat disetujui, tagihan mengikuti jumlah yang diterima, gudang
 * mencatat pelunasan, dan laporan menampilkan pemasukan gudang / pengeluaran outlet.
 */

use App\Models\Organization;
use App\Models\Product;
use App\Models\StockRequest;
use App\Models\Store;
use App\Models\User;
use App\Support\InternalPurchase;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake([\App\Events\StockRequestUpdated::class]);
});

function ipStore(Organization $org, string $name, string $type = StoreLocationType::OUTLET, string $role = 'admin', array $settings = []): array
{
    $store = Store::query()->create([
        'name' => $name,
        'slug' => 'ip-'.uniqid(),
        'organization_id' => $org->id,
        'location_type' => $type,
        'settings' => $settings,
        'is_active' => true,
        'is_subscription_active' => true,
    ]);

    $user = User::factory()->create(['store_id' => $store->id, 'role' => $role, 'email_verified_at' => now()]);
    $user->stores()->attach($store->id, ['role' => $role]);

    return [$store, $user];
}

function ipProduct(Store $store, float $stock, array $overrides = []): Product
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

/** Gudang Pusat (HPP 15.000, markup 20% → harga 18.000, stok 50) + Outlet A (stok 2, HPP 15.000). */
function ipGroup(float $markup = 20): array
{
    $org = Organization::query()->create([
        'name' => 'Grup Ayam',
        'slug' => 'grup-'.uniqid(),
        'central_kitchen_until' => now()->addMonth(),
    ]);
    [$ck, $ckAdmin] = ipStore($org, 'Central Kitchen', StoreLocationType::CENTRAL_WAREHOUSE, 'admin', ['internal_sale_markup_percent' => $markup]);
    [$outlet, $outletAdmin] = ipStore($org, 'Outlet A');
    $ckProduct = ipProduct($ck, 50);
    $outletProduct = ipProduct($outlet, 2);

    return compact('org', 'ck', 'ckAdmin', 'outlet', 'outletAdmin', 'ckProduct', 'outletProduct');
}

function ipOrder($test, array $g, float $qty = 10, ?string $mode = 'purchase'): StockRequest
{
    $test->actingAs($g['outletAdmin'])->post('/stock-requests', array_filter([
        'to_store_id' => $g['ck']->id,
        'mode' => $mode,
        'items' => [['product_id' => $g['outletProduct']->id, 'quantity' => $qty]],
    ], fn ($v) => $v !== null))->assertSessionHasNoErrors();

    return StockRequest::query()->latest('id')->firstOrFail();
}

function ipApprove($test, array $g, StockRequest $request, array $line = []): StockRequest
{
    $test->actingAs($g['ckAdmin'])->post("/stock-requests/{$request->id}/approve", [
        'lines' => [array_merge(['id' => $request->lines()->sole()->id, 'quantity_approved' => 10], $line)],
    ])->assertSessionHasNoErrors();

    return $request->fresh();
}

// ── Membuat ────────────────────────────────────────────────────────────────

it('outlet memilih beli ke gudang dan melihat perkiraan harga gudang', function () {
    $g = ipGroup();

    $this->actingAs($g['outletAdmin'])->get('/stock-requests/create')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('StockRequests/Create')
            ->where('defaultMode', 'transfer')
            // HPP gudang 15.000 + markup 20% = 18.000.
            ->where("prices.{$g['ck']->id}.{$g['outletProduct']->id}", 18000));

    $request = ipOrder($this, $g);
    $line = $request->lines()->sole();

    expect($request->mode)->toBe(InternalPurchase::MODE_PURCHASE)
        ->and($request->payment_status)->toBe(InternalPurchase::PAYMENT_UNPAID)
        ->and((float) $line->unit_price)->toBe(18000.0)
        ->and((float) $request->total_amount)->toBe(180000.0)
        // Jenis yang dipakai diingat sebagai bawaan lokasi peminta.
        ->and(InternalPurchase::defaultMode($g['outlet']->fresh()))->toBe(InternalPurchase::MODE_PURCHASE);
});

it('transfer stok biasa tetap tanpa harga dan tanpa tagihan', function () {
    $g = ipGroup();
    $request = ipOrder($this, $g, 10, null); // form lama: tanpa kolom jenis
    $request = ipApprove($this, $g, $request);

    expect($request->mode)->toBe(InternalPurchase::MODE_TRANSFER)
        ->and($request->payment_status)->toBeNull()
        ->and((float) $request->total_amount)->toBe(0.0)
        ->and($request->lines()->sole()->unit_price)->toBeNull()
        // Transfer biasa membawa HPP gudang, bukan harga jual.
        ->and((float) $request->transfer->lines()->sole()->unit_cost_snapshot)->toBe(15000.0);

    $this->actingAs($g['ckAdmin'])->post("/stock-requests/{$request->id}/pay", ['payment_method' => 'cash'])
        ->assertSessionHasErrors('request');
});

it('menolak jenis permintaan yang tidak dikenal', function () {
    $g = ipGroup();

    $this->actingAs($g['outletAdmin'])->post('/stock-requests', [
        'to_store_id' => $g['ck']->id,
        'mode' => 'hibah',
        'items' => [['product_id' => $g['outletProduct']->id, 'quantity' => 1]],
    ])->assertSessionHasErrors('mode');
});

// ── Menyetujui: harga dikunci, HPP outlet = harga beli ─────────────────────

it('gudang merevisi jumlah dan harga saat menyetujui, lalu tagihan dihitung', function () {
    $g = ipGroup();
    $request = ipApprove($this, $g, ipOrder($this, $g), ['quantity_approved' => 8, 'unit_price' => 17500]);
    $line = $request->lines()->sole();

    expect($request->status)->toBe(StockRequest::STATUS_APPROVED)
        ->and((float) $line->unit_price)->toBe(17500.0)
        ->and((float) $line->unit_cost)->toBe(15000.0) // HPP gudang saat itu
        ->and((float) $line->quantity_billed)->toBe(8.0)
        ->and((float) $line->line_total)->toBe(140000.0)
        ->and((float) $request->total_amount)->toBe(140000.0)
        ->and((float) $g['ckProduct']->fresh()->stock_quantity)->toBe(42.0)
        // Barang berangkat dengan nilai = harga beli outlet.
        ->and((float) $request->transfer->lines()->sole()->unit_cost_snapshot)->toBe(17500.0);
});

it('tanpa revisi harga, gudang memakai harga bawaannya', function () {
    $g = ipGroup();
    $request = ipApprove($this, $g, ipOrder($this, $g));

    expect((float) $request->lines()->sole()->unit_price)->toBe(18000.0)
        ->and((float) $request->total_amount)->toBe(180000.0);
});

it('setelah diterima, HPP outlet mengikuti harga beli dan tagihan mengikuti jumlah diterima', function () {
    $g = ipGroup();
    $request = ipApprove($this, $g, ipOrder($this, $g));
    $transfer = $request->transfer;
    $transferLine = $transfer->lines()->sole();

    // Outlet hanya menerima 9 dari 10 (1 kurang).
    $this->actingAs($g['outletAdmin'])->post("/stock-transfers/{$transfer->id}/receive", [
        ...receiptSignature(),
        'lines' => [['id' => $transferLine->id, 'quantity_received' => 9, 'discrepancy_type' => 'short', 'discrepancy_reason' => 'Kurang 1']],
    ])->assertSessionHasNoErrors();

    $request->refresh();
    $outletProduct = $g['outletProduct']->fresh();

    expect($request->status)->toBe(StockRequest::STATUS_COMPLETED)
        ->and((float) $request->lines()->sole()->quantity_billed)->toBe(9.0)
        ->and((float) $request->total_amount)->toBe(162000.0) // 9 × 18.000
        ->and((float) $outletProduct->stock_quantity)->toBe(11.0)
        // Rata-rata tertimbang: (2 × 15.000 + 9 × 18.000) / 11.
        ->and(round((float) $outletProduct->average_cost, 2))->toBe(round((2 * 15000 + 9 * 18000) / 11, 2));
});

// ── Pelunasan ──────────────────────────────────────────────────────────────

it('gudang mencatat pelunasan setelah barang dikirim; outlet tidak bisa menandai sendiri', function () {
    $g = ipGroup();
    $request = ipOrder($this, $g);

    // Belum disetujui → belum ada tagihan yang bisa dilunasi.
    $this->actingAs($g['ckAdmin'])->post("/stock-requests/{$request->id}/pay", ['payment_method' => 'transfer'])
        ->assertSessionHasErrors('request');

    $request = ipApprove($this, $g, $request);

    $this->actingAs($g['outletAdmin'])->post("/stock-requests/{$request->id}/pay", ['payment_method' => 'transfer'])->assertForbidden();
    expect($request->fresh()->isPaid())->toBeFalse();

    $this->actingAs($g['ckAdmin'])->get("/stock-requests/{$request->id}")
        ->assertInertia(fn ($page) => $page->where('canPay', true)->where('request.payment.status', 'unpaid')->where('request.total_amount', 180000));

    $this->actingAs($g['ckAdmin'])
        ->post("/stock-requests/{$request->id}/pay", ['payment_method' => 'transfer', 'payment_note' => 'BCA 24/11'])
        ->assertSessionHasNoErrors();

    $request->refresh();
    expect($request->isPaid())->toBeTrue()
        ->and($request->payment_method)->toBe('transfer')
        ->and($request->payment_note)->toBe('BCA 24/11')
        ->and($request->paid_by)->toBe($g['ckAdmin']->id)
        ->and($request->paid_at)->not->toBeNull();

    // Tidak bisa dilunasi dua kali.
    $this->actingAs($g['ckAdmin'])->post("/stock-requests/{$request->id}/pay", ['payment_method' => 'cash'])
        ->assertSessionHasErrors('request');
    $this->actingAs($g['outletAdmin'])->get("/stock-requests/{$request->id}")
        ->assertInertia(fn ($page) => $page->where('canPay', false)->where('request.payment.status', 'paid'));
});

// ── Harga jual gudang ──────────────────────────────────────────────────────

it('pengelola gudang mengatur markup harga jual; kasir tidak boleh', function () {
    $g = ipGroup(0);
    expect(InternalPurchase::defaultUnitPrice($g['ckProduct'], $g['ck']))->toBe(15000.0);

    $this->actingAs($g['ckAdmin'])->put('/stock-requests/pricing', ['markup_percent' => 12.5])->assertSessionHasNoErrors();
    $ck = $g['ck']->fresh();
    expect(InternalPurchase::markupPercent($ck))->toBe(12.5)
        ->and(InternalPurchase::defaultUnitPrice($g['ckProduct'], $ck))->toBe(16875.0);

    $this->actingAs($g['ckAdmin'])->put('/stock-requests/pricing', ['markup_percent' => -5])->assertSessionHasErrors('markup_percent');

    [, $cashier] = ipStore($g['org'], 'Outlet B', StoreLocationType::OUTLET, 'cashier');
    $this->actingAs($cashier)->put('/stock-requests/pricing', ['markup_percent' => 50])->assertStatus(302);
    expect(InternalPurchase::markupPercent($g['ck']->fresh()))->toBe(12.5);
});

// ── Laporan ────────────────────────────────────────────────────────────────

it('laporan menampilkan pemasukan gudang dan pengeluaran outlet dari pembelian yang sama', function () {
    $g = ipGroup();
    [$outletB, $outletBAdmin] = ipStore($g['org'], 'Outlet B');
    $bProduct = ipProduct($outletB, 1);

    // Outlet A membeli 10 (180.000) dan sudah lunas.
    $paid = ipApprove($this, $g, ipOrder($this, $g));
    $this->actingAs($g['ckAdmin'])->post("/stock-requests/{$paid->id}/pay", ['payment_method' => 'cash']);

    // Outlet B membeli 5 (90.000), belum dibayar.
    $this->actingAs($outletBAdmin)->post('/stock-requests', [
        'to_store_id' => $g['ck']->id, 'mode' => 'purchase', 'items' => [['product_id' => $bProduct->id, 'quantity' => 5]],
    ])->assertSessionHasNoErrors();
    $unpaid = StockRequest::query()->latest('id')->firstOrFail();
    $this->actingAs($g['ckAdmin'])->post("/stock-requests/{$unpaid->id}/approve", [
        'lines' => [['id' => $unpaid->lines()->sole()->id, 'quantity_approved' => 5]],
    ])->assertSessionHasNoErrors();

    // Transfer biasa dan pembelian yang belum disetujui tidak masuk laporan.
    ipApprove($this, $g, ipOrder($this, $g, 3, 'transfer'), ['quantity_approved' => 3]);
    ipOrder($this, $g, 2);

    // Sisi gudang: pemasukan.
    $this->actingAs($g['ckAdmin'])->get('/stock-requests/report')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('StockRequests/Report')
            ->where('direction', 'income')
            ->where('summary.count', 2)
            ->where('summary.total', 270000)
            ->where('summary.paid', 180000)
            ->where('summary.unpaid', 90000)
            // HPP 15 × 15.000 = 225.000 → laba kotor 45.000.
            ->where('summary.total_cost', 225000)
            ->where('summary.gross_profit', 45000)
            ->has('byStore', 2)
            ->where('byStore.0.name', 'Outlet A')
            ->where('byStore.0.total', 180000)
            ->where('byStore.1.unpaid', 90000)
            ->has('rows.data', 2));

    // Saringan: hanya yang belum dibayar.
    $this->actingAs($g['ckAdmin'])->get('/stock-requests/report?payment=unpaid')
        ->assertInertia(fn ($page) => $page->where('summary.count', 1)->where('summary.total', 90000)->where('rows.data.0.store', 'Outlet B'));

    // Sisi outlet A: pengeluaran pembelian stok (bukan pemasukan), tanpa HPP gudang.
    $this->actingAs($g['outletAdmin'])->get('/stock-requests/report')
        ->assertInertia(fn ($page) => $page
            ->where('direction', 'expense')
            ->where('summary.count', 1)
            ->where('summary.total', 180000)
            ->where('summary.unpaid', 0)
            ->missing('summary.gross_profit')
            ->where('rows.data.0.store', 'Central Kitchen'));

    // Di luar periode → kosong.
    $this->actingAs($g['ckAdmin'])->get('/stock-requests/report?from=2020-01-01&to=2020-01-31')
        ->assertInertia(fn ($page) => $page->where('summary.count', 0)->has('rows.data', 0));
});

it('staf gudang tanpa akses biaya tidak menerima HPP maupun laba kotor', function () {
    $g = ipGroup();
    $request = ipApprove($this, $g, ipOrder($this, $g));

    $staff = User::factory()->create(['store_id' => $g['ck']->id, 'role' => 'warehouse', 'email_verified_at' => now()]);
    $staff->stores()->attach($g['ck']->id, ['role' => 'warehouse']);

    $this->actingAs($staff)->get("/stock-requests/{$request->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('request.lines.0.unit_price', 18000)->missing('request.lines.0.unit_cost'));

    $this->actingAs($staff)->get('/stock-requests/report')
        ->assertInertia(fn ($page) => $page->where('summary.total', 180000)->missing('summary.total_cost')->missing('summary.gross_profit'));
});

it('permintaan otomatis memakai jenis bawaan lokasi peminta', function () {
    $g = ipGroup();
    $settings = $g['outlet']->settings ?? [];
    $settings['stock_request_default_mode'] = 'purchase';
    $g['outlet']->update(['settings' => $settings]);

    $request = app(\App\Actions\StockRequestWorkflow::class)->create(
        null,
        $g['outlet']->fresh(),
        $g['ck'],
        [['product_id' => $g['outletProduct']->id, 'quantity' => 4]],
        automatic: true,
        mode: InternalPurchase::defaultMode($g['outlet']->fresh()),
    );

    expect($request->isPurchase())->toBeTrue()
        ->and((float) $request->total_amount)->toBe(72000.0);
});

it('daftar permintaan menampilkan jenis, tagihan, dan status bayar', function () {
    $g = ipGroup();
    $request = ipApprove($this, $g, ipOrder($this, $g));

    $this->actingAs($g['ckAdmin'])->get('/stock-requests?tab=incoming')
        ->assertInertia(fn ($page) => $page
            ->where('requests.data.0.id', $request->id)
            ->where('requests.data.0.mode', 'purchase')
            ->where('requests.data.0.total_amount', 180000)
            ->where('requests.data.0.payment_status', 'unpaid')
            ->where('pricing.markup_percent', 20)
            ->where('pricing.can_manage', true));
});
