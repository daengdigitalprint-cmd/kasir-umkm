<?php

namespace App\Tools\Products;

use App\Models\Product;
use App\Models\User;
use App\Tools\AiTool;
use App\Tools\ToolResult;

class UpdateProductTool extends AiTool
{
    public function getName(): string
    {
        return 'update_product';
    }

    public function getDescription(): string
    {
        return 'Memperbarui informasi produk seperti nama, harga, stok, dll. Gunakan tool ini saat user ingin mengubah data produk.';
    }

    public function getParameters(): array
    {
        return [
            'product_id' => [
                'type' => 'integer',
                'description' => 'ID produk yang akan diupdate',
            ],
            'name' => [
                'type' => 'string',
                'description' => 'Nama baru produk (opsional)',
            ],
            'price' => [
                'type' => 'number',
                'description' => 'Harga jual baru dalam Rupiah (opsional)',
            ],
            'cost' => [
                'type' => 'number',
                'description' => 'Harga modal/beli baru dalam Rupiah (opsional)',
            ],
            'stock' => [
                'type' => 'integer',
                'description' => 'Stok baru produk (opsional)',
            ],
            'is_active' => [
                'type' => 'boolean',
                'description' => 'Status aktif/nonaktif produk (opsional)',
            ],
        ];
    }

    protected function getRequiredParameters(): array
    {
        return ['product_id'];
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

        $updateData = [];

        if (isset($args['name'])) {
            $updateData['name'] = $args['name'];
        }

        if (isset($args['price'])) {
            $updateData['price'] = $args['price'];
        }

        if (isset($args['cost'])) {
            $updateData['cost_price'] = $args['cost'];
        }

        if (isset($args['stock'])) {
            $updateData['stock_quantity'] = $args['stock'];
        }

        if (isset($args['is_active'])) {
            $updateData['is_active'] = $args['is_active'];
        }

        if (empty($updateData)) {
            return ToolResult::error('Tidak ada data yang diupdate');
        }

        $product->update($updateData);

        return ToolResult::success(
            data: [
                'product_id' => $product->id,
                'name' => $product->name,
                'price' => $this->formatCurrency($product->price),
                'stock' => $product->stock_quantity,
                'is_active' => $product->is_active,
            ],
            message: "Produk \"{$product->name}\" berhasil diperbarui"
        );
    }
}
