<?php

namespace App\Tools\Products;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Tools\AiTool;
use App\Tools\ToolResult;

class CreateProductTool extends AiTool
{
    public function getName(): string
    {
        return 'create_product';
    }

    public function getDescription(): string
    {
        return 'MEMBUAT PRODUK BARU di sistem. Gunakan SAAT user ingin MENAMBAH/MEMBUAT produk baru. Jika user menyebutkan nama produk beserta harga dan info lainnya, LANGSUNG buat produknya. JANGAN minta konfirmasi.';
    }

    public function getParameters(): array
    {
        return [
            'name' => [
                'type' => 'string',
                'description' => 'Nama produk',
            ],
            'price' => [
                'type' => 'number',
                'description' => 'Harga jual produk dalam Rupiah',
            ],
            'category' => [
                'type' => 'string',
                'description' => 'Nama kategori produk (opsional, akan dibuat baru jika belum ada)',
            ],
            'cost' => [
                'type' => 'number',
                'description' => 'Harga modal/beli/HPP (Harga Pokok Penjualan) produk dalam Rupiah (opsional)',
            ],
            'sku' => [
                'type' => 'string',
                'description' => 'SKU atau kode produk (opsional, akan di-generate otomatis jika kosong)',
            ],
            'stock' => [
                'type' => 'integer',
                'description' => 'Stok awal produk (default: 0)',
            ],
            'unit' => [
                'type' => 'string',
                'description' => 'Satuan produk (pcs, kg, liter, dll)',
            ],
        ];
    }

    protected function getRequiredParameters(): array
    {
        return ['name', 'price'];
    }

    public function execute(array $args, User $user): ToolResult
    {
        $this->validateArgs($args, $this->getRequiredParameters());

        $store = $user->store;
        if (! $store) {
            return ToolResult::error('User tidak memiliki toko');
        }

        // Cari atau buat kategori
        $categoryId = null;
        if (! empty($args['category'])) {
            $category = ProductCategory::firstOrCreate(
                ['store_id' => $store->id, 'name' => $args['category']],
                ['slug' => \Str::slug($args['category'])]
            );
            $categoryId = $category->id;
        }

        // Generate SKU jika kosong
        $sku = $args['sku'] ?? $this->generateSku($args['name']);

        // Buat produk
        $product = Product::create([
            'store_id' => $store->id,
            'name' => $args['name'],
            'sku' => $sku,
            'price' => $args['price'],
            'cost_price' => $args['cost'] ?? 0,
            'stock_quantity' => $args['stock'] ?? 0,
            'unit' => $args['unit'] ?? 'pcs',
            'product_category_id' => $categoryId,
            'is_active' => true,
        ]);

        return ToolResult::success(
            data: [
                'product_id' => $product->id,
                'name' => $product->name,
                'price' => $this->formatCurrency($product->price),
                'sku' => $product->sku,
                'stock' => $product->stock_quantity,
            ],
            message: "Produk \"{$product->name}\" berhasil dibuat dengan harga {$this->formatCurrency($product->price)}"
        );
    }

    protected function generateSku(string $name): string
    {
        $prefix = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $name), 0, 3));
        $suffix = strtoupper(substr(md5(uniqid()), 0, 5));

        return $prefix.'-'.$suffix;
    }
}
