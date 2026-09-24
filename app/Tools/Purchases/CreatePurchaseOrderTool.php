<?php

namespace App\Tools\Purchases;

use App\Models\CashShift;
use App\Models\CashTransaction;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\Accounting\JournalPoster;
use App\Support\BaseUnit;
use App\Support\InventoryCosting;
use App\Support\StoreLocationType;
use App\Support\StorePlanLimits;
use App\Tools\AiTool;
use App\Tools\ToolResult;
use Illuminate\Support\Facades\DB;

class CreatePurchaseOrderTool extends AiTool
{
    public function getName(): string
    {
        return 'create_purchase_order';
    }

    public function getDescription(): string
    {
        return 'Membuat pembelian (purchase order) baru dari supplier untuk merestock produk. '
            .'Gunakan saat user meminta beli/beliin/pesan barang, restock, atau membuat pembelian. '
            .'Produk bisa diidentifikasi lewat product_id atau product_name. Selalu sertakan jumlah (quantity) per produk.';
    }

    public function getParameters(): array
    {
        return [
            'items' => [
                'type' => 'array',
                'description' => 'Daftar produk yang akan dibeli',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'product_id' => [
                            'type' => 'integer',
                            'description' => 'ID produk (alternatif dengan product_name)',
                        ],
                        'product_name' => [
                            'type' => 'string',
                            'description' => 'Nama produk, dicari dengan LIKE jika product_id tidak diisi',
                        ],
                        'quantity' => [
                            'type' => 'number',
                            'description' => 'Jumlah yang dibeli (harus lebih dari 0), dalam satuan `unit`',
                        ],
                        'unit' => [
                            'type' => 'string',
                            'description' => 'Satuan input (default: satuan dasar produk, contoh: pcs, kg, gram)',
                        ],
                        'unit_cost' => [
                            'type' => 'number',
                            'description' => 'Harga beli per satuan input (default: harga modal produk)',
                        ],
                    ],
                    'required' => ['quantity'],
                ],
            ],
            'supplier_name' => [
                'type' => 'string',
                'description' => 'Nama supplier (opsional, dicari dengan LIKE. Jika tidak ada, pembelian tanpa supplier)',
            ],
            'notes' => [
                'type' => 'string',
                'description' => 'Catatan untuk pembelian ini (opsional)',
            ],
            'payment_method' => [
                'type' => 'string',
                'enum' => ['cash', 'credit'],
                'description' => 'Metode pembayaran: cash = lunas sekarang, credit = hutang (default: cash)',
            ],
        ];
    }

    protected function getRequiredParameters(): array
    {
        return ['items'];
    }

    public function execute(array $args, User $user): ToolResult
    {
        $this->validateArgs($args, $this->getRequiredParameters());

        $store = $user->store;
        if (! $store) {
            return ToolResult::error('User tidak memiliki toko');
        }

        if ($error = $this->guardStoreAccess($store)) {
            return ToolResult::error($error);
        }

        $items = $args['items'] ?? [];
        if (! is_array($items) || $items === []) {
            return ToolResult::error('Parameter "items" harus berisi minimal satu produk');
        }

        $paymentMethod = in_array($args['payment_method'] ?? null, ['cash', 'credit'], true)
            ? $args['payment_method']
            : 'cash';

        $supplierName = $args['supplier_name'] ?? null;

        try {
            $purchase = DB::transaction(function () use ($args, $store, $user, $supplierName, $paymentMethod, $items) {
                $resolved = $this->resolveItems($store, $items);
                $supplier = $this->resolveSupplier($store, $supplierName);

                $purchase = Purchase::create([
                    'store_id' => $store->id,
                    'supplier_id' => $supplier?->id,
                    'user_id' => $user->id,
                    'purchased_at' => now(),
                    'notes' => ! empty($args['notes']) ? mb_substr(mb_trim((string) $args['notes']), 0, 255) : null,
                ]);

                $dppCalc = '0.00';

                foreach ($resolved as $entry) {
                    /** @var Product $product */
                    $product = $entry['product'];
                    $inputUnit = $entry['input_unit'];
                    $baseUnit = $product->baseUnit();
                    $qtyBase = $entry['qty_base'];
                    $quantityInput = $entry['quantity_input'];

                    $unitCostInput = $entry['unit_cost_input'];
                    $unitCostBase = null;
                    $subtotal = null;

                    if ($unitCostInput !== null) {
                        $unitCostBase = BaseUnit::toBaseUnitCost($unitCostInput, $inputUnit, $baseUnit);
                        $subtotal = InventoryCosting::extendedCost($qtyBase, $unitCostBase, 2);
                        $dppCalc = bcadd($dppCalc, $subtotal, 2);

                        $product->average_cost = InventoryCosting::averageCostAfterInbound($product, $qtyBase, $unitCostBase);
                        $product->cost_price = round((float) $unitCostBase, 2);
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
                        'user_id' => $user->id,
                        'quantity_change' => $qtyBase,
                        'type' => StockMovement::TYPE_PURCHASE,
                        'reference_type' => Purchase::class,
                        'reference_id' => $purchase->id,
                        'balance_after' => (string) $product->stock_quantity,
                        'notes' => $purchase->purchase_number,
                    ]);
                }

                $isPaid = $paymentMethod === 'cash';
                $totalAmount = (float) $dppCalc;

                $purchase->update([
                    'dpp_amount' => $dppCalc,
                    'charges_amount' => '0.00',
                    'ppn_amount' => '0.00',
                    'total_amount' => $totalAmount,
                    'is_paid' => $isPaid,
                    'payment_method' => $paymentMethod,
                    'amount_paid' => $isPaid ? $totalAmount : 0,
                ]);

                return $purchase;
            });
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }

        JournalPoster::postPurchase($store, $purchase->fresh(['lines', 'charges']));

        ActivityLogger::logCreated(
            store: $store,
            subject: $purchase,
            description: "Membuat pembelian {$purchase->purchase_number} (via AI Assistant)",
            metadata: [
                'purchase_number' => $purchase->purchase_number,
                'supplier' => $purchase->supplier?->name,
                'lines_count' => $purchase->lines()->count(),
            ]
        );

        $this->recordLocalCashOutflow($store, $user, $purchase);

        $purchase->load('supplier:id,name');

        return ToolResult::success(
            data: [
                'purchase_id' => $purchase->id,
                'purchase_number' => $purchase->purchase_number,
                'supplier' => $purchase->supplier?->name,
                'payment_method' => $paymentMethod,
                'is_paid' => (bool) $purchase->is_paid,
                'total_amount' => (float) $purchase->total_amount,
                'items' => $purchase->lines->map(fn (PurchaseLine $line) => [
                    'product_id' => $line->product_id,
                    'name' => $line->product_name_snapshot,
                    'quantity' => $this->formatStock($line->qty_base),
                    'unit' => $line->base_unit,
                    'subtotal' => (float) ($line->subtotal ?? 0),
                ]),
            ],
            message: sprintf(
                'Pembelian %s berhasil dibuat (%d item, total %s). Stok produk telah bertambah.',
                $purchase->purchase_number,
                $purchase->lines()->count(),
                $this->formatCurrency((float) $purchase->total_amount),
            )
        );
    }

    /**
     * Validasi akses toko + konversi setiap item menjadi siap-pakai.
     *
     * @return array<int, array{product: Product, input_unit: string, qty_base: string, quantity_input: string, unit_cost_input: ?string}>
     */
    protected function guardStoreAccess(Store $store): ?string
    {
        if (! StorePlanLimits::allowsAdvancedRetailFeatures($store)) {
            return 'Paket Starter tidak mencakup modul pembelian. Upgrade paket terlebih dahulu untuk membuat pembelian.';
        }

        if (StoreLocationType::mustRoutePurchaseThroughCentralWarehouse($store)
            && ! (bool) ($store->settings['is_allow_local_purchase'] ?? false)) {
            return 'Toko ini termasuk sistem gudang, sehingga pembelian supplier harus dicatat di Gudang Pusat. '
                .'Gunakan halaman Pembelian dari akun Gudang Pusat, atau aktifkan pembelian lokal di pengaturan toko.';
        }

        return null;
    }

    /**
     * @param  array<int, array>  $items
     * @return array<int, array{product: Product, input_unit: string, qty_base: string, quantity_input: string, unit_cost_input: ?string}>
     */
    protected function resolveItems(Store $store, array $items): array
    {
        $resolved = [];

        foreach (array_values($items) as $index => $row) {
            $label = 'Item ke-'.($index + 1);
            $quantity = (float) ($row['quantity'] ?? 0);

            if ($quantity <= 0) {
                throw new \RuntimeException("{$label}: jumlah harus lebih dari 0.");
            }

            $product = $this->resolveProduct($store, $row);

            if (! $product) {
                $identifier = $row['product_id'] ?? ($row['product_name'] ?? '-');
                throw new \RuntimeException("{$label}: produk \"{$identifier}\" tidak ditemukan di toko ini.");
            }

            if (! $product->is_active) {
                throw new \RuntimeException("{$label}: produk \"{$product->name}\" tidak aktif.");
            }

            if (! $product->track_stock || $product->is_bundle) {
                throw new \RuntimeException("{$label}: produk \"{$product->name}\" tidak melacak stok.");
            }

            $inputUnit = mb_strtolower(mb_trim((string) ($row['unit'] ?? '')));
            if ($inputUnit === '') {
                $inputUnit = mb_strtolower((string) $product->baseUnit());
            }

            $baseUnit = $product->baseUnit();
            $qtyBase = BaseUnit::toBaseQuantity($quantity, $inputUnit, $baseUnit);
            if (bccomp($qtyBase, '0', 4) <= 0) {
                throw new \RuntimeException("{$label}: jumlah dasar untuk \"{$product->name}\" harus lebih dari 0.");
            }

            $unitCost = $row['unit_cost'] ?? null;
            if ($unitCost === null && (float) $product->cost_price > 0) {
                $unitCost = $product->cost_price;
            }

            $resolved[] = [
                'product' => $product,
                'input_unit' => $inputUnit,
                'qty_base' => $qtyBase,
                'quantity_input' => number_format(round($quantity, 4), 4, '.', ''),
                'unit_cost_input' => $unitCost === null
                    ? null
                    : number_format(round((float) $unitCost, 2), 2, '.', ''),
            ];
        }

        return $resolved;
    }

    protected function resolveProduct(Store $store, array $row): ?Product
    {
        $query = Product::query()
            ->where('store_id', $store->id)
            ->lockForUpdate();

        if (! empty($row['product_id'])) {
            return $query->find($row['product_id']);
        }

        if (! empty($row['product_name'])) {
            return (clone $query)
                ->where('name', 'like', "%{$row['product_name']}%")
                ->orderBy('id')
                ->first();
        }

        return null;
    }

    protected function resolveSupplier(Store $store, mixed $name): ?Supplier
    {
        $name = is_string($name) ? mb_trim($name) : '';
        if ($name === '') {
            return null;
        }

        $supplier = Supplier::query()
            ->where('store_id', $store->id)
            ->where('name', 'like', "%{$name}%")
            ->orderBy('id')
            ->first();

        if ($supplier !== null) {
            return $supplier;
        }

        return Supplier::create([
            'store_id' => $store->id,
            'name' => mb_substr($name, 0, 255),
        ]);
    }

    protected function allowsLocalPurchase(Store $store): bool
    {
        return StoreLocationType::mustRoutePurchaseThroughCentralWarehouse($store)
            && (bool) ($store->settings['is_allow_local_purchase'] ?? false);
    }

    protected function recordLocalCashOutflow(Store $store, User $user, Purchase $purchase): void
    {
        if (! $this->allowsLocalPurchase($store) || ! $purchase->is_paid || $purchase->payment_method !== 'cash') {
            return;
        }

        $openShift = CashShift::query()
            ->where('store_id', $store->id)
            ->where('user_id', $user->id)
            ->whereNull('closed_at')
            ->first();

        if (! $openShift) {
            return;
        }

        CashTransaction::create([
            'store_id' => $store->id,
            'user_id' => $user->id,
            'cash_shift_id' => $openShift->id,
            'type' => CashTransaction::TYPE_OUT,
            'category' => CashTransaction::CATEGORY_EXPENSE,
            'amount' => $purchase->total_amount,
            'party_name' => $purchase->supplier?->name,
            'notes' => 'Pembelian lokal '.$purchase->purchase_number.' (via AI Assistant)',
        ]);
    }

    protected function formatStock(string $stock): string
    {
        if (! str_contains($stock, '.')) {
            return $stock;
        }

        return rtrim(rtrim($stock, '0'), '.');
    }
}
