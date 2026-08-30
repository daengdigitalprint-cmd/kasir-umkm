<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Tambahkan menu_key 'tables' ke role yang sudah punya akses 'pos' (admin, manager, cashier).
     */
    public function up(): void
    {
        // Ambil role yang sudah punya akses menu 'pos' — berarti role tersebut seharusnya juga bisa akses Manajemen Meja
        $roles = DB::table('role_menu_permissions')
            ->where('menu_key', 'pos')
            ->pluck('role')
            ->unique()
            ->all();

        foreach ($roles as $role) {
            DB::table('role_menu_permissions')->updateOrInsert(
                ['role' => $role, 'menu_key' => 'tables'],
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('role_menu_permissions')
            ->where('menu_key', 'tables')
            ->delete();
    }
};
