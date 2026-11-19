<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Owner (pemilik usaha) mendapat menu manajemen yang sebelumnya tidak ada di
 * default sistem: pelanggan & promo, piutang, tim & outlet, distribusi produk,
 * serta pemantauan stok. Menu operasional kasir tetap tidak diberikan.
 *
 * Toko yang menyimpan pengaturan hak akses sendiri ("Tim & Akses") tidak berubah.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $menus = [
        'customers',
        'receivables',
        'discounts',
        'loyalty',
        'upsell',
        'manage_products',
        'manage_staff',
        'manage_stores',
        'stock_opnames',
        'stock_movements',
    ];

    public function up(): void
    {
        foreach ($this->menus as $menuKey) {
            DB::table('role_menu_permissions')->updateOrInsert(
                ['role' => 'owner', 'menu_key' => $menuKey],
                ['created_at' => now(), 'updated_at' => now()],
            );
        }

        \App\Models\RoleMenuPermission::flushMenuCache();
    }

    public function down(): void
    {
        DB::table('role_menu_permissions')
            ->where('role', 'owner')
            ->whereIn('menu_key', $this->menus)
            ->delete();

        \App\Models\RoleMenuPermission::flushMenuCache();
    }
};
