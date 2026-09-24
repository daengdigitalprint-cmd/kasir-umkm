<?php

namespace App\Tools\Stock;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Tools\AiTool;
use App\Tools\ToolResult;

class UpdateStockTool extends AiTool
{
    public function getName(): string
    {
        return 'update_stock';
    }

    public function getDescription(): string
    {
        return 'Menambah atau mengurangi stok produk. Gunakan tool ini saat user ingin melakukan penyesuaian stok.';
    }

    public function getParameters(): array
    {
        return [
            'product_id' => [
                'type' => 'integer',
                'description' => 'ID produk yang stoknya akan diupdate',
            ],
            'quantity' => [
                'type' => 'integer',
                'description' => 'Jumlah stok yang ditambah (positif) atau dikurangi (negatif)',
            ],
            'reason' => [
                'type' => 'string',
                'description' => 'Alasan perubahan stok (contoh: "Stok opname", "Barang masuk", "Rusak")',
            ],
        ];
    }

    protected function getRequiredParameters(): array
    {
        return ['product_id', 'quantity', 'reason'];
    }

    public function execute(array $args, User $user): ToolResult
    {
        $this->validateArgs($args, $this->getRequiredParameters());

        $store = $user->store;
        if (! $store) {
            return ToolResult::error('User tidak memiliki toko');
        }

        $product = Product::where('store_id', $store->id)
            ->find($args['product_id']);

        if (! $product) {
            return ToolResult::error("Produk dengan ID {$args['product_id']} tidak ditemukan");
        }

        $quantity = (int) $args['quantity'];
        $oldStock = $product->stock_quantity;
        $newStock = $oldStock + $quantity;

        // Validasi stok tidak boleh negatif (kecuali diizinkan)
        if ($newStock < 0 && ! $product->allow_negative_stock) {
            return ToolResult::error(sprintf(
                'Stok tidak cukup. Stok saat ini: %s, diminta: %d',
                $this->formatStock($oldStock),
                abs($quantity),
            ));
        }

        // Update stok
        $product->update(['stock_quantity' => $newStock]);

        // Catat pergerakan stok
        StockMovement::create([
            'product_id' => $product->id,
            'store_id' => $store->id,
            'type' => StockMovement::TYPE_ADJUSTMENT,
            'quantity_change' => $quantity,
            'balance_after' => $newStock,
            'reference_type' => 'ai_adjustment',
            'notes' => $args['reason'].' (oleh AI Assistant)',
            'user_id' => $user->id,
        ]);

        $action = $quantity > 0 ? 'ditambah' : 'dikurangi';

        return ToolResult::success(
            data: [
                'product_id' => $product->id,
                'name' => $product->name,
                'old_stock' => $oldStock,
                'new_stock' => $newStock,
                'change' => $quantity,
            ],
            message: sprintf(
                'Stok "%s" berhasil %s sebanyak %d. Stok: %s → %s',
                $product->name,
                $action,
                abs($quantity),
                $this->formatStock($oldStock),
                $this->formatStock($newStock),
            )
        );
    }

    protected function formatStock(string|int $stock): string
    {
        $value = (string) $stock;
        if (! str_contains($value, '.')) {
            return $value;
        }

        return rtrim(rtrim($value, '0'), '.');
    }
}
