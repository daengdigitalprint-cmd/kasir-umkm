<?php

namespace App\Tools\Stock;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Tools\AiTool;
use App\Tools\ToolResult;

class BulkUpdateStockTool extends AiTool
{
    public function getName(): string
    {
        return 'update_stock_bulk';
    }

    public function getDescription(): string
    {
        return 'Mengubah stok banyak produk sekaligus (disetel ke nilai tertentu atau ditambah jumlah tertentu). Gunakan saat user ingin menyesuaikan stok beberapa produk sekaligus.';
    }

    public function getParameters(): array
    {
        return [
            'product_ids' => [
                'type' => 'array',
                'items' => ['type' => 'integer'],
                'description' => 'Daftar ID produk yang stoknya akan diubah',
            ],
            'set_to' => [
                'type' => 'integer',
                'description' => 'Jika diisi, stok setiap produk disetel menjadi nilai ini',
            ],
            'add' => [
                'type' => 'integer',
                'description' => 'Jika diisi (dan set_to kosong), stok setiap produk ditambah sebanyak ini',
            ],
            'reason' => [
                'type' => 'string',
                'description' => 'Alasan perubahan stok (contoh: "Stok opname", "Barang masuk")',
            ],
        ];
    }

    protected function getRequiredParameters(): array
    {
        return ['product_ids'];
    }

    public function execute(array $args, User $user): ToolResult
    {
        $this->validateArgs($args, $this->getRequiredParameters());

        $store = $user->store;
        if (! $store) {
            return ToolResult::error('User tidak memiliki toko');
        }

        $productIds = collect($args['product_ids'])->map(fn ($id) => (int) $id)->unique()->values();
        if ($productIds->isEmpty()) {
            return ToolResult::error('Daftar produk kosong');
        }

        $hasSetTo = array_key_exists('set_to', $args) && $args['set_to'] !== null;
        $hasAdd = array_key_exists('add', $args) && $args['add'] !== null;

        if (! $hasSetTo && ! $hasAdd) {
            return ToolResult::error('Isi set_to atau add untuk menentukan perubahan stok');
        }

        $reason = $args['reason'] ?? 'Penyesuaian massal via AI Assistant';
        $updated = collect();
        $skipped = collect();

        foreach ($productIds as $productId) {
            $product = Product::where('store_id', $store->id)->find($productId);

            if (! $product) {
                $skipped->push("#{$productId}");

                continue;
            }

            $oldStock = (int) round((float) $product->stock_quantity);
            $newStock = $hasSetTo ? (int) $args['set_to'] : $oldStock + (int) $args['add'];

            if ($newStock < 0 && ! $product->allow_negative_stock) {
                $skipped->push($product->name);

                continue;
            }

            $change = $newStock - $oldStock;
            $product->update(['stock_quantity' => $newStock]);

            StockMovement::create([
                'product_id' => $product->id,
                'store_id' => $store->id,
                'type' => StockMovement::TYPE_ADJUSTMENT,
                'quantity_change' => $change,
                'balance_after' => $newStock,
                'reference_type' => 'ai_adjustment',
                'notes' => $reason.' (oleh AI Assistant)',
                'user_id' => $user->id,
            ]);

            $updated->push([
                'product_id' => $product->id,
                'name' => $product->name,
                'old_stock' => $oldStock,
                'new_stock' => $newStock,
            ]);
        }

        if ($updated->isEmpty()) {
            return ToolResult::error('Tidak ada produk yang dapat diubah: '.implode(', ', $skipped->all()));
        }

        $message = $hasSetTo
            ? sprintf('Stok %d produk berhasil disetel menjadi %d.', $updated->count(), (int) $args['set_to'])
            : sprintf('Stok %d produk berhasil ditambah %d.', $updated->count(), (int) $args['add']);

        if ($skipped->isNotEmpty()) {
            $message .= ' Dilewati: '.implode(', ', $skipped->all()).'.';
        }

        return ToolResult::success(
            data: [
                'products' => $updated->all(),
                'count' => $updated->count(),
                'skipped' => $skipped->all(),
                'set_to' => $hasSetTo ? (int) $args['set_to'] : null,
                'add' => $hasAdd ? (int) $args['add'] : null,
            ],
            message: $message,
        );
    }
}
