<?php

namespace App\Support;

use App\Models\ProductCategory;
use App\Models\RoleMenuPermission;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class MobileDefaultData
{
    /**
     * Isi data awal (paket, toko, akun owner/kasir, kategori, hak akses menu) untuk instalasi mobile.
     * Aman dijalankan berulang kali.
     */
    public static function ensure(): void
    {
        self::removeSqliteRoleCheckConstraint('users');
        self::removeSqliteRoleCheckConstraint('store_user');

        DB::transaction(function (): void {
            SubscriptionPlan::updateOrCreate(
                ['slug' => 'starter'],
                [
                    'name' => 'Starter',
                    'description' => 'Paket gratis bawaan aplikasi mobile & offline kasir',
                    'price' => 0,
                    'duration_days' => 0,
                    'max_products' => StorePlanLimits::STARTER_MAX_PRODUCTS,
                    'max_customers' => 50,
                    'max_transactions' => null,
                    'max_stores' => 1,
                    'features' => StorePlanLimits::STARTER_FEATURES,
                    'is_active' => true,
                    'is_popular' => false,
                    'sort_order' => 1,
                    'billing_cycle' => 'monthly',
                ]
            );

            $store = Store::query()->first() ?? Store::create([
                'name' => 'Toko Kasir UMKM',
                'slug' => 'toko-kasir-umkm',
                'address' => 'Jl. Usaha Mandiri No. 1',
                'phone' => '081234567890',
                'receipt_footer' => 'Terima kasih atas kunjungan Anda!',
                'is_active' => true,
                'subscription_status' => 'active',
                'subscription_expires_at' => now()->addYears(5),
            ]);

            if (User::query()->count() === 0) {
                $owner = User::create([
                    'name' => 'Owner Toko',
                    'email' => 'owner@kasir.local',
                    'password' => Hash::make('password123'),
                    'role' => 'owner',
                    'store_id' => $store->id,
                ]);

                $cashier = User::create([
                    'name' => 'Kasir Utama',
                    'email' => 'kasir@kasir.local',
                    'password' => Hash::make('kasir123'),
                    'role' => 'cashier',
                    'store_id' => $store->id,
                ]);

                if (Schema::hasTable('store_user')) {
                    DB::table('store_user')->insertOrIgnore([
                        ['store_id' => $store->id, 'user_id' => $owner->id, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()],
                        ['store_id' => $store->id, 'user_id' => $cashier->id, 'role' => 'cashier', 'created_at' => now(), 'updated_at' => now()],
                    ]);
                }
            }

            $categories = ['Makanan & Minuman', 'Sembako & Pokok', 'Camilan & Snack', 'Kebutuhan Rumah', 'Lain-lain'];

            foreach ($categories as $index => $categoryName) {
                ProductCategory::firstOrCreate(
                    ['store_id' => $store->id, 'name' => $categoryName],
                    ['sort_order' => $index + 1]
                );
            }

            $fullMenus = [
                'dashboard', 'pos', 'pos_transactions', 'cash_shifts', 'cash_transactions',
                'products', 'product_categories', 'stock_overview', 'reports', 'settings',
            ];

            $menuDefaults = [
                'admin' => $fullMenus,
                'manager' => $fullMenus,
                'owner' => $fullMenus,
                'cashier' => ['dashboard', 'pos', 'pos_transactions', 'cash_shifts', 'cash_transactions', 'stock_overview', 'settings'],
            ];

            foreach ($menuDefaults as $role => $menus) {
                foreach ($menus as $menuKey) {
                    RoleMenuPermission::firstOrCreate(['role' => $role, 'menu_key' => $menuKey]);
                }
            }
        });
    }

    /**
     * SQLite mengimplementasikan enum sebagai CHECK constraint yang tidak bisa diubah via ALTER TABLE,
     * sehingga role baru (owner, kitchen, barber, dst.) ditolak. Buat ulang tabel tanpa CHECK pada kolom role.
     */
    public static function removeSqliteRoleCheckConstraint(string $table): void
    {
        if (DB::getDriverName() !== 'sqlite' || ! Schema::hasTable($table)) {
            return;
        }

        $createSql = DB::table('sqlite_master')->where('type', 'table')->where('name', $table)->value('sql');
        $pattern = '/\s*check\s*\(\s*"?role"?\s+in\s*\([^)]*\)\s*\)/i';

        if (! is_string($createSql) || ! preg_match($pattern, $createSql)) {
            return;
        }

        $temporaryTable = $table.'_role_fix';
        $newCreateSql = preg_replace(
            '/^CREATE\s+TABLE\s+"?'.preg_quote($table, '/').'"?/i',
            'CREATE TABLE "'.$temporaryTable.'"',
            preg_replace($pattern, '', $createSql)
        );

        $indexSqls = DB::table('sqlite_master')
            ->where('type', 'index')
            ->where('tbl_name', $table)
            ->whereNotNull('sql')
            ->pluck('sql');

        $columns = implode(', ', array_map(
            fn (string $column): string => '"'.str_replace('"', '""', $column).'"',
            array_column(Schema::getColumns($table), 'name')
        ));

        DB::statement('PRAGMA foreign_keys = OFF');
        DB::statement('DROP TABLE IF EXISTS "'.$temporaryTable.'"');
        DB::statement($newCreateSql);
        DB::statement("INSERT INTO \"{$temporaryTable}\" ({$columns}) SELECT {$columns} FROM \"{$table}\"");
        DB::statement('DROP TABLE "'.$table.'"');
        DB::statement('ALTER TABLE "'.$temporaryTable.'" RENAME TO "'.$table.'"');

        foreach ($indexSqls as $indexSql) {
            DB::statement($indexSql);
        }

        DB::statement('PRAGMA foreign_keys = ON');
    }
}
