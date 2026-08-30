<?php

namespace Database\Seeders;

use App\Models\ProductCategory;
use App\Models\Store;
use Illuminate\Database\Seeder;

class ProductCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $store = Store::query()->where('slug', 'toko-sembako-maju-jaya')->first()
            ?? Store::query()->first();

        if (! $store) {
            $this->command->error('Store not found. Please run StoreSeeder first.');

            return;
        }

        $categories = [
            ['name' => 'Makanan Ringan',    'sort_order' => 1],
            ['name' => 'Makanan Berat',     'sort_order' => 2],
            ['name' => 'Minuman',           'sort_order' => 3],
            ['name' => 'Sembako',           'sort_order' => 4],
            ['name' => 'Bumbu & Rempah',    'sort_order' => 5],
            ['name' => 'Roti & Kue',        'sort_order' => 6],
            ['name' => 'Susu & Olahan Susu', 'sort_order' => 7],
            ['name' => 'Telur & Daging',    'sort_order' => 8],
            ['name' => 'Sayuran & Buah',    'sort_order' => 9],
            ['name' => 'Beku (Frozen)',     'sort_order' => 10],
            ['name' => 'Alat Mandi & Cuci', 'sort_order' => 11],
            ['name' => 'Kosmetik & Skincare', 'sort_order' => 12],
            ['name' => 'Peralatan Rumah',   'sort_order' => 13],
            ['name' => 'Obat & Vitamin',    'sort_order' => 14],
            ['name' => 'Perlengkapan Bayi', 'sort_order' => 15],
            ['name' => 'Aksesoris',         'sort_order' => 16],
            ['name' => 'Alat Tulis (ATK)',  'sort_order' => 17],
            ['name' => 'Elektronik',        'sort_order' => 18],
            ['name' => 'Mainan',            'sort_order' => 19],
            ['name' => 'Lainnya',           'sort_order' => 20],
        ];

        $existing = $store->productCategories()->pluck('name')->map(fn ($n) => strtolower($n))->toArray();

        $count = 0;
        foreach ($categories as $cat) {
            if (! in_array(strtolower($cat['name']), $existing)) {
                ProductCategory::create([
                    'store_id' => $store->id,
                    'name' => $cat['name'],
                    'sort_order' => $cat['sort_order'],
                ]);
                $count++;
            }
        }

        $this->command->info("Seeder: {$count} kategori produk berhasil ditambahkan untuk toko {$store->name}.");
    }
}
