<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Store;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Cari outlet demo (ES NUSA) terlebih dahulu, fallback ke toko sembako atau toko pertama
        $store = Store::query()->where('slug', 'demo-meli-saturiski')->first()
            ?? Store::query()->where('slug', 'toko-sembako-maju-jaya')->first()
            ?? Store::query()->first();

        if (! $store) {
            $this->command->error('Store not found. Please run StoreSeeder first.');

            return;
        }

        $this->command->info("Seeding produk menu ke: {$store->name} (ID {$store->id})");

        $products = [
            // TEA SERIES
            ['name' => 'Teh Original (K)', 'category' => 'TEA SERIES', 'price' => 3000, 'unit' => 'pcs', 'sku' => 'TS-TORI-K', 'inventory_type' => 'sellable'],
            ['name' => 'Teh Original (B)', 'category' => 'TEA SERIES', 'price' => 5000, 'unit' => 'pcs', 'sku' => 'TS-TORI-B', 'inventory_type' => 'sellable'],
            ['name' => 'Lecy Tea (K)', 'category' => 'TEA SERIES', 'price' => 8000, 'unit' => 'pcs', 'sku' => 'TS-LCT-K', 'inventory_type' => 'sellable'],
            ['name' => 'Lecy Tea (B)', 'category' => 'TEA SERIES', 'price' => 10000, 'unit' => 'pcs', 'sku' => 'TS-LCT-B', 'inventory_type' => 'sellable'],
            ['name' => 'Peach Tea (K)', 'category' => 'TEA SERIES', 'price' => 8000, 'unit' => 'pcs', 'sku' => 'TS-PCT-K', 'inventory_type' => 'sellable'],
            ['name' => 'Peach tea (B)', 'category' => 'TEA SERIES', 'price' => 10000, 'unit' => 'pcs', 'sku' => 'TS-PCT-B', 'inventory_type' => 'sellable'],
            ['name' => 'Lemon Tea (K)', 'category' => 'TEA SERIES', 'price' => 8000, 'unit' => 'pcs', 'sku' => 'TS-LMT-K', 'inventory_type' => 'sellable'],
            ['name' => 'Lemon Tea (B)', 'category' => 'TEA SERIES', 'price' => 10000, 'unit' => 'pcs', 'sku' => 'TS-LMT-B', 'inventory_type' => 'sellable'],
            ['name' => 'Yakult Tea (K)', 'category' => 'TEA SERIES', 'price' => 8000, 'unit' => 'pcs', 'sku' => 'TS-YKT-K', 'inventory_type' => 'sellable'],
            ['name' => 'Yakult Tea (B)', 'category' => 'TEA SERIES', 'price' => 10000, 'unit' => 'pcs', 'sku' => 'TS-YKT-B', 'inventory_type' => 'sellable'],
            ['name' => 'Green Apple Tea (K)', 'category' => 'TEA SERIES', 'price' => 10000, 'unit' => 'pcs', 'sku' => 'TS-GAT-K', 'inventory_type' => 'sellable'],
            ['name' => 'Green Apple Tea (B)', 'category' => 'TEA SERIES', 'price' => 12000, 'unit' => 'pcs', 'sku' => 'TS-GAT-B', 'inventory_type' => 'sellable'],
            ['name' => 'Semangka Tea (K)', 'category' => 'TEA SERIES', 'price' => 10000, 'unit' => 'pcs', 'sku' => 'TS-SGT-K', 'inventory_type' => 'sellable'],
            ['name' => 'Semangka Tea (B)', 'category' => 'TEA SERIES', 'price' => 12000, 'unit' => 'pcs', 'sku' => 'TS-SGT-B', 'inventory_type' => 'sellable'],
            ['name' => 'Honey Lime Tea (K)', 'category' => 'TEA SERIES', 'price' => 10000, 'unit' => 'pcs', 'sku' => 'TS-HLT-K', 'inventory_type' => 'sellable'],
            ['name' => 'Honey Lime Tea (B)', 'category' => 'TEA SERIES', 'price' => 12000, 'unit' => 'pcs', 'sku' => 'TS-HLT-B', 'inventory_type' => 'sellable'],
            ['name' => 'Teh Jahe Jeruk (K)', 'category' => 'TEA SERIES', 'price' => 8000, 'unit' => 'pcs', 'sku' => 'TS-TJJ-K', 'inventory_type' => 'sellable'],
            ['name' => 'Teh Jahe Jeruk (B)', 'category' => 'TEA SERIES', 'price' => 10000, 'unit' => 'pcs', 'sku' => 'TS-TJJ-B', 'inventory_type' => 'sellable'],
            ['name' => 'Teh Sereh Wangi (K)', 'category' => 'TEA SERIES', 'price' => 8000, 'unit' => 'pcs', 'sku' => 'TS-TSW-K', 'inventory_type' => 'sellable'],
            ['name' => 'Teh Sereh Wangi (B)', 'category' => 'TEA SERIES', 'price' => 10000, 'unit' => 'pcs', 'sku' => 'TS-TSW-B', 'inventory_type' => 'sellable'],

            // MILK TEA SERIES
            ['name' => 'Teh Susu Jahe Jeruk (K)', 'category' => 'MILK TEA SERIES', 'price' => 10000, 'unit' => 'pcs', 'sku' => 'MTS-TSJJ-K', 'inventory_type' => 'sellable'],
            ['name' => 'Teh Susu Jahe Jeruk (B)', 'category' => 'MILK TEA SERIES', 'price' => 12000, 'unit' => 'pcs', 'sku' => 'MTS-TSJJ-B', 'inventory_type' => 'sellable'],
            ['name' => 'Milktea (K)', 'category' => 'MILK TEA SERIES', 'price' => 8000, 'unit' => 'pcs', 'sku' => 'MTS-MT-K', 'inventory_type' => 'sellable'],
            ['name' => 'Milktea (B)', 'category' => 'MILK TEA SERIES', 'price' => 10000, 'unit' => 'pcs', 'sku' => 'MTS-MT-B', 'inventory_type' => 'sellable'],
            ['name' => 'Choco Milktea (K)', 'category' => 'MILK TEA SERIES', 'price' => 10000, 'unit' => 'pcs', 'sku' => 'MTS-CMT-K', 'inventory_type' => 'sellable'],
            ['name' => 'Choco Milktea (B)', 'category' => 'MILK TEA SERIES', 'price' => 12000, 'unit' => 'pcs', 'sku' => 'MTS-CMT-B', 'inventory_type' => 'sellable'],
            ['name' => 'Strawberry Milktea (K)', 'category' => 'MILK TEA SERIES', 'price' => 10000, 'unit' => 'pcs', 'sku' => 'MTS-SMT-K', 'inventory_type' => 'sellable'],
            ['name' => 'Strawberry Milktea (B)', 'category' => 'MILK TEA SERIES', 'price' => 12000, 'unit' => 'pcs', 'sku' => 'MTS-SMT-B', 'inventory_type' => 'sellable'],
            ['name' => 'Mango Milktea (K)', 'category' => 'MILK TEA SERIES', 'price' => 10000, 'unit' => 'pcs', 'sku' => 'MTS-MM-K', 'inventory_type' => 'sellable'],
            ['name' => 'Mango Milktea (B)', 'category' => 'MILK TEA SERIES', 'price' => 12000, 'unit' => 'pcs', 'sku' => 'MTS-MM-B', 'inventory_type' => 'sellable'],
            ['name' => 'Cookies Milktea (K)', 'category' => 'MILK TEA SERIES', 'price' => 10000, 'unit' => 'pcs', 'sku' => 'MTS-CKMT-K', 'inventory_type' => 'sellable'],
            ['name' => 'Cookies Milktea (B)', 'category' => 'MILK TEA SERIES', 'price' => 12000, 'unit' => 'pcs', 'sku' => 'MTS-CKMT-B', 'inventory_type' => 'sellable'],

            // MILK SERIES
            ['name' => 'Taro (K)', 'category' => 'MILK SERIES', 'price' => 8000, 'unit' => 'pcs', 'sku' => 'MS-TR-K', 'inventory_type' => 'sellable'],
            ['name' => 'Taro (B)', 'category' => 'MILK SERIES', 'price' => 10000, 'unit' => 'pcs', 'sku' => 'MS-TR-B', 'inventory_type' => 'sellable'],
            ['name' => 'Kacang Hijau (K)', 'category' => 'MILK SERIES', 'price' => 8000, 'unit' => 'pcs', 'sku' => 'MS-KH-K', 'inventory_type' => 'sellable'],
            ['name' => 'Kacang Hijau (B)', 'category' => 'MILK SERIES', 'price' => 10000, 'unit' => 'pcs', 'sku' => 'MS-KH-B', 'inventory_type' => 'sellable'],
            ['name' => 'Rock Melon (K)', 'category' => 'MILK SERIES', 'price' => 8000, 'unit' => 'pcs', 'sku' => 'MS-RM-K', 'inventory_type' => 'sellable'],
            ['name' => 'Rock Melon (B)', 'category' => 'MILK SERIES', 'price' => 10000, 'unit' => 'pcs', 'sku' => 'MS-RM-B', 'inventory_type' => 'sellable'],
            ['name' => 'Green Tea (K)', 'category' => 'MILK SERIES', 'price' => 8000, 'unit' => 'pcs', 'sku' => 'MS-GT-K', 'inventory_type' => 'sellable'],
            ['name' => 'Green Tea (B)', 'category' => 'MILK SERIES', 'price' => 10000, 'unit' => 'pcs', 'sku' => 'MS-GT-B', 'inventory_type' => 'sellable'],
            ['name' => 'Coffelatte (K)', 'category' => 'MILK SERIES', 'price' => 10000, 'unit' => 'pcs', 'sku' => 'MS-CL-K', 'inventory_type' => 'sellable'],
            ['name' => 'Coffelatte (B)', 'category' => 'MILK SERIES', 'price' => 12000, 'unit' => 'pcs', 'sku' => 'MS-CL-B', 'inventory_type' => 'sellable'],
            ['name' => 'Es Doger (K)', 'category' => 'MILK SERIES', 'price' => 8000, 'unit' => 'pcs', 'sku' => 'MS-ED-K', 'inventory_type' => 'sellable'],
            ['name' => 'Es Doger (B)', 'category' => 'MILK SERIES', 'price' => 10000, 'unit' => 'pcs', 'sku' => 'MS-ED-B', 'inventory_type' => 'sellable'],
            ['name' => 'Es Melon (K)', 'category' => 'MILK SERIES', 'price' => 10000, 'unit' => 'pcs', 'sku' => 'MS-EM-K', 'inventory_type' => 'sellable'],
            ['name' => 'Es Melon (B)', 'category' => 'MILK SERIES', 'price' => 12000, 'unit' => 'pcs', 'sku' => 'MS-EM-B', 'inventory_type' => 'sellable'],
            ['name' => 'Coklat Premium (K)', 'category' => 'MILK SERIES', 'price' => 10000, 'unit' => 'pcs', 'sku' => 'MS-CP-K', 'inventory_type' => 'sellable'],
            ['name' => 'Coklat Premium (B)', 'category' => 'MILK SERIES', 'price' => 12000, 'unit' => 'pcs', 'sku' => 'MS-CP-B', 'inventory_type' => 'sellable'],
            ['name' => 'Rose Matcha (K)', 'category' => 'MILK SERIES', 'price' => 13000, 'unit' => 'pcs', 'sku' => 'MS-RMa-K', 'inventory_type' => 'sellable'],
            ['name' => 'Rose Matcha (B)', 'category' => 'MILK SERIES', 'price' => 15000, 'unit' => 'pcs', 'sku' => 'MS-RMa-B', 'inventory_type' => 'sellable'],
            ['name' => 'Choco Matcha (K)', 'category' => 'MILK SERIES', 'price' => 13000, 'unit' => 'pcs', 'sku' => 'MS-CMa-K', 'inventory_type' => 'sellable'],
            ['name' => 'Choco Matcha (B)', 'category' => 'MILK SERIES', 'price' => 15000, 'unit' => 'pcs', 'sku' => 'MS-CMa-B', 'inventory_type' => 'sellable'],
            ['name' => 'Choco Taro (K)', 'category' => 'MILK SERIES', 'price' => 13000, 'unit' => 'pcs', 'sku' => 'MS-CT-K', 'inventory_type' => 'sellable'],
            ['name' => 'Choco Taro (B)', 'category' => 'MILK SERIES', 'price' => 15000, 'unit' => 'pcs', 'sku' => 'MS-CT-B', 'inventory_type' => 'sellable'],
            ['name' => 'Cookies & Cream (K)', 'category' => 'MILK SERIES', 'price' => 13000, 'unit' => 'pcs', 'sku' => 'MS-CC-K', 'inventory_type' => 'sellable'],
            ['name' => 'Cookies & Cream (B)', 'category' => 'MILK SERIES', 'price' => 15000, 'unit' => 'pcs', 'sku' => 'MS-CC-B', 'inventory_type' => 'sellable'],
            ['name' => 'Cotton Candy (K)', 'category' => 'MILK SERIES', 'price' => 10000, 'unit' => 'pcs', 'sku' => 'MS-CCa-K', 'inventory_type' => 'sellable'],
            ['name' => 'Cotton Candy (B)', 'category' => 'MILK SERIES', 'price' => 12000, 'unit' => 'pcs', 'sku' => 'MS-CCa-B', 'inventory_type' => 'sellable'],
            ['name' => 'Alvocado (K)', 'category' => 'MILK SERIES', 'price' => 10000, 'unit' => 'pcs', 'sku' => 'MS-AVC-K', 'inventory_type' => 'sellable'],
            ['name' => 'Alvocado (B)', 'category' => 'MILK SERIES', 'price' => 12000, 'unit' => 'pcs', 'sku' => 'MS-AVC-B', 'inventory_type' => 'sellable'],

            // MACCHIATO SERIES
            ['name' => 'Coklat Macciato (K)', 'category' => 'MACCHIATO SERIES', 'price' => 10000, 'unit' => 'pcs', 'sku' => 'MCS-CM-K', 'inventory_type' => 'sellable'],
            ['name' => 'Coklat Macciato (B)', 'category' => 'MACCHIATO SERIES', 'price' => 12000, 'unit' => 'pcs', 'sku' => 'MCS-CM-B', 'inventory_type' => 'sellable'],
            ['name' => 'Red Velvet Macciato (K)', 'category' => 'MACCHIATO SERIES', 'price' => 10000, 'unit' => 'pcs', 'sku' => 'MCS-RVM-K', 'inventory_type' => 'sellable'],
            ['name' => 'Red Velvet Macciato (B)', 'category' => 'MACCHIATO SERIES', 'price' => 12000, 'unit' => 'pcs', 'sku' => 'MCS-RVM-B', 'inventory_type' => 'sellable'],
            ['name' => 'Greentea Macciato (K)', 'category' => 'MACCHIATO SERIES', 'price' => 10000, 'unit' => 'pcs', 'sku' => 'MCS-GTM-K', 'inventory_type' => 'sellable'],
            ['name' => 'Greentea Macciato (B)', 'category' => 'MACCHIATO SERIES', 'price' => 12000, 'unit' => 'pcs', 'sku' => 'MCS-GTM-B', 'inventory_type' => 'sellable'],
            ['name' => 'Taro Macciato (K)', 'category' => 'MACCHIATO SERIES', 'price' => 10000, 'unit' => 'pcs', 'sku' => 'MCS-TM-K', 'inventory_type' => 'sellable'],
            ['name' => 'Taro Macciato (B)', 'category' => 'MACCHIATO SERIES', 'price' => 12000, 'unit' => 'pcs', 'sku' => 'MCS-TM-B', 'inventory_type' => 'sellable'],

            // SQUASH SERIES
            ['name' => 'Strawberry Milk Squash (K)', 'category' => 'SQUASH SERIES', 'price' => 10000, 'unit' => 'pcs', 'sku' => 'SQS-SMS-K', 'inventory_type' => 'sellable'],
            ['name' => 'Strawberry Milk Squash (B)', 'category' => 'SQUASH SERIES', 'price' => 12000, 'unit' => 'pcs', 'sku' => 'SQS-SMS-B', 'inventory_type' => 'sellable'],
            ['name' => 'Blackcurrant Milk Squash (K)', 'category' => 'SQUASH SERIES', 'price' => 10000, 'unit' => 'pcs', 'sku' => 'SQS-BMS-K', 'inventory_type' => 'sellable'],
            ['name' => 'Blackcurrant Milk Squash (B)', 'category' => 'SQUASH SERIES', 'price' => 12000, 'unit' => 'pcs', 'sku' => 'SQS-BMS-B', 'inventory_type' => 'sellable'],

            // MINUMAN TAMBAHAN
            ['name' => 'Es Milo (K)', 'category' => 'MINUMAN TAMBAHAN', 'price' => 10000, 'unit' => 'pcs', 'sku' => 'MT-EMI-K', 'inventory_type' => 'sellable'],
            ['name' => 'Es Milo (B)', 'category' => 'MINUMAN TAMBAHAN', 'price' => 12000, 'unit' => 'pcs', 'sku' => 'MT-EMI-B', 'inventory_type' => 'sellable'],
            ['name' => 'Es Markisa (K)', 'category' => 'MINUMAN TAMBAHAN', 'price' => 8000, 'unit' => 'pcs', 'sku' => 'MT-EMR-K', 'inventory_type' => 'sellable'],
            ['name' => 'Es Markisa (B)', 'category' => 'MINUMAN TAMBAHAN', 'price' => 10000, 'unit' => 'pcs', 'sku' => 'MT-EMR-B', 'inventory_type' => 'sellable'],
            ['name' => 'Es Chocolatos', 'category' => 'MINUMAN TAMBAHAN', 'price' => 8000, 'unit' => 'pcs', 'sku' => 'MT-EC-K', 'inventory_type' => 'sellable'],
            ['name' => 'Es Chocolatos (B)', 'category' => 'MINUMAN TAMBAHAN', 'price' => 10000, 'unit' => 'pcs', 'sku' => 'MT-EC-B', 'inventory_type' => 'sellable'],
            ['name' => 'Top Kopi Aren', 'category' => 'MINUMAN TAMBAHAN', 'price' => 8000, 'unit' => 'pcs', 'sku' => 'MT-TKA', 'inventory_type' => 'sellable'],
            ['name' => 'Good Day Freeze Mocafrío Biru', 'category' => 'MINUMAN TAMBAHAN', 'price' => 8000, 'unit' => 'pcs', 'sku' => 'MT-GDFM', 'inventory_type' => 'sellable'],
            ['name' => 'Good Day Moccacino Merah', 'category' => 'MINUMAN TAMBAHAN', 'price' => 8000, 'unit' => 'pcs', 'sku' => 'MT-GDMM', 'inventory_type' => 'sellable'],
            ['name' => 'Beng Beng Drink', 'category' => 'MINUMAN TAMBAHAN', 'price' => 8000, 'unit' => 'pcs', 'sku' => 'MT-BBD-K', 'inventory_type' => 'sellable'],
            ['name' => 'Beng Beng Drink (B)', 'category' => 'MINUMAN TAMBAHAN', 'price' => 10000, 'unit' => 'pcs', 'sku' => 'MT-BBD-B', 'inventory_type' => 'sellable'],
            ['name' => 'Thai Tea (K)', 'category' => 'MINUMAN TAMBAHAN', 'price' => 10000, 'unit' => 'pcs', 'sku' => 'MT-TT-K', 'inventory_type' => 'sellable'],
            ['name' => 'Thai Tea (B)', 'category' => 'MINUMAN TAMBAHAN', 'price' => 12000, 'unit' => 'pcs', 'sku' => 'MT-TT-B', 'inventory_type' => 'sellable'],
            ['name' => 'Goodday Freeze (B)', 'category' => 'MINUMAN TAMBAHAN', 'price' => 10000, 'unit' => 'pcs', 'sku' => 'MT-GDF-B', 'inventory_type' => 'sellable'],

            // CEMILAN
            ['name' => 'Tahu Bulat', 'category' => 'CEMILAN', 'price' => 1000, 'unit' => 'pcs', 'sku' => 'CML-TB', 'inventory_type' => 'sellable'],
            ['name' => 'Sotong', 'category' => 'CEMILAN', 'price' => 1000, 'unit' => 'pcs', 'sku' => 'CML-STG', 'inventory_type' => 'sellable'],
        ];

        foreach ($products as $productData) {
            $variants = $productData['variants'] ?? null;
            $hasVariants = is_array($variants) && count($variants) > 0;
            $row = $productData;
            unset($row['variants']);

            if ($hasVariants) {
                $row['has_variants'] = true;
                $row['is_bundle'] = false;
                $row['allows_decimal'] = $row['allows_decimal'] ?? false;
            } else {
                $row['has_variants'] = false;
            }

            $catName = $row['category'];
            $cat = ProductCategory::query()->firstOrCreate(
                ['store_id' => $store->id, 'name' => $catName],
                ['sort_order' => 0],
            );

            $product = Product::query()->updateOrCreate(
                ['store_id' => $store->id, 'sku' => $row['sku']],
                [
                    'store_id' => $store->id,
                    'product_category_id' => $cat->id,
                    ...$row,
                    'is_active' => true,
                    'track_stock' => true,
                ],
            );

            if ($hasVariants) {
                ProductVariant::query()->where('product_id', $product->id)->delete();
                $sumStock = 0.0;
                foreach ($variants as $i => $vDef) {
                    ProductVariant::query()->create([
                        'store_id' => $store->id,
                        'product_id' => $product->id,
                        'name' => $vDef['name'],
                        'sku' => $vDef['sku'] ?? null,
                        'barcode' => $vDef['barcode'] ?? null,
                        'price' => $vDef['price'] ?? null,
                        'cost_price' => $vDef['cost_price'] ?? null,
                        'stock_quantity' => (string) ($vDef['stock_quantity'] ?? 0),
                        'min_stock_level' => (string) ($vDef['min_stock_level'] ?? 0),
                        'is_active' => true,
                        'sort_order' => (int) ($vDef['sort_order'] ?? $i),
                    ]);
                    $sumStock += (float) ($vDef['stock_quantity'] ?? 0);
                }
                $product->update([
                    'stock_quantity' => $sumStock,
                    'price' => (float) ($variants[0]['price'] ?? $product->price),
                ]);
            }
        }

        $this->command->info('Products seeded successfully!');
    }
}
