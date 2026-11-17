<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * F2 Daftar Belanja: role yang sudah boleh membuka Pembelian otomatis
     * juga boleh membuka Daftar Belanja.
     */
    public function up(): void
    {
        $roles = DB::table('role_menu_permissions')
            ->where('menu_key', 'purchases')
            ->pluck('role')
            ->unique()
            ->all();

        foreach ($roles as $role) {
            DB::table('role_menu_permissions')->updateOrInsert(
                ['role' => $role, 'menu_key' => 'shopping_list'],
                ['created_at' => now(), 'updated_at' => now()],
            );
        }

        \App\Models\RoleMenuPermission::flushMenuCache();
    }

    public function down(): void
    {
        DB::table('role_menu_permissions')
            ->where('menu_key', 'shopping_list')
            ->delete();

        \App\Models\RoleMenuPermission::flushMenuCache();
    }
};
