<?php

use App\Models\RoleMenuPermission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Menu piutang hanya untuk admin dan manager (backofficeOnly)
        foreach (['admin', 'manager'] as $role) {
            RoleMenuPermission::firstOrCreate([
                'role' => $role,
                'menu_key' => 'receivables',
            ]);
        }
    }

    public function down(): void
    {
        RoleMenuPermission::where('menu_key', 'receivables')->delete();
    }
};
