<?php

use App\Models\Product;
use App\Models\PurchaseLine;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use App\Support\BaseUnit;
use App\Support\InventoryCosting;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Test Tahap 3 — Pembelian Gudang Pusat.
 *
 * Karena CSRF 419 bersifat pre-existing di seluruh test POST proyek ini,
 * pengujian dilakukan dengan memanggil logika bisnis langsung melalui DB transaction.
 */
function makePurchaseStore(string $locationType = StoreLocationType::CENTRAL_WAREHOUSE): array
{
    $store = Store::query()->create([
        'name' => $locationType === StoreLocationType::CENTRAL_WAREHOUSE ? 'Gudang Pusat Test' : 'Outlet Test',
        'slug' => 'lokasi-pembelian-'.uniqid(),
        'location_type' => $locationType,
        'is_active' => true,
        'is_subscription_active' => true,
        'subscription_end' => null,
        // Sebagian tes di file ini menguji posting jurnal pembelian → modul aktif.
        'settings' => ['accounting_enabled' => true],
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    return [$store, $user];
}

function makePurchaseProduct(Store $store, array $attributes = []): Product
{
    return Product::query()->create(array_merge([
        'store_id' => $store->id,
        'name' => 'Bahan Test',
        'price' => 0,
        'cost_price' => 0,
        'average_cost' => 0,
        'stock_quantity' => 0,
        'inventory_type' => 'raw_material',
        'base_unit' => 'gram',
        'unit' => 'gram',
        'track_stock' => true,
        'is_active' => true,
    ], $attributes));
}

/**
 * Replika logika PurchaseController::store untuk pengujian terfokus.
 * Mengembalikan Purchase yang sudah dibuat atau melempar RuntimeException.
 */
function executeCentralPurchase(Store $store, User $user, array $linesData, ?string $ppnAmount = null): \App\Models\Purchase
{
    $userId = $user->id;

    return DB::transaction(function () use ($store, $userId, $linesData, $ppnAmount) {
        $productIds = collect($linesData)
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values();

        $products = Product::query()
            ->where('store_id', $store->id)
            ->whereIn('id', $productIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($products->count() !== $productIds->count()) {
            throw new \RuntimeException('Produk tidak ditemukan.');
        }

        foreach ($products as $product) {
            if (! $product->is_active) {
                throw new \RuntimeException("Produk «{$product->name}» tidak aktif.");
            }
            if (! $product->track_stock || $product->is_bundle) {
                throw new \RuntimeException("Produk «{$product->name}» tidak melacak stok.");
            }
        }

        $purchase = \App\Models\Purchase::create([
            'store_id' => $store->id,
            'user_id' => $userId,
            'purchased_at' => now(),
        ]);

        $dppCalc = '0.00';
        $sortedLines = collect($linesData)->sortBy(fn ($row) => sprintf(
            '%020d:%020d',
            (int) $row['product_id'],
            (int) ($row['product_variant_id'] ?? 0),
        ));

        foreach ($sortedLines as $row) {
            $product = $products->get((int) $row['product_id']);
            $inputUnit = mb_strtolower(trim((string) $row['input_unit']));
            $baseUnit = $product->baseUnit();

            $qtyBase = BaseUnit::toBaseQuantity($row['quantity'], $inputUnit, $baseUnit);
            if (bccomp($qtyBase, '0', 4) <= 0) {
                throw new \RuntimeException('Jumlah dasar harus lebih dari 0.');
            }

            $quantityInput = number_format(round((float) $row['quantity'], 4), 4, '.', '');
            $unitCostInput = null;
            $unitCostBase = null;
            $subtotal = null;

            if (array_key_exists('unit_cost', $row) && $row['unit_cost'] !== null) {
                $unitCostInput = number_format(round((float) $row['unit_cost'], 2), 2, '.', '');
                $unitCostBase = BaseUnit::toBaseUnitCost($unitCostInput, $inputUnit, $baseUnit);
                $subtotal = InventoryCosting::extendedCost($qtyBase, $unitCostBase, 2);
                $dppCalc = bcadd($dppCalc, $subtotal, 2);

                $averageCost = InventoryCosting::averageCostAfterInbound($product, $qtyBase, $unitCostBase);
                $baseCostRounded = round((float) $unitCostBase, 2);
                $product->average_cost = $averageCost;
                $product->cost_price = $baseCostRounded;
                $product->save();
            }

            PurchaseLine::create([
                'purchase_id' => $purchase->id,
                'product_id' => $product->id,
                'quantity' => $qtyBase,
                'unit_cost' => $unitCostBase === null ? null : round((float) $unitCostBase, 2),
                'input_unit' => $inputUnit,
                'quantity_input' => $quantityInput,
                'base_unit' => $baseUnit,
                'qty_base' => $qtyBase,
                'unit_cost_input' => $unitCostInput,
                'unit_cost_base' => $unitCostBase,
                'subtotal' => $subtotal,
            ]);

            $product->increment('stock_quantity', $qtyBase);
            $product->refresh();

            StockMovement::create([
                'store_id' => $store->id,
                'product_id' => $product->id,
                'user_id' => $userId,
                'quantity_change' => $qtyBase,
                'type' => StockMovement::TYPE_PURCHASE,
                'reference_type' => \App\Models\Purchase::class,
                'reference_id' => $purchase->id,
                'balance_after' => (string) $product->stock_quantity,
                'notes' => $purchase->purchase_number,
            ]);
        }

        $ppn = number_format(round((float) ($ppnAmount ?? 0), 2), 2, '.', '');
        $purchase->update([
            'dpp_amount' => $dppCalc,
            'ppn_amount' => $ppn,
            'total_amount' => bcadd($dppCalc, $ppn, 2),
        ]);

        return $purchase;
    });
}

test('pembelian gudang mengonversi satuan kg ke gram dan menyimpan snapshot biaya', function () {
    [$store, $user] = makePurchaseStore();
    $product = makePurchaseProduct($store, ['name' => 'Biji Kopi']);

    $purchase = executeCentralPurchase($store, $user, [[
        'product_id' => $product->id,
        'quantity' => 5,
        'input_unit' => 'kg',
        'unit_cost' => 20000,
    ]]);

    $line = PurchaseLine::query()->sole();
    $product->refresh();
    $movement = StockMovement::query()->where('type', StockMovement::TYPE_PURCHASE)->sole();

    expect($line->input_unit)->toBe('kg')
        ->and($line->quantity_input)->toBe('5.0000')
        ->and($line->base_unit)->toBe('gram')
        ->and($line->qty_base)->toBe('5000.0000')
        ->and($line->unit_cost_input)->toBe('20000.00')
        ->and($line->unit_cost_base)->toBe('20.000000')
        ->and($line->subtotal)->toBe('100000.00')
        ->and($line->quantity)->toBe('5000.0000')
        ->and($product->stock_quantity)->toBe('5000.0000')
        ->and($product->average_cost)->toBe('20.000000')
        ->and($product->cost_price)->toBe('20.00')
        ->and($movement->quantity_change)->toBe('5000.0000')
        ->and($movement->balance_after)->toBe('5000.0000')
        ->and($purchase->dpp_amount)->toBe('100000.00')
        ->and($purchase->total_amount)->toBe('100000.00');
});

test('pembelian volume 18 liter menghasilkan 18000 ml', function () {
    [$store, $user] = makePurchaseStore();
    $product = makePurchaseProduct($store, [
        'name' => 'Sirup',
        'base_unit' => 'ml',
        'unit' => 'ml',
    ]);

    executeCentralPurchase($store, $user, [[
        'product_id' => $product->id,
        'quantity' => 18,
        'input_unit' => 'liter',
        'unit_cost' => 25000,
    ]]);

    $line = PurchaseLine::query()->sole();
    $product->refresh();

    expect($line->qty_base)->toBe('18000.0000')
        ->and($line->unit_cost_base)->toBe('25.000000')
        ->and($line->subtotal)->toBe('450000.00')
        ->and($product->stock_quantity)->toBe('18000.0000')
        ->and($product->average_cost)->toBe('25.000000');
});

test('pembelian menghitung average cost dengan metode weighted average', function () {
    [$store, $user] = makePurchaseStore();
    $product = makePurchaseProduct($store, [
        'stock_quantity' => 100,
        'cost_price' => 10,
        'average_cost' => 10,
    ]);

    executeCentralPurchase($store, $user, [[
        'product_id' => $product->id,
        'quantity' => 100,
        'input_unit' => 'gram',
        'unit_cost' => 20,
    ]]);

    $product->refresh();

    expect($product->stock_quantity)->toBe('200.0000')
        ->and($product->average_cost)->toBe('15.000000');
});

test('outlet tidak dapat menjalankan pembelian gudang', function () {
    [$store, $user] = makePurchaseStore(StoreLocationType::OUTLET);

    expect($store->isCentralWarehouse())->toBeFalse();
    expect(StoreLocationType::isCentralWarehouse($store))->toBeFalse();
});

test('satuan lintas dimensi ditolak', function () {
    [$store, $user] = makePurchaseStore();
    $product = makePurchaseProduct($store, [
        'name' => 'Sirup',
        'base_unit' => 'ml',
        'unit' => 'ml',
    ]);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('tidak sedimensi');

    // kg (weight) ke ml (volume) — lintas dimensi
    executeCentralPurchase($store, $user, [[
        'product_id' => $product->id,
        'quantity' => 1,
        'input_unit' => 'kg',
        'unit_cost' => 10000,
    ]]);
});

test('kegagalan satu baris membatalkan seluruh transaksi', function () {
    [$store, $user] = makePurchaseStore();
    $validProduct = makePurchaseProduct($store, ['name' => 'Kopi']);
    $invalidProduct = makePurchaseProduct($store, [
        'name' => 'Sirup',
        'base_unit' => 'ml',
        'unit' => 'ml',
    ]);

    try {
        executeCentralPurchase($store, $user, [
            [
                'product_id' => $validProduct->id,
                'quantity' => 1,
                'input_unit' => 'kg',
                'unit_cost' => 10000,
            ],
            [
                'product_id' => $invalidProduct->id,
                'quantity' => 1,
                'input_unit' => 'kg',
                'unit_cost' => 10000,
            ],
        ]);
        $this->fail('Seharusnya melempar exception.');
    } catch (\InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('tidak sedimensi');
    }

    expect(\App\Models\Purchase::query()->count())->toBe(0)
        ->and(PurchaseLine::query()->count())->toBe(0)
        ->and(StockMovement::query()->count())->toBe(0)
        ->and($validProduct->fresh()->stock_quantity)->toBe('0.0000')
        ->and($validProduct->fresh()->average_cost)->toBe('0.000000');
});

test('perubahan harga master tidak mengubah histori pembelian', function () {
    [$store, $user] = makePurchaseStore();
    $product = makePurchaseProduct($store, ['name' => 'Gula']);

    executeCentralPurchase($store, $user, [[
        'product_id' => $product->id,
        'quantity' => 5,
        'input_unit' => 'kg',
        'unit_cost' => 15000,
    ]]);

    $originalLine = PurchaseLine::query()->sole();
    $originalSubtotal = $originalLine->subtotal;
    $originalUnitCostBase = $originalLine->unit_cost_base;

    // Simulasi perubahan harga master produk
    $product->update(['cost_price' => 99, 'average_cost' => 99]);

    $originalLine->refresh();

    expect($originalLine->unit_cost_base)->toBe($originalUnitCostBase)
        ->and($originalLine->subtotal)->toBe($originalSubtotal);
});

test('stock_movement memakai kuantitas dasar', function () {
    [$store, $user] = makePurchaseStore();
    $product = makePurchaseProduct($store);

    executeCentralPurchase($store, $user, [[
        'product_id' => $product->id,
        'quantity' => 2,
        'input_unit' => 'kg',
        'unit_cost' => 10000,
    ]]);

    $movement = StockMovement::query()->where('type', StockMovement::TYPE_PURCHASE)->sole();

    expect($movement->quantity_change)->toBe('2000.0000')
        ->and($movement->balance_after)->toBe('2000.0000')
        ->and($movement->store_id)->toBe($store->id)
        ->and($movement->user_id)->toBe($user->id);
});

/**
 * Replika logika PurchaseController::store yang mendukung new_product resolution.
 */
function executePurchaseWithManual(Store $store, User $user, array $linesData): \App\Models\Purchase
{
    $userId = $user->id;

    return DB::transaction(function () use ($store, $userId, $linesData) {
        $resolved = [];
        foreach ($linesData as $row) {
            if (! empty($row['new_product']['name'])) {
                $np = $row['new_product'];
                $existing = Product::query()
                    ->where('store_id', $store->id)
                    ->where('name', $np['name'])
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    $row['product_id'] = $existing->id;
                } else {
                    $product = $store->products()->create([
                        'name' => $np['name'],
                        'sku' => $np['sku'] ?? null,
                        'barcode' => $np['barcode'] ?? null,
                        'inventory_type' => \App\Support\InventoryType::normalize($np['inventory_type'] ?? null),
                        'base_unit' => \App\Support\BaseUnit::normalizeBaseUnit($np['base_unit'] ?? null),
                        'unit' => $np['unit'] ?? 'pcs',
                        'price' => 0,
                        'cost_price' => 0,
                        'average_cost' => 0,
                        'stock_quantity' => 0,
                        'min_stock_level' => 0,
                        'track_stock' => true,
                        'allows_decimal' => (bool) ($np['allows_decimal'] ?? false),
                        'has_variants' => false,
                        'is_bundle' => false,
                        'is_active' => true,
                    ]);
                    $row['product_id'] = $product->id;
                }
            }
            $resolved[] = $row;
        }

        $productIds = collect($resolved)
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values();

        $products = Product::query()
            ->where('store_id', $store->id)
            ->whereIn('id', $productIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $purchase = \App\Models\Purchase::create([
            'store_id' => $store->id,
            'user_id' => $userId,
            'purchased_at' => now(),
        ]);

        $dppCalc = '0.00';
        $sortedLines = collect($resolved)->sortBy(fn ($row) => sprintf(
            '%020d:%020d',
            (int) $row['product_id'],
            (int) ($row['product_variant_id'] ?? 0),
        ));

        foreach ($sortedLines as $row) {
            $product = $products->get((int) $row['product_id']);
            $inputUnit = mb_strtolower(trim((string) $row['input_unit']));
            $baseUnit = $product->baseUnit();

            $qtyBase = BaseUnit::toBaseQuantity($row['quantity'], $inputUnit, $baseUnit);
            if (bccomp($qtyBase, '0', 4) <= 0) {
                throw new \RuntimeException('Jumlah dasar harus lebih dari 0.');
            }

            $quantityInput = number_format(round((float) $row['quantity'], 4), 4, '.', '');
            $unitCostInput = null;
            $unitCostBase = null;
            $subtotal = null;

            if (array_key_exists('unit_cost', $row) && $row['unit_cost'] !== null) {
                $unitCostInput = number_format(round((float) $row['unit_cost'], 2), 2, '.', '');
                $unitCostBase = BaseUnit::toBaseUnitCost($unitCostInput, $inputUnit, $baseUnit);
                $subtotal = InventoryCosting::extendedCost($qtyBase, $unitCostBase, 2);
                $dppCalc = bcadd($dppCalc, $subtotal, 2);

                $averageCost = InventoryCosting::averageCostAfterInbound($product, $qtyBase, $unitCostBase);
                $baseCostRounded = round((float) $unitCostBase, 2);
                $product->average_cost = $averageCost;
                $product->cost_price = $baseCostRounded;
                $product->save();
            }

            PurchaseLine::create([
                'purchase_id' => $purchase->id,
                'product_id' => $product->id,
                'product_name_snapshot' => $product->name,
                'sku_snapshot' => $product->sku,
                'quantity' => $qtyBase,
                'unit_cost' => $unitCostBase === null ? null : round((float) $unitCostBase, 2),
                'input_unit' => $inputUnit,
                'quantity_input' => $quantityInput,
                'base_unit' => $baseUnit,
                'qty_base' => $qtyBase,
                'unit_cost_input' => $unitCostInput,
                'unit_cost_base' => $unitCostBase,
                'subtotal' => $subtotal,
            ]);

            $product->increment('stock_quantity', $qtyBase);
            $product->refresh();

            StockMovement::create([
                'store_id' => $store->id,
                'product_id' => $product->id,
                'user_id' => $userId,
                'quantity_change' => $qtyBase,
                'type' => StockMovement::TYPE_PURCHASE,
                'reference_type' => \App\Models\Purchase::class,
                'reference_id' => $purchase->id,
                'balance_after' => (string) $product->stock_quantity,
                'notes' => $purchase->purchase_number,
            ]);
        }

        $purchase->update([
            'dpp_amount' => $dppCalc,
            'ppn_amount' => '0.00',
            'total_amount' => bcadd($dppCalc, '0.00', 2),
        ]);

        return $purchase;
    });
}

test('produk manual dibuat otomatis saat pembelian dan stok bertambah', function () {
    [$store, $user] = makePurchaseStore();

    $purchase = executePurchaseWithManual($store, $user, [[
        'new_product' => [
            'name' => 'Tepung Terigu 1kg',
            'sku' => 'TPG-001',
            'inventory_type' => 'raw_material',
            'base_unit' => 'gram',
        ],
        'quantity' => 10,
        'input_unit' => 'kg',
        'unit_cost' => 12000,
    ]]);

    $product = Product::query()->where('name', 'Tepung Terigu 1kg')->sole();
    $line = PurchaseLine::query()->sole();

    expect($product->store_id)->toBe($store->id)
        ->and($product->sku)->toBe('TPG-001')
        ->and($product->track_stock)->toBeTrue()
        ->and($product->stock_quantity)->toBe('10000.0000')
        ->and($line->product_name_snapshot)->toBe('Tepung Terigu 1kg')
        ->and($line->sku_snapshot)->toBe('TPG-001')
        ->and($line->product_id)->toBe($product->id);
});

test('produk manual dengan nama sama menggunakan produk existing', function () {
    [$store, $user] = makePurchaseStore();

    // Produk pertama: buat manual
    executePurchaseWithManual($store, $user, [[
        'new_product' => [
            'name' => 'Gula Pasir',
            'inventory_type' => 'raw_material',
            'base_unit' => 'gram',
        ],
        'quantity' => 5,
        'input_unit' => 'kg',
        'unit_cost' => 14000,
    ]]);

    $product = Product::query()->where('name', 'Gula Pasir')->sole();
    $firstStock = $product->fresh()->stock_quantity;

    // Produk kedua: nama sama → harus pakai yang existing
    executePurchaseWithManual($store, $user, [[
        'new_product' => [
            'name' => 'Gula Pasir',
            'inventory_type' => 'raw_material',
            'base_unit' => 'gram',
        ],
        'quantity' => 3,
        'input_unit' => 'kg',
        'unit_cost' => 15000,
    ]]);

    expect(Product::query()->where('name', 'Gula Pasir')->count())->toBe(1)
        ->and($product->fresh()->stock_quantity)->toBe('8000.0000');
});

test('purchase_line menyimpan snapshot nama dan sku', function () {
    [$store, $user] = makePurchaseStore();
    $product = makePurchaseProduct($store, ['name' => 'Kopi Arabica', 'sku' => 'KPA-001']);

    executePurchaseWithManual($store, $user, [[
        'product_id' => $product->id,
        'quantity' => 2,
        'input_unit' => 'kg',
        'unit_cost' => 200000,
    ]]);

    $line = PurchaseLine::query()->sole();

    expect($line->product_name_snapshot)->toBe('Kopi Arabica')
        ->and($line->sku_snapshot)->toBe('KPA-001');
});

test('pembelian menyimpan biaya tambahan ke tabel purchase_charges', function () {
    [$store, $user] = makePurchaseStore();
    $product = makePurchaseProduct($store, ['name' => 'Biji Kopi']);

    $purchase = executeCentralPurchase($store, $user, [[
        'product_id' => $product->id,
        'quantity' => 5,
        'input_unit' => 'kg',
        'unit_cost' => 20000,
    ]]);

    \App\Models\PurchaseCharge::create([
        'purchase_id' => $purchase->id,
        'store_id' => $store->id,
        'name' => 'Ongkos kirim',
        'amount' => '25000.00',
        'notes' => 'Dari gudang ke tokonya',
    ]);

    $purchase = $purchase->fresh(['charges']);
    expect($purchase->charges)->toHaveCount(1)
        ->and($purchase->charges->first()->name)->toBe('Ongkos kirim')
        ->and($purchase->charges->first()->amount)->toBe('25000.00');
});

test('pembelian yang hanya berisi biaya tetap tercatat tanpa baris produk', function () {
    [$store, $user] = makePurchaseStore();

    $purchase = \App\Models\Purchase::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'purchased_at' => now(),
    ]);

    \App\Models\PurchaseCharge::create([
        'purchase_id' => $purchase->id,
        'store_id' => $store->id,
        'name' => 'Biaya bongkar muat',
        'amount' => '50000.00',
    ]);

    $purchase->update([
        'dpp_amount' => '0.00',
        'charges_amount' => '50000.00',
        'ppn_amount' => '0.00',
        'total_amount' => '50000.00',
    ]);

    $purchase = $purchase->fresh(['lines', 'charges']);
    expect($purchase->lines)->toHaveCount(0)
        ->and($purchase->charges)->toHaveCount(1)
        ->and($purchase->charges_amount)->toBe('50000.00')
        ->and($purchase->total_amount)->toBe('50000.00');
});

test('biaya pembelian diposting sebagai beban operasional di jurnal', function () {
    [$store, $user] = makePurchaseStore();
    $product = makePurchaseProduct($store, ['name' => 'Biji Kopi']);

    $purchase = executeCentralPurchase($store, $user, [[
        'product_id' => $product->id,
        'quantity' => 1,
        'input_unit' => 'gram',
        'unit_cost' => 1000,
    ]]);

    \App\Models\PurchaseCharge::create([
        'purchase_id' => $purchase->id,
        'store_id' => $store->id,
        'name' => 'Ongkos kirim',
        'amount' => '25000.00',
    ]);
    $purchase->update([
        'charges_amount' => '25000.00',
        'total_amount' => bcadd($purchase->dpp_amount, 25000, 2),
    ]);

    \App\Support\Accounting\ChartOfAccountsBootstrap::ensureForStore($store);
    \App\Support\Accounting\JournalPoster::postPurchase($store, $purchase->fresh(['lines', 'charges']));

    $beban = \App\Models\ChartOfAccount::query()
        ->where('store_id', $store->id)
        ->where('code', \App\Support\Accounting\ChartOfAccountsBootstrap::CODE_BEBAN_OPERASIONAL)
        ->sole();

    $bebanDebit = \App\Models\JournalLine::query()
        ->join('journal_entries as je', 'journal_lines.journal_entry_id', '=', 'je.id')
        ->where('je.store_id', $store->id)
        ->where('je.reference_type', \App\Models\Purchase::class)
        ->where('journal_lines.chart_of_account_id', $beban->id)
        ->sum('journal_lines.debit');

    expect((float) $bebanDebit)->toBe(25000.0);
});
