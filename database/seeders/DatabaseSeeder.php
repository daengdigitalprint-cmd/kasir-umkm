<?php

namespace Database\Seeders;

use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Data user (super admin, demo, admin toko) akan hilang jika Anda menjalankan
     * migrate:fresh, migrate:refresh, atau menghapus/mengosongkan tabel users.
     * Buat ulang dengan: php artisan db:seed
     * Hanya super admin: php artisan db:seed --class=SuperAdminSeeder
     * Hanya akun demo (toko demo + transaksi): php artisan db:seed --class=DemoShowcaseSeeder
     */
    public function run(): void
    {
        $this->call([
            SuperAdminSeeder::class,
            SubscriptionPlanSeeder::class,
            StoreSeeder::class,
            ProductCategorySeeder::class,
            ProductSeeder::class,
            DemoShowcaseSeeder::class,
            RoleMenuPermissionSeeder::class,
        ]);

        $store = Store::query()->where('slug', 'toko-sembako-maju-jaya')->first();
        if ($store) {
            User::query()->updateOrCreate(
                ['email' => 'admin@tokomajujaya.com'],
                [
                    'name' => 'Admin Toko',
                    'password' => 'password',
                    'business_type' => 'general',
                    'store_id' => $store->id,
                    'role' => 'admin',
                    'is_active' => true,
                    'is_super_admin' => false,
                    'email_verified_at' => now(),
                ],
            );
        }
    }
}
