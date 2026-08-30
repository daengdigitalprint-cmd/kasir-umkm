<?php

use App\Models\RoleMenuPermission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        RoleMenuPermission::query()->where('role', 'kitchen')->delete();

        foreach (['dashboard', 'kitchen_queue', 'settings', 'attendance'] as $menuKey) {
            RoleMenuPermission::create([
                'role' => 'kitchen',
                'menu_key' => $menuKey,
            ]);
        }
    }

    public function down(): void
    {
        RoleMenuPermission::query()->where('role', 'kitchen')->delete();
    }
};
