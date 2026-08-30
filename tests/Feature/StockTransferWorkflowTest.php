<?php

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use App\Models\Store;
use App\Models\User;
use App\Support\InventoryCosting;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Test Tahap 4 — Transfer Gudang Berstatus.
 *
 * Karena CSRF 419 bersifat pre-existing di seluruh test POST proyek ini,
 * pengujian dilakukan dengan memanggil logika bisnis langsung melalui DB transaction.
 */
function makeTransferOrg(string $namePrefix): array
{
    $store = Store::query()->create([
        'name' => "{$namePrefix} Test",
        'slug' => 'transfer-test-'.uniqid(),
        'location_type' => StoreLocationType::OUTLET,
        'is_active' => true,
        'is_subscription_active' => true,
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    return [$store, $user];
}

function makeTransferProduct(Store $store, array $attrs = []): Product
{
    return Product::query()->create(array_merge([
        'store_id' => $store->id,
        'name' => 'Produk Transfer',
        'price' => 10000,
        'cost_price' => 5000,
        'average_cost' => 5000,
        'stock_quantity' => 0,
        'inventory_type' => 'raw_material',
        'base_unit' => 'gram',
        'unit' => 'gram',
        'track_stock' => true,
        'is_active' => true,
    ], $attrs));
}

/**
 * Replika logika StockTransferController::store untuk draft.
 */
function createDraftTransfer(
    User $user,
    Store $fromStore,
    Store $toStore,
    array $linesData,
): StockTransfer {
    $userId = $user->id;

    return DB::transaction(function () use ($userId, $fromStore, $toStore, $linesData) {
        $sortedFromIds = collect($linesData)->pluck('from_product_id')->sort()->values();
        $lockedFrom = Product::query()
            ->whereIn('id', $sortedFromIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $resolved = [];
        foreach ($linesData as $row) {
            $from = $lockedFrom->get($row['from_product_id']);
            $qty = \App\Support\ProductQuantity::normalizeLineQuantity($from, $row['quantity']);
            $to = Product::query()
                ->where('store_id', $toStore->id)
                ->where('sku', $from->sku)
                ->first();

            $resolved[] = ['from' => $from, 'to' => $to, 'qty' => $qty];
        }

        $transfer = StockTransfer::create([
            'from_store_id' => $fromStore->id,
            'to_store_id' => $toStore->id,
            'user_id' => $userId,
            'status' => StockTransfer::STATUS_DRAFT,
        ]);

        foreach ($resolved as $r) {
            $averageCost = InventoryCosting::currentAverageCost($r['from']);
            $totalCost = InventoryCosting::extendedCost($r['qty'], $averageCost, 2);
            StockTransferLine::create([
                'stock_transfer_id' => $transfer->id,
                'from_product_id' => $r['from']->id,
                'to_product_id' => $r['to']->id,
                'quantity' => $r['qty'],
                'base_unit' => $r['from']->baseUnit(),
                'unit_cost_snapshot' => $averageCost,
                'total_cost_snapshot' => $totalCost,
            ]);
        }

        return $transfer;
    });
}

/**
 * Replika logika send.
 */
function executeSendTransfer(User $user, StockTransfer $transfer): void
{
    DB::transaction(function () use ($user, $transfer) {
        $transfer->lockForUpdate();
        $transfer->assertCanTransitionTo(StockTransfer::STATUS_SENT);

        $fromStore = $transfer->fromStore;
        $lines = $transfer->lines()->with('fromProduct')->get();

        $fromIds = $lines->pluck('from_product_id')->sort()->values();
        Product::query()->whereIn('id', $fromIds)->orderBy('id')->lockForUpdate()->get();

        foreach ($lines as $line) {
            $product = $line->fromProduct;
            $product->refresh();
            $qty = (string) $line->quantity;

            if (\App\Support\ProductQuantity::lt((string) $product->stock_quantity, $qty)) {
                throw new \RuntimeException("Stok «{$product->name}» tidak mencukupi.");
            }

            $averageCost = InventoryCosting::currentAverageCost($product);
            $totalCost = InventoryCosting::extendedCost($qty, $averageCost, 2);
            $line->update(['unit_cost_snapshot' => $averageCost, 'total_cost_snapshot' => $totalCost]);

            \App\Support\TransferStockOperations::deductAtSource($fromStore, $user, $product, $qty, $line);
        }

        $transfer->update([
            'status' => StockTransfer::STATUS_SENT,
            'sent_at' => now(),
            'sent_by' => $user->id,
        ]);
    });
}

/**
 * Replika logika receive.
 */
function executeReceiveTransfer(User $user, StockTransfer $transfer): void
{
    DB::transaction(function () use ($user, $transfer) {
        $transfer->lockForUpdate();
        $transfer->assertCanTransitionTo(StockTransfer::STATUS_RECEIVED);

        $toStore = $transfer->toStore;
        $lines = $transfer->lines()->with('toProduct')->get();

        $toIds = $lines->pluck('to_product_id')->sort()->values();
        Product::query()->whereIn('id', $toIds)->orderBy('id')->lockForUpdate()->get();

        foreach ($lines as $line) {
            $product = $line->toProduct;
            $product->refresh();
            $qty = (string) $line->quantity;
            $unitCostSource = (string) ($line->unit_cost_snapshot ?? InventoryCosting::currentAverageCost($product));

            \App\Support\TransferStockOperations::addAtDestination($toStore, $user, $product, $qty, $line);

            $product->refresh();
            $newAverageCost = InventoryCosting::weightedAverage(
                bcsub((string) $product->stock_quantity, $qty, 4),
                InventoryCosting::currentAverageCost($product),
                $qty,
                $unitCostSource,
            );
            $product->update([
                'average_cost' => $newAverageCost,
                'cost_price' => round((float) $newAverageCost, 2),
            ]);
        }

        $transfer->update([
            'status' => StockTransfer::STATUS_RECEIVED,
            'received_at' => now(),
            'received_by' => $user->id,
        ]);
    });
}

/**
 * Replika logika cancel.
 */
function executeCancelTransfer(User $user, StockTransfer $transfer, string $reason): void
{
    DB::transaction(function () use ($user, $transfer, $reason) {
        $transfer->lockForUpdate();
        $transfer->assertCanTransitionTo(StockTransfer::STATUS_CANCELLED);

        if ($transfer->isDraft()) {
            $transfer->update([
                'status' => StockTransfer::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'cancellation_reason' => $reason,
            ]);

            return;
        }

        $fromStore = $transfer->fromStore;
        $lines = $transfer->lines()->with('fromProduct')->get();
        $fromIds = $lines->pluck('from_product_id')->sort()->values();
        Product::query()->whereIn('id', $fromIds)->orderBy('id')->lockForUpdate()->get();

        foreach ($lines as $line) {
            $product = $line->fromProduct;
            $product->refresh();
            \App\Support\TransferStockOperations::restoreAtSource($fromStore, $user, $product, (string) $line->quantity, $line);
        }

        $transfer->update([
            'status' => StockTransfer::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by' => $user->id,
            'cancellation_reason' => $reason,
        ]);
    });
}

// ═══════════════════════════════════════════════════════
// Tests
// ═══════════════════════════════════════════════════════

test('draft tidak mengubah stok', function () {
    [$fromStore, $fromUser] = makeTransferOrg('Gudang A');
    [$toStore, $toUser] = makeTransferOrg('Outlet B');

    // Assign $toUser ke $toStore agar relasi user->store benar.
    $toUser->update(['store_id' => $toStore->id]);

    $org = \App\Models\Organization::query()->create(['name' => 'Grup Test', 'slug' => 'grup-test-'.uniqid()]);
    $fromStore->update(['organization_id' => $org->id]);
    $toStore->update(['organization_id' => $org->id]);

    $product = makeTransferProduct($fromStore, ['stock_quantity' => '500.0000']);
    $destProduct = makeTransferProduct($toStore, ['name' => 'Produk B', 'sku' => $product->sku, 'stock_quantity' => '0.0000']);

    $transfer = createDraftTransfer($fromUser, $fromStore, $toStore, [
        ['from_product_id' => $product->id, 'quantity' => 100],
    ]);

    $product->refresh();
    $destProduct->refresh();

    expect($transfer->status)->toBe(StockTransfer::STATUS_DRAFT)
        ->and($product->stock_quantity)->toBe('500.0000')
        ->and($destProduct->stock_quantity)->toBe('0.0000')
        ->and(StockMovement::where('type', StockMovement::TYPE_TRANSFER_OUT)->count())->toBe(0)
        ->and(StockMovement::where('type', StockMovement::TYPE_TRANSFER_IN)->count())->toBe(0);
});

test('kirim hanya mengurangi stok sumber', function () {
    [$fromStore, $fromUser] = makeTransferOrg('Gudang A');
    [$toStore, $toUser] = makeTransferOrg('Outlet B');
    $toUser->update(['store_id' => $toStore->id]);

    $org = \App\Models\Organization::query()->create(['name' => 'Grup Test 2', 'slug' => 'grup-test-2-'.uniqid()]);
    $fromStore->update(['organization_id' => $org->id]);
    $toStore->update(['organization_id' => $org->id]);

    $product = makeTransferProduct($fromStore, ['stock_quantity' => '500.0000']);
    $destProduct = makeTransferProduct($toStore, ['name' => 'Produk B', 'sku' => $product->sku, 'stock_quantity' => '0.0000']);

    $transfer = createDraftTransfer($fromUser, $fromStore, $toStore, [
        ['from_product_id' => $product->id, 'quantity' => 100],
    ]);
    executeSendTransfer($fromUser, $transfer);

    $product->refresh();
    $destProduct->refresh();

    expect($transfer->fresh()->status)->toBe(StockTransfer::STATUS_SENT)
        ->and($product->stock_quantity)->toBe('400.0000')
        ->and($destProduct->stock_quantity)->toBe('0.0000')
        ->and(StockMovement::where('type', StockMovement::TYPE_TRANSFER_OUT)->count())->toBe(1);
});

test('terima menambah stok tujuan dan update weighted average', function () {
    [$fromStore, $fromUser] = makeTransferOrg('Gudang C');
    [$toStore, $toUser] = makeTransferOrg('Outlet D');
    $toUser->update(['store_id' => $toStore->id]);

    $org = \App\Models\Organization::query()->create(['name' => 'Grup Test 3', 'slug' => 'grup-test-3-'.uniqid()]);
    $fromStore->update(['organization_id' => $org->id]);
    $toStore->update(['organization_id' => $org->id]);

    $product = makeTransferProduct($fromStore, [
        'stock_quantity' => '500.0000',
        'average_cost' => '10.000000',
        'cost_price' => 10,
    ]);
    $destProduct = makeTransferProduct($toStore, [
        'name' => 'Produk B',
        'sku' => $product->sku,
        'stock_quantity' => '100.0000',
        'average_cost' => '20.000000',
        'cost_price' => 20,
    ]);

    $transfer = createDraftTransfer($fromUser, $fromStore, $toStore, [
        ['from_product_id' => $product->id, 'quantity' => 100],
    ]);
    executeSendTransfer($fromUser, $transfer);
    executeReceiveTransfer($toUser, $transfer);

    $destProduct->refresh();

    expect($transfer->fresh()->status)->toBe(StockTransfer::STATUS_RECEIVED)
        ->and($destProduct->stock_quantity)->toBe('200.0000')
        ->and(StockMovement::where('type', StockMovement::TYPE_TRANSFER_IN)->count())->toBe(1);
});

test('pembatalan draft tanpa mutasi stok', function () {
    [$fromStore, $fromUser] = makeTransferOrg('Gudang E');
    [$toStore, $toUser] = makeTransferOrg('Outlet F');
    $toUser->update(['store_id' => $toStore->id]);

    $org = \App\Models\Organization::query()->create(['name' => 'Grup Test 4', 'slug' => 'grup-test-4-'.uniqid()]);
    $fromStore->update(['organization_id' => $org->id]);
    $toStore->update(['organization_id' => $org->id]);

    $product = makeTransferProduct($fromStore, ['stock_quantity' => '500.0000']);
    $destProduct = makeTransferProduct($toStore, ['name' => 'Produk B', 'sku' => $product->sku]);

    $transfer = createDraftTransfer($fromUser, $fromStore, $toStore, [
        ['from_product_id' => $product->id, 'quantity' => 100],
    ]);
    executeCancelTransfer($fromUser, $transfer, 'Tidak jadi');

    $product->refresh();
    $destProduct->refresh();

    expect($transfer->fresh()->status)->toBe(StockTransfer::STATUS_CANCELLED)
        ->and($transfer->fresh()->cancellation_reason)->toBe('Tidak jadi')
        ->and($product->stock_quantity)->toBe('500.0000')
        ->and($destProduct->stock_quantity)->toBe('0.0000')
        ->and(StockMovement::count())->toBe(0);
});

test('pembatalan sent mengembalikan stok sumber', function () {
    [$fromStore, $fromUser] = makeTransferOrg('Gudang G');
    [$toStore, $toUser] = makeTransferOrg('Outlet H');
    $toUser->update(['store_id' => $toStore->id]);

    $org = \App\Models\Organization::query()->create(['name' => 'Grup Test 5', 'slug' => 'grup-test-5-'.uniqid()]);
    $fromStore->update(['organization_id' => $org->id]);
    $toStore->update(['organization_id' => $org->id]);

    $product = makeTransferProduct($fromStore, ['stock_quantity' => '500.0000']);
    $destProduct = makeTransferProduct($toStore, ['name' => 'Produk B', 'sku' => $product->sku]);

    $transfer = createDraftTransfer($fromUser, $fromStore, $toStore, [
        ['from_product_id' => $product->id, 'quantity' => 100],
    ]);
    executeSendTransfer($fromUser, $transfer);

    $product->refresh();
    expect($product->stock_quantity)->toBe('400.0000');

    executeCancelTransfer($fromUser, $transfer, 'Salah kirim');

    $product->refresh();

    expect($transfer->fresh()->status)->toBe(StockTransfer::STATUS_CANCELLED)
        ->and($transfer->fresh()->cancellation_reason)->toBe('Salah kirim')
        ->and($product->stock_quantity)->toBe('500.0000')
        ->and(StockMovement::where('type', StockMovement::TYPE_TRANSFER_REVERSAL)->count())->toBe(1);
});

test('transfer Pharmacy mempertahankan batch dan ED saat terima dan batal', function () {
    [$fromStore, $fromUser] = makeTransferOrg('Apotek Asal');
    [$toStore, $toUser] = makeTransferOrg('Apotek Tujuan');
    $fromStore->update(['business_type' => 'pharmacy', 'settings' => ['batch_tracking_enabled' => true]]);
    $toStore->update(['business_type' => 'pharmacy', 'settings' => ['batch_tracking_enabled' => true]]);
    $org = \App\Models\Organization::query()->create(['name' => 'Grup Apotek', 'slug' => 'grup-apotek-'.uniqid()]);
    $fromStore->update(['organization_id' => $org->id]);
    $toStore->update(['organization_id' => $org->id]);
    $product = makeTransferProduct($fromStore, ['stock_quantity' => '5.0000']);
    $destination = makeTransferProduct($toStore, ['name' => 'Produk Tujuan', 'sku' => $product->sku]);
    ProductBatch::create(['store_id' => $fromStore->id, 'product_id' => $product->id, 'quantity_remaining' => '2.0000', 'batch_code' => 'A-01', 'expiry_date' => now()->addMonth()]);
    ProductBatch::create(['store_id' => $fromStore->id, 'product_id' => $product->id, 'quantity_remaining' => '3.0000', 'batch_code' => 'B-02', 'expiry_date' => now()->addMonths(2)]);
    $transfer = createDraftTransfer($fromUser, $fromStore, $toStore, [['from_product_id' => $product->id, 'quantity' => 4]]);

    executeSendTransfer($fromUser, $transfer);
    expect($transfer->lines()->first()->batchAllocations()->get()->map(fn ($b) => [$b->batch_code, $b->expiry_date->toDateString(), $b->quantity])->all())
        ->toBe([['A-01', now()->addMonth()->toDateString(), '2.0000'], ['B-02', now()->addMonths(2)->toDateString(), '2.0000']]);
    executeReceiveTransfer($toUser, $transfer);
    expect(ProductBatch::where('product_id', $destination->id)->orderBy('batch_code')->get()->map(fn ($b) => [$b->batch_code, $b->expiry_date->toDateString(), $b->quantity_remaining])->all())
        ->toBe([['A-01', now()->addMonth()->toDateString(), '2.0000'], ['B-02', now()->addMonths(2)->toDateString(), '2.0000']]);

    $cancelTransfer = createDraftTransfer($fromUser, $fromStore, $toStore, [['from_product_id' => $product->id, 'quantity' => 1]]);
    executeSendTransfer($fromUser, $cancelTransfer);
    executeCancelTransfer($fromUser, $cancelTransfer, 'Salah kirim');
    expect(ProductBatch::where('product_id', $product->id)->where('quantity_remaining', '>', 0)->orderBy('batch_code')->get()->map(fn ($b) => [$b->batch_code, $b->expiry_date->toDateString(), $b->quantity_remaining])->all())
        ->toBe([['B-02', now()->addMonths(2)->toDateString(), '1.0000']]);
});

test('transfer Pharmacy menolak batch sumber tanpa ED', function () {
    [$fromStore, $fromUser] = makeTransferOrg('Apotek ED Asal');
    [$toStore] = makeTransferOrg('Apotek ED Tujuan');
    $fromStore->update(['business_type' => 'pharmacy']);
    $toStore->update(['business_type' => 'pharmacy']);
    $product = makeTransferProduct($fromStore, ['stock_quantity' => '1.0000']);
    $destination = makeTransferProduct($toStore, ['name' => 'Produk Tujuan', 'sku' => $product->sku]);
    ProductBatch::create(['store_id' => $fromStore->id, 'product_id' => $product->id, 'quantity_remaining' => '1.0000', 'batch_code' => 'TANPA-ED']);
    $transfer = createDraftTransfer($fromUser, $fromStore, $toStore, [['from_product_id' => $product->id, 'quantity' => 1]]);

    expect(fn () => executeSendTransfer($fromUser, $transfer))
        ->toThrow(\RuntimeException::class, 'tanpa ED tidak dapat ditransfer');

    expect($product->fresh()->stock_quantity)->toBe('1.0000')
        ->and(ProductBatch::where('product_id', $product->id)->value('quantity_remaining'))->toBe('1.0000');
});

test('tujuan Pharmacy menolak alokasi batch kosong atau tanpa ED', function () {
    [$fromStore, $fromUser] = makeTransferOrg('Apotek Alokasi Asal');
    [$toStore, $toUser] = makeTransferOrg('Apotek Alokasi Tujuan');
    $fromStore->update(['business_type' => 'pharmacy']);
    $toStore->update(['business_type' => 'pharmacy']);
    $product = makeTransferProduct($fromStore, ['stock_quantity' => '1.0000']);
    $destination = makeTransferProduct($toStore, ['name' => 'Produk Tujuan', 'sku' => $product->sku]);
    $transfer = createDraftTransfer($fromUser, $fromStore, $toStore, [['from_product_id' => $product->id, 'quantity' => 1]]);
    $line = $transfer->lines()->sole();

    expect(fn () => \App\Support\TransferStockOperations::addAtDestination($toStore, $toUser, $destination, '1.0000', $line))
        ->toThrow(\RuntimeException::class, 'wajib ada dan memiliki ED');

    $line->batchAllocations()->create(['batch_code' => 'TANPA-ED', 'quantity' => '1.0000']);

    expect(fn () => \App\Support\TransferStockOperations::addAtDestination($toStore, $toUser, $destination, '1.0000', $line))
        ->toThrow(\RuntimeException::class, 'wajib ada dan memiliki ED');

    expect($destination->fresh()->stock_quantity)->toBe('0.0000')
        ->and(ProductBatch::where('product_id', $destination->id)->count())->toBe(0);
});

test('tujuan non-Pharmacy tetap menerima transfer batch tanpa alokasi', function () {
    [$fromStore, $fromUser] = makeTransferOrg('Retail Alokasi Asal');
    [$toStore, $toUser] = makeTransferOrg('Retail Alokasi Tujuan');
    $toStore->update(['settings' => ['batch_tracking_enabled' => true]]);
    $product = makeTransferProduct($fromStore, ['stock_quantity' => '1.0000']);
    $destination = makeTransferProduct($toStore, ['name' => 'Produk Tujuan', 'sku' => $product->sku]);
    $transfer = createDraftTransfer($fromUser, $fromStore, $toStore, [['from_product_id' => $product->id, 'quantity' => 1]]);

    \App\Support\TransferStockOperations::addAtDestination($toStore, $toUser, $destination, '1.0000', $transfer->lines()->sole());

    expect($destination->fresh()->stock_quantity)->toBe('1.0000')
        ->and(ProductBatch::where('product_id', $destination->id)->value('expiry_date'))->toBeNull();
});

test('status final tidak dapat diproses ulang', function () {
    [$fromStore, $fromUser] = makeTransferOrg('Gudang I');
    [$toStore, $toUser] = makeTransferOrg('Outlet J');
    $toUser->update(['store_id' => $toStore->id]);

    $org = \App\Models\Organization::query()->create(['name' => 'Grup Test 6', 'slug' => 'grup-test-6-'.uniqid()]);
    $fromStore->update(['organization_id' => $org->id]);
    $toStore->update(['organization_id' => $org->id]);

    $product = makeTransferProduct($fromStore, ['stock_quantity' => '500.0000']);
    $destProduct = makeTransferProduct($toStore, ['name' => 'Produk B', 'sku' => $product->sku]);

    $transfer = createDraftTransfer($fromUser, $fromStore, $toStore, [
        ['from_product_id' => $product->id, 'quantity' => 100],
    ]);
    executeSendTransfer($fromUser, $transfer);
    executeReceiveTransfer($toUser, $transfer);

    $this->expectException(\RuntimeException::class);
    executeSendTransfer($fromUser, $transfer->fresh());
});

test('stok tidak cukup saat dikirim', function () {
    [$fromStore, $fromUser] = makeTransferOrg('Gudang K');
    [$toStore, $toUser] = makeTransferOrg('Outlet L');
    $toUser->update(['store_id' => $toStore->id]);

    $org = \App\Models\Organization::query()->create(['name' => 'Grup Test 7', 'slug' => 'grup-test-7-'.uniqid()]);
    $fromStore->update(['organization_id' => $org->id]);
    $toStore->update(['organization_id' => $org->id]);

    $product = makeTransferProduct($fromStore, ['stock_quantity' => '50.0000']);
    $destProduct = makeTransferProduct($toStore, ['name' => 'Produk B', 'sku' => $product->sku]);

    $transfer = createDraftTransfer($fromUser, $fromStore, $toStore, [
        ['from_product_id' => $product->id, 'quantity' => 100],
    ]);

    $this->expectException(\RuntimeException::class);
    executeSendTransfer($fromUser, $transfer);
});

test('state machine menolak transisi tidak valid', function () {
    [$fromStore, $fromUser] = makeTransferOrg('Gudang M');
    [$toStore, $toUser] = makeTransferOrg('Outlet N');
    $toUser->update(['store_id' => $toStore->id]);

    $org = \App\Models\Organization::query()->create(['name' => 'Grup Test 8', 'slug' => 'grup-test-8-'.uniqid()]);
    $fromStore->update(['organization_id' => $org->id]);
    $toStore->update(['organization_id' => $org->id]);

    $product = makeTransferProduct($fromStore, ['stock_quantity' => '500.0000']);
    $destProduct = makeTransferProduct($toStore, ['name' => 'Produk B', 'sku' => $product->sku]);

    $transfer = createDraftTransfer($fromUser, $fromStore, $toStore, [
        ['from_product_id' => $product->id, 'quantity' => 100],
    ]);

    // Draft tidak bisa langsung ke received
    $this->expectException(\RuntimeException::class);
    $transfer->assertCanTransitionTo(StockTransfer::STATUS_RECEIVED);
});

test('snapshot biaya tersimpan pada baris transfer', function () {
    [$fromStore, $fromUser] = makeTransferOrg('Gudang O');
    [$toStore, $toUser] = makeTransferOrg('Outlet P');
    $toUser->update(['store_id' => $toStore->id]);

    $org = \App\Models\Organization::query()->create(['name' => 'Grup Test 9', 'slug' => 'grup-test-9-'.uniqid()]);
    $fromStore->update(['organization_id' => $org->id]);
    $toStore->update(['organization_id' => $org->id]);

    $product = makeTransferProduct($fromStore, [
        'stock_quantity' => '500.0000',
        'average_cost' => '8.500000',
        'cost_price' => 8.5,
    ]);
    $destProduct = makeTransferProduct($toStore, ['name' => 'Produk B', 'sku' => $product->sku]);

    $transfer = createDraftTransfer($fromUser, $fromStore, $toStore, [
        ['from_product_id' => $product->id, 'quantity' => 200],
    ]);

    $line = $transfer->lines()->sole();

    expect($line->base_unit)->toBe('gram')
        ->and($line->unit_cost_snapshot)->toBe('8.500000')
        ->and($line->total_cost_snapshot)->not->toBeNull();
});

test('transfer existing otomatis menjadi received', function () {
    // Transfer existing tidak punya status terisi → harus di-backfill.
    $org = \App\Models\Organization::query()->create(['name' => 'Grup Test 10', 'slug' => 'grup-test-10-'.uniqid()]);
    [$fromStore] = makeTransferOrg('Gudang Old');
    [$toStore] = makeTransferOrg('Outlet Old');
    $fromStore->update(['organization_id' => $org->id]);
    $toStore->update(['organization_id' => $org->id]);

    $oldTransfer = StockTransfer::create([
        'transfer_number' => 'TRF-OLD-00001',
        'from_store_id' => $fromStore->id,
        'to_store_id' => $toStore->id,
        'status' => StockTransfer::STATUS_RECEIVED,
        'received_at' => now(),
    ]);

    expect($oldTransfer->status)->toBe(StockTransfer::STATUS_RECEIVED)
        ->and($oldTransfer->isFinal())->toBeTrue();
});
