<?php

/*
 * F8 — Bukti terima & penyelesaian selisih.
 *
 * Terima wajib menyertakan nama + tanda tangan penerima. Selisih dicatat dengan jenis
 * (kurang/rusak/lebih) dan menunggu keputusan lokasi pengirim:
 * kurang/rusak → dikembalikan ke stok pengirim atau dicatat rugi (opsional kiriman susulan);
 * lebih → diakui (stok pindah) atau ditarik kembali (stok tetap).
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

function sigStore(Organization $org, string $name, string $type = StoreLocationType::OUTLET): array
{
    $store = Store::query()->create([
        'name' => $name,
        'slug' => 'ttd-'.uniqid(),
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

function sigProduct(Store $store, array $overrides = []): Product
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

/** Transfer Dikirim: 10 pcs @Rp2.000 dari gudang (sisa stok 90) ke outlet (stok 0). */
function sigSetup(): array
{
    $org = Organization::query()->create(['name' => 'Grup Burger', 'slug' => 'grup-'.uniqid()]);
    [$ck, $ckAdmin] = sigStore($org, 'Gudang Pusat', StoreLocationType::CENTRAL_WAREHOUSE);
    [$outlet, $outletAdmin] = sigStore($org, 'Outlet A');

    $source = sigProduct($ck, ['stock_quantity' => 90]);
    $target = sigProduct($outlet);

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

    return compact('transfer', 'line', 'source', 'target', 'ckAdmin', 'outletAdmin');
}

/** Terima dengan satu baris berselisih, lalu kembalikan konteksnya. */
function sigReceive($test, array $ctx, array $row): void
{
    $test->actingAs($ctx['outletAdmin'])->post("/stock-transfers/{$ctx['transfer']->id}/receive", [
        ...receiptSignature(),
        'lines' => [array_merge(['id' => $ctx['line']->id], $row)],
    ])->assertSessionHasNoErrors();
}

// ── Tanda tangan ───────────────────────────────────────────────────────────

it('menyimpan nama dan tanda tangan penerima saat diterima', function () {
    $ctx = sigSetup();

    $this->actingAs($ctx['outletAdmin'])
        ->post("/stock-transfers/{$ctx['transfer']->id}/receive", receiptSignature('Sari Kasir'))
        ->assertSessionHasNoErrors();

    $transfer = $ctx['transfer']->fresh();
    expect($transfer->status)->toBe(StockTransfer::STATUS_RECEIVED)
        ->and($transfer->receiver_name)->toBe('Sari Kasir')
        ->and($transfer->receiver_signature)->toStartWith('data:image/png;base64,')
        ->and((float) $ctx['target']->fresh()->stock_quantity)->toBe(10.0);
});

it('menolak penerimaan tanpa nama atau tanda tangan yang sah', function (array $payload, string $field) {
    $ctx = sigSetup();

    $this->actingAs($ctx['outletAdmin'])
        ->post("/stock-transfers/{$ctx['transfer']->id}/receive", $payload)
        ->assertSessionHasErrors($field);

    expect($ctx['transfer']->fresh()->status)->toBe(StockTransfer::STATUS_SENT)
        ->and((float) $ctx['target']->fresh()->stock_quantity)->toBe(0.0);
})->with([
    'tanpa apa pun' => [[], 'receiver_signature'],
    'tanpa nama' => [['receiver_signature' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='], 'receiver_name'],
    'tanda tangan bukan gambar' => [['receiver_name' => 'Budi', 'receiver_signature' => 'halo'], 'receiver_signature'],
    'base64 bukan PNG' => [['receiver_name' => 'Budi', 'receiver_signature' => 'data:image/png;base64,'.base64_encode('bukan png')], 'receiver_signature'],
]);

it('tidak mengirim tanda tangan ke daftar transfer, tetapi mengirimnya ke halaman detail', function () {
    $ctx = sigSetup();
    $this->actingAs($ctx['outletAdmin'])->post("/stock-transfers/{$ctx['transfer']->id}/receive", receiptSignature());

    $this->actingAs($ctx['outletAdmin'])->get('/stock-transfers')->assertOk()->assertInertia(
        fn ($page) => $page->has('transfers.data', 1)->missing('transfers.data.0.receiver_signature')
    );
    $this->actingAs($ctx['outletAdmin'])->get("/stock-transfers/{$ctx['transfer']->id}")->assertOk()->assertInertia(
        fn ($page) => $page->where('transfer.receiver_name', 'Budi Penerima')
            ->where('transfer.receiver_signature', fn ($v) => str_starts_with((string) $v, 'data:image/png;base64,'))
    );
});

it('bukti terima memuat nama, tanda tangan, dan selisih', function () {
    $ctx = sigSetup();
    sigReceive($this, $ctx, ['quantity_received' => 8, 'discrepancy_type' => 'damaged', 'discrepancy_reason' => 'Penyok']);

    $transfer = $ctx['transfer']->fresh()->load(['fromStore', 'toStore', 'user', 'sentBy', 'receivedBy', 'lines.fromProduct', 'lines.toProduct']);
    $html = view('pdf.delivery-note', ['transfer' => $transfer, 'statusLabel' => 'Diterima', 'timezone' => 'Asia/Makassar'])->render();

    expect($html)->toContain('BUKTI TERIMA BARANG')
        ->and($html)->toContain('Budi Penerima')
        ->and($html)->toContain('data:image/png;base64,')
        ->and($html)->toContain('Rusak 2')
        ->and($html)->toContain('Menunggu keputusan pengirim');

    $this->actingAs($ctx['outletAdmin'])->get("/stock-transfers/{$transfer->id}/delivery-note")
        ->assertOk()
        ->assertHeader('content-disposition', 'attachment; filename=bukti-terima-'.$transfer->transfer_number.'.pdf');
});

// ── Jenis selisih saat terima ──────────────────────────────────────────────

it('mencatat jenis selisih kurang atau rusak', function (?string $type, string $expected) {
    $ctx = sigSetup();
    sigReceive($this, $ctx, array_filter(['quantity_received' => 7, 'discrepancy_type' => $type, 'discrepancy_reason' => 'Catatan']));

    $line = $ctx['line']->fresh();
    expect($line->discrepancy_type)->toBe($expected)
        ->and((float) $line->quantity_received)->toBe(7.0)
        ->and($line->needsResolution())->toBeTrue()
        ->and((float) $line->discrepancyQuantity())->toBe(3.0)
        ->and((float) $ctx['target']->fresh()->stock_quantity)->toBe(7.0);
})->with([
    'kurang' => ['short', 'short'],
    'rusak' => ['damaged', 'damaged'],
    'tanpa jenis dianggap kurang' => [null, 'short'],
]);

it('barang lebih hanya menambah stok sebesar jumlah kirim sampai pengirim memutuskan', function () {
    $ctx = sigSetup();
    sigReceive($this, $ctx, ['quantity_received' => 12]);

    $line = $ctx['line']->fresh();
    expect($line->discrepancy_type)->toBe('over')
        ->and((float) $line->quantity_received)->toBe(10.0)
        ->and((float) $line->quantity_over)->toBe(2.0)
        ->and($line->needsResolution())->toBeTrue()
        ->and((float) $ctx['target']->fresh()->stock_quantity)->toBe(10.0)
        ->and((float) $ctx['source']->fresh()->stock_quantity)->toBe(90.0);
});

it('penerimaan yang sesuai tidak menunggu penyelesaian', function () {
    $ctx = sigSetup();
    sigReceive($this, $ctx, ['quantity_received' => 10]);

    expect($ctx['line']->fresh()->discrepancy_type)->toBeNull()
        ->and($ctx['line']->fresh()->needsResolution())->toBeFalse();
});

// ── Penyelesaian oleh pengirim ─────────────────────────────────────────────

it('mengembalikan barang kurang ke stok pengirim', function () {
    $ctx = sigSetup();
    sigReceive($this, $ctx, ['quantity_received' => 8, 'discrepancy_type' => 'short', 'discrepancy_reason' => 'Tidak ada di kiriman']);

    $this->actingAs($ctx['ckAdmin'])->post("/stock-transfers/{$ctx['transfer']->id}/resolve-discrepancies", [
        'lines' => [['id' => $ctx['line']->id, 'resolution' => 'returned', 'note' => 'Tertinggal di rak']],
    ])->assertSessionHasNoErrors()->assertSessionHas('success');

    $line = $ctx['line']->fresh();
    $movement = StockMovement::query()->where('product_id', $ctx['source']->id)->where('type', StockMovement::TYPE_TRANSFER_REVERSAL)->sole();

    expect($line->resolution)->toBe('returned')
        ->and($line->resolution_note)->toBe('Tertinggal di rak')
        ->and($line->resolved_by)->toBe($ctx['ckAdmin']->id)
        ->and($line->resolved_at)->not->toBeNull()
        ->and((float) $ctx['source']->fresh()->stock_quantity)->toBe(92.0)
        ->and((float) $ctx['target']->fresh()->stock_quantity)->toBe(8.0)
        ->and((float) $movement->quantity_change)->toBe(2.0)
        ->and($movement->notes)->toContain('dikembalikan ke stok pengirim');
});

it('mencatat barang rusak sebagai rugi tanpa mengubah stok', function () {
    $ctx = sigSetup();
    sigReceive($this, $ctx, ['quantity_received' => 8, 'discrepancy_type' => 'damaged', 'discrepancy_reason' => 'Penyok']);

    $this->actingAs($ctx['ckAdmin'])->post("/stock-transfers/{$ctx['transfer']->id}/resolve-discrepancies", [
        'lines' => [['id' => $ctx['line']->id, 'resolution' => 'loss']],
    ])->assertSessionHasNoErrors();

    expect($ctx['line']->fresh()->resolution)->toBe('loss')
        ->and((float) $ctx['source']->fresh()->stock_quantity)->toBe(90.0)
        ->and((float) $ctx['target']->fresh()->stock_quantity)->toBe(8.0)
        ->and(StockMovement::query()->where('product_id', $ctx['source']->id)->exists())->toBeFalse();
});

it('membuat draft kiriman susulan untuk jumlah yang kurang', function () {
    $ctx = sigSetup();
    sigReceive($this, $ctx, ['quantity_received' => 6, 'discrepancy_type' => 'short', 'discrepancy_reason' => 'Kurang 4']);

    $this->actingAs($ctx['ckAdmin'])->post("/stock-transfers/{$ctx['transfer']->id}/resolve-discrepancies", [
        'lines' => [['id' => $ctx['line']->id, 'resolution' => 'returned', 'resend' => true]],
    ])->assertSessionHasNoErrors();

    $followup = StockTransfer::query()->where('followup_of_id', $ctx['transfer']->id)->sole();
    $followupLine = $followup->lines()->sole();

    expect($followup->status)->toBe(StockTransfer::STATUS_DRAFT)
        ->and($followup->from_store_id)->toBe($ctx['transfer']->from_store_id)
        ->and($followup->to_store_id)->toBe($ctx['transfer']->to_store_id)
        ->and($followup->notes)->toContain($ctx['transfer']->transfer_number)
        ->and((float) $followupLine->quantity)->toBe(4.0)
        ->and($followupLine->from_product_id)->toBe($ctx['source']->id)
        ->and($followupLine->to_product_id)->toBe($ctx['target']->id)
        // Draft belum mengurangi stok: 90 + 4 yang dikembalikan.
        ->and((float) $ctx['source']->fresh()->stock_quantity)->toBe(94.0);
});

it('mengakui kelebihan: stok pengirim berkurang dan stok penerima bertambah', function () {
    $ctx = sigSetup();
    sigReceive($this, $ctx, ['quantity_received' => 12]);

    $this->actingAs($ctx['ckAdmin'])->post("/stock-transfers/{$ctx['transfer']->id}/resolve-discrepancies", [
        'lines' => [['id' => $ctx['line']->id, 'resolution' => 'accepted']],
    ])->assertSessionHasNoErrors();

    expect($ctx['line']->fresh()->resolution)->toBe('accepted')
        ->and((float) $ctx['source']->fresh()->stock_quantity)->toBe(88.0)
        ->and((float) $ctx['target']->fresh()->stock_quantity)->toBe(12.0)
        ->and((float) $ctx['target']->fresh()->average_cost)->toBe(2000.0);
});

it('menarik kembali kelebihan tanpa mengubah stok', function () {
    $ctx = sigSetup();
    sigReceive($this, $ctx, ['quantity_received' => 12]);

    $this->actingAs($ctx['ckAdmin'])->post("/stock-transfers/{$ctx['transfer']->id}/resolve-discrepancies", [
        'lines' => [['id' => $ctx['line']->id, 'resolution' => 'rejected']],
    ])->assertSessionHasNoErrors();

    expect($ctx['line']->fresh()->resolution)->toBe('rejected')
        ->and((float) $ctx['source']->fresh()->stock_quantity)->toBe(90.0)
        ->and((float) $ctx['target']->fresh()->stock_quantity)->toBe(10.0);
});

it('gagal mengakui kelebihan bila stok pengirim tidak cukup, tanpa perubahan apa pun', function () {
    $ctx = sigSetup();
    sigReceive($this, $ctx, ['quantity_received' => 12]);
    $ctx['source']->update(['stock_quantity' => 1]);

    $this->actingAs($ctx['ckAdmin'])->post("/stock-transfers/{$ctx['transfer']->id}/resolve-discrepancies", [
        'lines' => [['id' => $ctx['line']->id, 'resolution' => 'accepted']],
    ])->assertSessionHas('error');

    expect($ctx['line']->fresh()->resolution)->toBeNull()
        ->and((float) $ctx['source']->fresh()->stock_quantity)->toBe(1.0)
        ->and((float) $ctx['target']->fresh()->stock_quantity)->toBe(10.0);
});

it('menolak penyelesaian yang tidak sah', function (string $receivedAs, array $row) {
    $ctx = sigSetup();
    sigReceive($this, $ctx, $receivedAs === 'over'
        ? ['quantity_received' => 12]
        : ['quantity_received' => 8, 'discrepancy_type' => 'short', 'discrepancy_reason' => 'Kurang']);

    $this->actingAs($ctx['ckAdmin'])->post("/stock-transfers/{$ctx['transfer']->id}/resolve-discrepancies", [
        'lines' => [array_merge(['id' => $ctx['line']->id], $row)],
    ])->assertSessionHasErrors();

    expect($ctx['line']->fresh()->resolution)->toBeNull()
        ->and((float) $ctx['source']->fresh()->stock_quantity)->toBe(90.0);
})->with([
    'kurang diselesaikan dengan pilihan untuk lebih' => ['short', ['resolution' => 'accepted']],
    'lebih diselesaikan dengan pilihan untuk kurang' => ['over', ['resolution' => 'returned']],
    'pilihan tidak dikenal' => ['short', ['resolution' => 'dibuang']],
]);

it('selisih tidak bisa diselesaikan dua kali', function () {
    $ctx = sigSetup();
    sigReceive($this, $ctx, ['quantity_received' => 8, 'discrepancy_type' => 'short', 'discrepancy_reason' => 'Kurang']);
    $payload = ['lines' => [['id' => $ctx['line']->id, 'resolution' => 'returned']]];

    $this->actingAs($ctx['ckAdmin'])->post("/stock-transfers/{$ctx['transfer']->id}/resolve-discrepancies", $payload)->assertSessionHasNoErrors();
    $this->actingAs($ctx['ckAdmin'])->post("/stock-transfers/{$ctx['transfer']->id}/resolve-discrepancies", $payload)->assertSessionHasErrors('lines');

    expect((float) $ctx['source']->fresh()->stock_quantity)->toBe(92.0);
});

it('hanya lokasi pengirim yang boleh menyelesaikan selisih', function () {
    $ctx = sigSetup();
    sigReceive($this, $ctx, ['quantity_received' => 8, 'discrepancy_type' => 'short', 'discrepancy_reason' => 'Kurang']);

    $this->actingAs($ctx['outletAdmin'])->post("/stock-transfers/{$ctx['transfer']->id}/resolve-discrepancies", [
        'lines' => [['id' => $ctx['line']->id, 'resolution' => 'returned']],
    ])->assertForbidden();

    expect($ctx['line']->fresh()->resolution)->toBeNull()
        ->and((float) $ctx['source']->fresh()->stock_quantity)->toBe(90.0);
});

it('selisih belum bisa diselesaikan sebelum transfer diterima', function () {
    $ctx = sigSetup();

    $this->actingAs($ctx['ckAdmin'])->post("/stock-transfers/{$ctx['transfer']->id}/resolve-discrepancies", [
        'lines' => [['id' => $ctx['line']->id, 'resolution' => 'returned']],
    ])->assertSessionHasErrors('lines');
});

it('menampilkan selisih yang belum diputuskan hanya di sisi pengirim', function () {
    $ctx = sigSetup();
    sigReceive($this, $ctx, ['quantity_received' => 8, 'discrepancy_type' => 'short', 'discrepancy_reason' => 'Kurang']);

    $this->actingAs($ctx['ckAdmin'])->get('/stock-transfers?needs_resolution=1')->assertOk()->assertInertia(
        fn ($page) => $page->where('needsResolutionCount', 1)
            ->where('needsResolutionFilter', true)
            ->has('transfers.data', 1)
            ->where('transfers.data.0.open_discrepancy_lines_count', 1)
    );
    $this->actingAs($ctx['outletAdmin'])->get('/stock-transfers?needs_resolution=1')->assertOk()->assertInertia(
        fn ($page) => $page->where('needsResolutionCount', 0)->has('transfers.data', 0)
    );

    $this->actingAs($ctx['ckAdmin'])->get("/stock-transfers/{$ctx['transfer']->id}")->assertInertia(fn ($page) => $page->where('canResolve', true));
    $this->actingAs($ctx['outletAdmin'])->get("/stock-transfers/{$ctx['transfer']->id}")->assertInertia(fn ($page) => $page->where('canResolve', false));

    $this->actingAs($ctx['ckAdmin'])->post("/stock-transfers/{$ctx['transfer']->id}/resolve-discrepancies", [
        'lines' => [['id' => $ctx['line']->id, 'resolution' => 'loss']],
    ]);

    $this->actingAs($ctx['ckAdmin'])->get('/stock-transfers')->assertInertia(fn ($page) => $page->where('needsResolutionCount', 0));
    $this->actingAs($ctx['ckAdmin'])->get("/stock-transfers/{$ctx['transfer']->id}")->assertInertia(fn ($page) => $page->where('canResolve', false));
});

it('selisih lama tanpa jenis tidak dianggap menunggu keputusan', function () {
    $ctx = sigSetup();
    $ctx['transfer']->update(['status' => StockTransfer::STATUS_RECEIVED, 'received_at' => now(), 'received_by' => $ctx['outletAdmin']->id]);
    $ctx['line']->update(['quantity_received' => 8, 'discrepancy_reason' => 'Selisih lama']);

    $this->actingAs($ctx['ckAdmin'])->get('/stock-transfers?discrepancy=1')->assertInertia(
        fn ($page) => $page->where('needsResolutionCount', 0)
            ->has('transfers.data', 1)
            ->where('transfers.data.0.discrepancy_lines_count', 1)
            ->where('transfers.data.0.open_discrepancy_lines_count', 0)
    );
});
