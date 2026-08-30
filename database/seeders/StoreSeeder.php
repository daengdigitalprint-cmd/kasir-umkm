<?php

namespace Database\Seeders;

use App\Models\Store;
use App\Support\StoreDefaultProductCategories;
use Illuminate\Database\Seeder;

class StoreSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $store = Store::query()->updateOrCreate(
            ['slug' => 'toko-sembako-maju-jaya'],
            [
                'name' => 'Toko Sembako Maju Jaya',
                'business_type' => 'retail',
                'description' => 'Toko sembako terlengkap dengan harga terjangkau',
                'address' => 'Jl. Raya No. 123, Jakarta Selatan',
                'phone' => '+62 812 3456 7890',
                'email' => 'info@tokomajujaya.com',
                'currency' => 'IDR',
                'timezone' => 'Asia/Jakarta',
                'settings' => [
                    'tax_rate' => 11,
                    'receipt_footer' => 'Terima kasih telah berbelanja di Toko Maju Jaya!',
                    'low_stock_threshold' => 10,
                ],
                'is_active' => true,
            ],
        );

        StoreDefaultProductCategories::ensureForStore($store);
    }
}
