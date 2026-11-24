<?php

/*
 * F9 — Foto bukti selisih, penerimaan buta, dan pengajuan koreksi setelah diterima.
 *
 * Koreksi diajukan lokasi penerima (maksimal 24 jam setelah diterima) dan diputuskan lokasi pengirim.
 * Saat disetujui, stok penerima mengikuti jumlah tiba yang benar dan status selisih baris dihitung ulang.
 */

use App\Models\Organization;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferCorrection;
use App\Models\StockTransferLine;
use App\Models\Store;
use App\Models\User;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function corrStore(Organization $org, string $name, string $type = StoreLocationType::OUTLET, array $settings = []): array
{
    $store = Store::query()->create([
        'name' => $name,
        'slug' => 'koreksi-'.uniqid(),
        'organization_id' => $org->id,
        'location_type' => $type,
        'is_active' => true,
        'is_subscription_active' => true,
        'settings' => $settings,
    ]);

    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin', 'email_verified_at' => now()]);
    $user->stores()->attach($store->id, ['role' => 'admin']);

    return [$store, $user];
}

function corrProduct(Store $store, array $overrides = []): Product
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

/** Transfer Dikirim: 10 pcs dari gudang (sisa 90) ke outlet (stok 0). */
function corrSetup(array $outletSettings = []): array
{
    $org = Organization::query()->create(['name' => 'Grup Burger', 'slug' => 'grup-'.uniqid()]);
    [$ck, $ckAdmin] = corrStore($org, 'Gudang Pusat', StoreLocationType::CENTRAL_WAREHOUSE);
    [$outlet, $outletAdmin] = corrStore($org, 'Outlet A', StoreLocationType::OUTLET, $outletSettings);

    $source = corrProduct($ck, ['stock_quantity' => 90]);
    $target = corrProduct($outlet);

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

    return compact('transfer', 'line', 'source', 'target', 'ckAdmin', 'outletAdmin', 'outlet');
}

/** Terima dengan satu baris (kosong = sesuai jumlah kirim). */
function corrReceive($test, array $ctx, array $row = ['quantity_received' => 10]): void
{
    $test->actingAs($ctx['outletAdmin'])->post("/stock-transfers/{$ctx['transfer']->id}/receive", [
        ...receiptSignature(),
        'lines' => [array_merge(['id' => $ctx['line']->id], $row)],
    ])->assertSessionHasNoErrors();
}

/** Ajukan koreksi jumlah tiba untuk baris tunggal. */
function corrRequest($test, array $ctx, float $quantity, array $extra = [])
{
    return $test->actingAs($ctx['outletAdmin'])->post("/stock-transfers/{$ctx['transfer']->id}/corrections", [
        'reason' => 'Salah hitung saat terima',
        'lines' => [array_merge(['id' => $ctx['line']->id, 'quantity_received' => $quantity], $extra)],
    ]);
}

function corrDecide($test, array $ctx, string $decision, array $payload = [], string $actor = 'ckAdmin')
{
    $correction = StockTransferCorrection::query()->where('stock_transfer_id', $ctx['transfer']->id)->latest('id')->firstOrFail();

    return $test->actingAs($ctx[$actor])->post("/stock-transfers/{$ctx['transfer']->id}/corrections/{$correction->id}/{$decision}", $payload);
}

// ── Foto bukti ─────────────────────────────────────────────────────────────

it('menyimpan foto bukti untuk barang yang berselisih', function () {
    Storage::fake('public');
    $ctx = corrSetup();

    corrReceive($this, $ctx, [
        'quantity_received' => 8,
        'discrepancy_type' => 'damaged',
        'discrepancy_reason' => 'Penyok',
        'photo' => UploadedFile::fake()->image('rusak.jpg', 800, 600),
    ]);

    $path = $ctx['line']->fresh()->discrepancy_photo;
    expect($path)->toStartWith('transfer-discrepancies/');
    Storage::disk('public')->assertExists($path);
});

it('mengabaikan foto untuk barang yang diterima sesuai', function () {
    Storage::fake('public');
    $ctx = corrSetup();

    corrReceive($this, $ctx, ['quantity_received' => 10, 'photo' => UploadedFile::fake()->image('foto.jpg')]);

    expect($ctx['line']->fresh()->discrepancy_photo)->toBeNull()
        ->and(Storage::disk('public')->allFiles('transfer-discrepancies'))->toBe([]);
});

it('menolak foto bukti yang bukan gambar dan tidak menerima transfernya', function () {
    Storage::fake('public');
    $ctx = corrSetup();

    $this->actingAs($ctx['outletAdmin'])->post("/stock-transfers/{$ctx['transfer']->id}/receive", [
        ...receiptSignature(),
        'lines' => [[
            'id' => $ctx['line']->id,
            'quantity_received' => 8,
            'discrepancy_reason' => 'Kurang',
            'photo' => UploadedFile::fake()->create('nota.pdf', 100, 'application/pdf'),
        ]],
    ])->assertSessionHasErrors();

    expect($ctx['transfer']->fresh()->status)->toBe(StockTransfer::STATUS_SENT);
});

// ── Penerimaan buta ────────────────────────────────────────────────────────

it('penerimaan buta mengikuti pengaturan lokasi penerima', function () {
    $ctx = corrSetup(['blind_transfer_receiving' => true]);

    $this->actingAs($ctx['outletAdmin'])->get("/stock-transfers/{$ctx['transfer']->id}")
        ->assertInertia(fn ($page) => $page->where('blindReceiving', true));
    // Pengirim tidak menerima, jadi tidak dalam mode hitung buta.
    $this->actingAs($ctx['ckAdmin'])->get("/stock-transfers/{$ctx['transfer']->id}")
        ->assertInertia(fn ($page) => $page->where('blindReceiving', false));

    corrReceive($this, $ctx);

    expect($ctx['transfer']->fresh()->received_blind)->toBeTrue();
    $this->actingAs($ctx['outletAdmin'])->get("/stock-transfers/{$ctx['transfer']->id}")
        ->assertInertia(fn ($page) => $page->where('blindReceiving', false)->where('transfer.received_blind', true));
});

it('tanpa pengaturan, penerimaan tidak ditandai buta', function () {
    $ctx = corrSetup();

    $this->actingAs($ctx['outletAdmin'])->get("/stock-transfers/{$ctx['transfer']->id}")
        ->assertInertia(fn ($page) => $page->where('blindReceiving', false));
    corrReceive($this, $ctx);

    expect($ctx['transfer']->fresh()->received_blind)->toBeFalse();
});

it('menyimpan saklar penerimaan buta dari pengaturan toko', function () {
    $ctx = corrSetup();

    $this->actingAs($ctx['outletAdmin'])->put('/settings/store', [
        'name' => $ctx['outlet']->name,
        'business_type' => $ctx['outlet']->business_type ?? 'retail',
        'blind_transfer_receiving' => true,
    ])->assertSessionHasNoErrors();

    expect($ctx['outlet']->fresh()->settings['blind_transfer_receiving'] ?? null)->toBeTrue();
});

// ── Pengajuan koreksi ──────────────────────────────────────────────────────

it('mengajukan koreksi tanpa mengubah stok sampai diputuskan', function () {
    $ctx = corrSetup();
    corrReceive($this, $ctx, ['quantity_received' => 8, 'discrepancy_reason' => 'Kurang 2']);

    corrRequest($this, $ctx, 10)->assertSessionHasNoErrors()->assertSessionHas('success');

    $correction = StockTransferCorrection::query()->sole();
    $cLine = $correction->lines()->sole();
    expect($correction->status)->toBe('pending')
        ->and($correction->reason)->toBe('Salah hitung saat terima')
        ->and($correction->requested_by)->toBe($ctx['outletAdmin']->id)
        ->and((float) $cLine->quantity_before)->toBe(8.0)
        ->and((float) $cLine->quantity_after)->toBe(10.0)
        ->and((float) $ctx['target']->fresh()->stock_quantity)->toBe(8.0)
        ->and((float) $ctx['line']->fresh()->quantity_received)->toBe(8.0);
});

it('koreksi naik yang disetujui menambah stok penerima dan menghapus selisih', function () {
    $ctx = corrSetup();
    corrReceive($this, $ctx, ['quantity_received' => 8, 'discrepancy_reason' => 'Kurang 2']);
    corrRequest($this, $ctx, 10);

    corrDecide($this, $ctx, 'approve', ['note' => 'Oke'])->assertSessionHasNoErrors()->assertSessionHas('success');

    $line = $ctx['line']->fresh();
    $correction = StockTransferCorrection::query()->sole();
    $movement = StockMovement::query()->where('product_id', $ctx['target']->id)->latest('id')->first();

    expect($correction->status)->toBe('approved')
        ->and($correction->decided_by)->toBe($ctx['ckAdmin']->id)
        ->and($correction->decision_note)->toBe('Oke')
        ->and((float) $line->quantity_received)->toBe(10.0)
        ->and($line->discrepancy_type)->toBeNull()
        ->and($line->needsResolution())->toBeFalse()
        ->and((float) $ctx['target']->fresh()->stock_quantity)->toBe(10.0)
        ->and((float) $ctx['source']->fresh()->stock_quantity)->toBe(90.0)
        ->and((float) $movement->quantity_change)->toBe(2.0)
        ->and($movement->type)->toBe(StockMovement::TYPE_TRANSFER_IN)
        ->and($movement->notes)->toContain('koreksi penerimaan');
});

it('koreksi turun yang disetujui mengurangi stok penerima dan memunculkan selisih baru', function () {
    $ctx = corrSetup();
    corrReceive($this, $ctx);
    corrRequest($this, $ctx, 7, ['discrepancy_type' => 'damaged', 'note' => '3 ternyata berjamur']);

    corrDecide($this, $ctx, 'approve')->assertSessionHasNoErrors();

    $line = $ctx['line']->fresh();
    $movement = StockMovement::query()->where('product_id', $ctx['target']->id)->latest('id')->first();

    expect((float) $line->quantity_received)->toBe(7.0)
        ->and($line->discrepancy_type)->toBe('damaged')
        ->and($line->discrepancy_reason)->toContain('3 ternyata berjamur')
        ->and($line->needsResolution())->toBeTrue()
        ->and((float) $line->discrepancyQuantity())->toBe(3.0)
        ->and((float) $ctx['target']->fresh()->stock_quantity)->toBe(7.0)
        ->and((float) $movement->quantity_change)->toBe(-3.0)
        ->and($movement->type)->toBe(StockMovement::TYPE_TRANSFER_IN);

    // Selisih baru bisa langsung diputuskan pengirim lewat alur F8.
    $this->actingAs($ctx['ckAdmin'])->post("/stock-transfers/{$ctx['transfer']->id}/resolve-discrepancies", [
        'lines' => [['id' => $line->id, 'resolution' => 'loss']],
    ])->assertSessionHasNoErrors();
    expect($ctx['line']->fresh()->resolution)->toBe('loss');
});

it('koreksi menjadi lebih dari jumlah kirim menahan kelebihannya', function () {
    $ctx = corrSetup();
    corrReceive($this, $ctx);
    corrRequest($this, $ctx, 12);

    corrDecide($this, $ctx, 'approve')->assertSessionHasNoErrors();

    $line = $ctx['line']->fresh();
    expect($line->discrepancy_type)->toBe('over')
        ->and((float) $line->quantity_received)->toBe(10.0)
        ->and((float) $line->quantity_over)->toBe(2.0)
        ->and($line->needsResolution())->toBeTrue()
        ->and((float) $ctx['target']->fresh()->stock_quantity)->toBe(10.0);
});

it('koreksi atas selisih yang sudah dicatat rugi membuka kembali keputusannya', function () {
    $ctx = corrSetup();
    corrReceive($this, $ctx, ['quantity_received' => 8, 'discrepancy_reason' => 'Kurang 2']);
    $this->actingAs($ctx['ckAdmin'])->post("/stock-transfers/{$ctx['transfer']->id}/resolve-discrepancies", [
        'lines' => [['id' => $ctx['line']->id, 'resolution' => 'loss']],
    ]);

    corrRequest($this, $ctx, 9)->assertSessionHasNoErrors();
    corrDecide($this, $ctx, 'approve')->assertSessionHasNoErrors();

    $line = $ctx['line']->fresh();
    expect((float) $line->quantity_received)->toBe(9.0)
        ->and($line->discrepancy_type)->toBe('short')
        ->and($line->resolution)->toBeNull()
        ->and($line->resolved_at)->toBeNull()
        ->and((float) $ctx['target']->fresh()->stock_quantity)->toBe(9.0);
});

it('menolak koreksi untuk selisih yang sudah diselesaikan dengan perpindahan stok', function () {
    $ctx = corrSetup();
    corrReceive($this, $ctx, ['quantity_received' => 8, 'discrepancy_reason' => 'Kurang 2']);
    $this->actingAs($ctx['ckAdmin'])->post("/stock-transfers/{$ctx['transfer']->id}/resolve-discrepancies", [
        'lines' => [['id' => $ctx['line']->id, 'resolution' => 'returned']],
    ]);

    corrRequest($this, $ctx, 10)->assertSessionHasErrors('lines');

    expect(StockTransferCorrection::query()->count())->toBe(0);
    $this->actingAs($ctx['outletAdmin'])->get("/stock-transfers/{$ctx['transfer']->id}")
        ->assertInertia(fn ($page) => $page->where('canRequestCorrection', false)->where('correctableLineIds', []));
});

it('penolakan wajib beralasan dan tidak mengubah apa pun', function () {
    $ctx = corrSetup();
    corrReceive($this, $ctx, ['quantity_received' => 8, 'discrepancy_reason' => 'Kurang 2']);
    corrRequest($this, $ctx, 10);

    corrDecide($this, $ctx, 'reject')->assertSessionHasErrors('note');
    corrDecide($this, $ctx, 'reject', ['note' => 'Sudah dicek CCTV, memang 8'])->assertSessionHasNoErrors();

    $correction = StockTransferCorrection::query()->sole();
    expect($correction->status)->toBe('rejected')
        ->and($correction->decision_note)->toBe('Sudah dicek CCTV, memang 8')
        ->and((float) $ctx['line']->fresh()->quantity_received)->toBe(8.0)
        ->and((float) $ctx['target']->fresh()->stock_quantity)->toBe(8.0);
});

it('penerima boleh membatalkan pengajuannya, lalu mengajukan lagi', function () {
    $ctx = corrSetup();
    corrReceive($this, $ctx, ['quantity_received' => 8, 'discrepancy_reason' => 'Kurang 2']);
    corrRequest($this, $ctx, 10);

    corrDecide($this, $ctx, 'cancel', [], 'outletAdmin')->assertSessionHasNoErrors();
    expect(StockTransferCorrection::query()->sole()->status)->toBe('cancelled');

    corrRequest($this, $ctx, 9)->assertSessionHasNoErrors();
    expect(StockTransferCorrection::query()->where('status', 'pending')->count())->toBe(1);
});

it('membatasi siapa yang mengajukan dan memutuskan', function () {
    $ctx = corrSetup();
    corrReceive($this, $ctx, ['quantity_received' => 8, 'discrepancy_reason' => 'Kurang 2']);

    // Pengirim tidak mengajukan koreksi atas penerimaan orang lain.
    $this->actingAs($ctx['ckAdmin'])->post("/stock-transfers/{$ctx['transfer']->id}/corrections", [
        'reason' => 'Coba-coba', 'lines' => [['id' => $ctx['line']->id, 'quantity_received' => 10]],
    ])->assertForbidden();

    corrRequest($this, $ctx, 10);

    // Penerima tidak menyetujui pengajuannya sendiri; pengirim tidak membatalkannya.
    corrDecide($this, $ctx, 'approve', [], 'outletAdmin')->assertForbidden();
    corrDecide($this, $ctx, 'reject', ['note' => 'x'], 'outletAdmin')->assertForbidden();
    corrDecide($this, $ctx, 'cancel', [], 'ckAdmin')->assertForbidden();

    expect(StockTransferCorrection::query()->sole()->status)->toBe('pending');
});

it('menolak pengajuan yang tidak sah', function (Closure $prepare, array $payload) {
    $ctx = corrSetup();
    corrReceive($this, $ctx, ['quantity_received' => 8, 'discrepancy_reason' => 'Kurang 2']);
    $prepare($ctx);

    $this->actingAs($ctx['outletAdmin'])->post("/stock-transfers/{$ctx['transfer']->id}/corrections", [
        'reason' => $payload['reason'] ?? 'Salah hitung saat terima',
        'lines' => [['id' => $payload['line'] ?? $ctx['line']->id, 'quantity_received' => $payload['qty'] ?? 10]],
    ])->assertSessionHasErrors();

    expect(StockTransferCorrection::query()->where('status', 'pending')->count())->toBe($payload['pending'] ?? 0);
})->with([
    'batas 24 jam sudah lewat' => [fn (array $ctx) => $ctx['transfer']->update(['received_at' => now()->subHours(25)]), []],
    'jumlah tidak berubah' => [fn () => null, ['qty' => 8]],
    'jumlah negatif' => [fn () => null, ['qty' => -1]],
    'tanpa alasan' => [fn () => null, ['reason' => '']],
    'baris transfer lain' => [fn () => null, ['line' => 999999]],
    'masih ada pengajuan menunggu' => [fn (array $ctx) => StockTransferCorrection::query()->create([
        'stock_transfer_id' => $ctx['transfer']->id, 'status' => 'pending', 'reason' => 'Sebelumnya', 'requested_by' => $ctx['outletAdmin']->id,
    ]), ['pending' => 1]],
]);

it('koreksi belum bisa diajukan sebelum transfer diterima', function () {
    $ctx = corrSetup();

    corrRequest($this, $ctx, 9)->assertSessionHasErrors('lines');
});

it('selisih tidak bisa diputuskan selama ada pengajuan koreksi yang menunggu', function () {
    $ctx = corrSetup();
    corrReceive($this, $ctx, ['quantity_received' => 8, 'discrepancy_reason' => 'Kurang 2']);
    corrRequest($this, $ctx, 10);

    $this->actingAs($ctx['ckAdmin'])->post("/stock-transfers/{$ctx['transfer']->id}/resolve-discrepancies", [
        'lines' => [['id' => $ctx['line']->id, 'resolution' => 'returned']],
    ])->assertSessionHasErrors('lines');

    expect($ctx['line']->fresh()->resolution)->toBeNull()
        ->and((float) $ctx['source']->fresh()->stock_quantity)->toBe(90.0);
    $this->actingAs($ctx['ckAdmin'])->get("/stock-transfers/{$ctx['transfer']->id}")
        ->assertInertia(fn ($page) => $page->where('canResolve', false)->where('canDecideCorrection', true));
});

it('gagal menyetujui koreksi turun bila stok penerima sudah terpakai', function () {
    $ctx = corrSetup();
    corrReceive($this, $ctx);
    corrRequest($this, $ctx, 4);
    $ctx['target']->update(['stock_quantity' => 3]); // sebagian sudah terjual

    corrDecide($this, $ctx, 'approve')->assertSessionHas('error');

    expect(StockTransferCorrection::query()->sole()->status)->toBe('pending')
        ->and((float) $ctx['target']->fresh()->stock_quantity)->toBe(3.0)
        ->and((float) $ctx['line']->fresh()->quantity_received)->toBe(10.0);
});

it('gagal menyetujui bila catatan baris berubah sejak koreksi diajukan', function () {
    $ctx = corrSetup();
    corrReceive($this, $ctx, ['quantity_received' => 8, 'discrepancy_reason' => 'Kurang 2']);
    corrRequest($this, $ctx, 10);
    $ctx['line']->update(['quantity_received' => 9]);

    corrDecide($this, $ctx, 'approve')->assertSessionHasErrors('correction');

    expect(StockTransferCorrection::query()->sole()->status)->toBe('pending')
        ->and((float) $ctx['target']->fresh()->stock_quantity)->toBe(8.0);
});

it('koreksi yang sudah diputuskan tidak bisa diputuskan lagi', function () {
    $ctx = corrSetup();
    corrReceive($this, $ctx, ['quantity_received' => 8, 'discrepancy_reason' => 'Kurang 2']);
    corrRequest($this, $ctx, 10);
    corrDecide($this, $ctx, 'approve')->assertSessionHasNoErrors();

    corrDecide($this, $ctx, 'approve')->assertSessionHasErrors('correction');

    expect((float) $ctx['target']->fresh()->stock_quantity)->toBe(10.0);
});

it('pengajuan koreksi muncul di daftar pengirim dan di halaman detail', function () {
    $ctx = corrSetup();
    corrReceive($this, $ctx);
    corrRequest($this, $ctx, 9);

    $this->actingAs($ctx['ckAdmin'])->get('/stock-transfers?needs_resolution=1')->assertInertia(
        fn ($page) => $page->where('needsResolutionCount', 1)
            ->has('transfers.data', 1)
            ->where('transfers.data.0.pending_corrections_count', 1)
    );
    $this->actingAs($ctx['outletAdmin'])->get('/stock-transfers')->assertInertia(fn ($page) => $page->where('needsResolutionCount', 0));

    $this->actingAs($ctx['outletAdmin'])->get("/stock-transfers/{$ctx['transfer']->id}")->assertInertia(
        fn ($page) => $page->where('canRequestCorrection', false)
            ->where('canCancelCorrection', true)
            ->where('canDecideCorrection', false)
            ->has('transfer.corrections', 1)
            ->where('transfer.corrections.0.status', 'pending')
            ->has('transfer.corrections.0.lines', 1)
    );
});

it('menawarkan koreksi kepada penerima hanya dalam batas waktu', function () {
    $ctx = corrSetup();
    corrReceive($this, $ctx);

    $this->actingAs($ctx['outletAdmin'])->get("/stock-transfers/{$ctx['transfer']->id}")->assertInertia(
        fn ($page) => $page->where('canRequestCorrection', true)->where('correctableLineIds', [$ctx['line']->id])->where('correctionWindowHours', 24)
    );
    $this->actingAs($ctx['ckAdmin'])->get("/stock-transfers/{$ctx['transfer']->id}")->assertInertia(fn ($page) => $page->where('canRequestCorrection', false));

    $ctx['transfer']->update(['received_at' => now()->subHours(25)]);
    $this->actingAs($ctx['outletAdmin'])->get("/stock-transfers/{$ctx['transfer']->id}")->assertInertia(fn ($page) => $page->where('canRequestCorrection', false));
});
